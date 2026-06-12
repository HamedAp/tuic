<?php

class TuicManager {
    private $apiUrl;
    private $apiSecret;
    private $configPath;

    public function __construct($apiUrl, $apiSecret, $configPath = 'config.toml') {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiSecret = $apiSecret;
        $this->configPath = $configPath;
    }

    /**
     * REST API: Get online users count
     */
    public function getOnline() {
        return $this->callApi('/online', 'GET');
    }

    /**
     * REST API: Get detailed online users (IP addresses)
     */
    public function getDetailedOnline() {
        return $this->callApi('/detailed_online', 'GET');
    }

    /**
     * REST API: Get traffic statistics
     */
    public function getTraffic() {
        return $this->callApi('/traffic', 'GET');
    }

    /**
     * REST API: Kick users by UUIDs
     * @param array $uuids Array of UUID strings
     */
    public function kickUser(array $uuids) {
        return $this->callApi('/kick', 'POST', $uuids);
    }

    /**
     * Config File: Get list of all users from config
     */
    public function getUserList() {
        $config = $this->readConfig();
        return $config['users'] ?? [];
    }

    /**
     * Config File: Add a new user
     */
    public function addUser($uuid, $password) {
        $config = $this->readConfig();
        if (!isset($config['users'])) {
            $config['users'] = [];
        }
        $config['users'][$uuid] = $password;
        return $this->writeConfig($config);
    }

    /**
     * Config File: Remove a user
     */
    public function removeUser($uuid) {
        $config = $this->readConfig();
        if (isset($config['users'][$uuid])) {
            unset($config['users'][$uuid]);
            return $this->writeConfig($config);
        }
        return false;
    }

    /**
     * Generate a TUIC connection link for a user
     */
    public function getTuicLink($uuid, $overrideHostname = null) {
        $config = $this->readConfig();
        $users = $config['users'] ?? [];
        if (!isset($users[$uuid])) {
            return false;
        }

        $password = $users[$uuid];
        $settings = $config['settings'] ?? [];

        $server = $settings['server'] ?? '[::]:8443';
        $port = 8443;
        if (preg_match('/:(\d+)$/', $server, $matches)) {
            $port = $matches[1];
        }

        $hostname = $overrideHostname ?: ($settings['tls.hostname'] ?? 'localhost');
        $cc = $settings['quic.congestion_control.controller'] ?? 'bbr';
        $alpn = $settings['tls.alpn'] ?? 'h3';

        return "tuic://$uuid:$password@$hostname:$port/?congestion_control=$cc&alpn=$alpn&udp_relay_mode=native&allow_insecure=1";
    }

    private function callApi($endpoint, $method = 'GET', $data = null) {
        $ch = curl_init($this->apiUrl . $endpoint);
        $headers = [
            'Authorization: Bearer ' . $this->apiSecret,
            'Content-Type: application/json'
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true) ?: true;
        }

        return false;
    }

    /**
     * Simple TOML parser for users and basic settings
     */
    private function readConfig() {
        if (!file_exists($this->configPath)) {
            return ['users' => [], 'settings' => [], 'raw_lines' => []];
        }

        $content = file_get_contents($this->configPath);
        $lines = explode("\n", $content);
        $config = ['users' => [], 'settings' => [], 'raw_lines' => $lines];

        $currentSection = "";
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed) || $trimmed[0] === '#') {
                continue;
            }

            if ($trimmed[0] === '[' && substr($trimmed, -1) === ']') {
                $currentSection = substr($trimmed, 1, -1);
                continue;
            }

            if (strpos($trimmed, '=') !== false) {
                list($key, $val) = explode('=', $trimmed, 2);
                $key = trim($key, " \t\n\r\0\x0B\"'");
                $val = trim($val);

                // Handle arrays like alpn = ["h3", "spdy/3.1"]
                if ($val !== "" && $val[0] === '[' && substr($val, -1) === ']') {
                    $val = trim($val, '[]');
                    $parts = explode(',', $val);
                    $val = implode(',', array_map(function($v) {
                        return trim($v, " \t\n\r\0\x0B\"'");
                    }, $parts));
                } else {
                    $val = trim($val, " \t\n\r\0\x0B\"'");
                }

                if ($currentSection === 'users') {
                    $config['users'][$key] = $val;
                } else {
                    $fullKey = $currentSection ? "$currentSection.$key" : $key;
                    $config['settings'][$fullKey] = $val;
                }
            }
        }

        return $config;
    }

    /**
     * Simple TOML writer that preserves other sections but updates [users]
     */
    private function writeConfig($config) {
        $newLines = [];
        $usersFound = false;
        $inUsersSection = false;
        $originalLines = $config['raw_lines'] ?? [];

        foreach ($originalLines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '[users]') {
                $usersFound = true;
                $inUsersSection = true;
                $newLines[] = $line;
                // Inject users here
                foreach ($config['users'] as $uuid => $pass) {
                    $newLines[] = "\"$uuid\" = \"$pass\"";
                }
                continue;
            }

            if ($inUsersSection) {
                if (strpos($trimmed, '[') === 0) {
                    $inUsersSection = false;
                    $newLines[] = $line;
                }
                // Skip old user lines
                continue;
            }

            $newLines[] = $line;
        }

        if (!$usersFound) {
            $newLines[] = "";
            $newLines[] = "[users]";
            foreach ($config['users'] as $uuid => $pass) {
                $newLines[] = "\"$uuid\" = \"$pass\"";
            }
        }

        $result = file_put_contents($this->configPath, implode("\n", $newLines)) !== false;
        if ($result) {
            $this->restartService();
        }
        return $result;
    }

    /**
     * Try to restart the tuic service so changes take effect
     */
    private function restartService() {
        // This requires the web user to have sudo permissions for this specific command
        // or for the server to be running in a way that it watches the config file.
        // shell_exec('sudo systemctl restart tuic');
    }
}

// Example usage:
/*
$manager = new TuicManager('http://127.0.0.1:8443', 'YOUR_SECRET_HERE', '/path/to/config.toml');
$online = $manager->getOnline();
print_r($online);

$manager->addUser('550e8400-e29b-41d4-a716-446655440000', 'secure_password');
*/
