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
     * Simple TOML parser for the users section
     */
    private function readConfig() {
        if (!file_exists($this->configPath)) {
            return [];
        }

        $content = file_get_contents($this->configPath);
        $lines = explode("\n", $content);
        $config = ['users' => [], 'raw_lines' => $lines];

        $inUsersSection = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '[users]') {
                $inUsersSection = true;
                continue;
            }
            if ($inUsersSection && strpos($trimmed, '[') === 0) {
                $inUsersSection = false;
                continue;
            }

            if ($inUsersSection && !empty($trimmed) && strpos($trimmed, '=') !== false) {
                list($uuid, $pass) = explode('=', $trimmed, 2);
                $uuid = trim($uuid, " \t\n\r\0\x0B\"'");
                $pass = trim($pass, " \t\n\r\0\x0B\"'");
                $config['users'][$uuid] = $pass;
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

        return file_put_contents($this->configPath, implode("\n", $newLines)) !== false;
    }
}

// Example usage:
/*
$manager = new TuicManager('http://127.0.0.1:8443', 'YOUR_SECRET_HERE', '/path/to/config.toml');
$online = $manager->getOnline();
print_r($online);

$manager->addUser('550e8400-e29b-41d4-a716-446655440000', 'secure_password');
*/
