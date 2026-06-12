<?php
include("config.php");
date_default_timezone_set("Asia/Tehran");

class TuicManager {
    private $apiUrl;
    private $apiSecret;
    private $configPath;

    public function __construct($apiUrl, $apiSecret, $configPath = '/var/www/config.toml') {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiSecret = $apiSecret;
        $this->configPath = $configPath;
    }

    public function getOnline() {
        return $this->callApi('/online', 'GET');
    }

    public function getDetailedOnline() {
        return $this->callApi('/detailed_online', 'GET');
    }

    public function getTraffic() {
        return $this->callApi('/traffic', 'GET');
    }

    public function kickUser(array $uuids) {
        return $this->callApi('/kick', 'POST', $uuids);
    }

    public function getUserList() {
        $config = $this->readConfig();
        return $config['users'] ?? [];
    }

    public function addUser($uuid, $password) {
        $config = $this->readConfig();
        if (!isset($config['users'])) {
            $config['users'] = [];
        }
        $config['users'][$uuid] = $password;
        return $this->writeConfig($config);
    }

    public function removeUser($uuid) {
        $config = $this->readConfig();
        if (isset($config['users'][$uuid])) {
            unset($config['users'][$uuid]);
            return $this->writeConfig($config);
        }
        return false;
    }

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

        $hostname = $overrideHostname ?: ($settings['tls.hostname'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

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

    private function restartService() {
        @shell_exec('sudo systemctl restart tuic');
    }
}

function getTuicManager() {
    static $manager = null;
    if ($manager === null) {
        $configPath = '/var/www/config.toml';
        $apiUrl = 'http://127.0.0.1:8080';
        $apiSecret = 'YOUR_SECRET_HERE';

        if (file_exists($configPath)) {
            $content = file_get_contents($configPath);
            if (preg_match('/\[restful\][^\[]*addr\s*=\s*"(.*?)"/s', $content, $m)) {
                $apiUrl = 'http://' . $m[1];
            }
            if (preg_match('/\[restful\][^\[]*secret\s*=\s*"(.*?)"/s', $content, $m)) {
                $apiSecret = $m[1];
            }
        }
        $manager = new TuicManager($apiUrl, $apiSecret, $configPath);
    }
    return $manager;
}

function tuiconline() { return getTuicManager()->getOnline(); }
function tuicdetailed_online() { return getTuicManager()->getDetailedOnline(); }
function tuictraffic() { return getTuicManager()->getTraffic(); }
function tuicuserlist() { return getTuicManager()->getUserList(); }
function tuicadduser($uuid, $password) { return getTuicManager()->addUser($uuid, $password); }
function tuicremoveuser($uuid) { return getTuicManager()->removeUser($uuid); }
function tuickickuser($uuids) { return getTuicManager()->kickUser($uuids); }
function tuiclink($uuid, $host = null) { return getTuicManager()->getTuicLink($uuid, $host); }

function tuicport()
{
    $configPath = '/var/www/config.toml';
    if (file_exists($configPath)) {
        $content = file_get_contents($configPath);
        if (preg_match('/server\s*=\s*".*?:(\d+)"/', $content, $m)) {
            return $m[1];
        }
    }
    return "8443";
}
function tuicqr($user)
{
    $manager = getTuicManager();
    $users = $manager->getUserList();
    $uuid = array_search($user, $users);
    if ($uuid) {
        return $manager->getTuicLink($uuid);
    }
    return "";
}
function tuicdeluser($user)
{
    $manager = getTuicManager();
    $users = $manager->getUserList();
    $uuid = array_search($user, $users);
    if ($uuid) {
        return $manager->removeUser($uuid);
    }
    return false;
}
function tuicnewuser($user)
{
    $manager = getTuicManager();
    $users = $manager->getUserList();
    if (in_array($user, $users)) {
        $uuid = array_search($user, $users);
    } else {
        $uuid = trim(shell_exec('uuidgen'));
        if (empty($uuid)) $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
        $manager->addUser($uuid, $user);
    }
    return $manager->getTuicLink($uuid) . "#" . $user;
}

function getPublicIP()
{
    $services = [
        'https://api.ipify.org',
        'https://ifconfig.me/ip',
        'https://icanhazip.com',
        'https://ipinfo.io/ip'
    ];
    foreach ($services as $service) {
        $ip = @file_get_contents($service);
        if ($ip !== false && filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
            return trim($ip);
        }
    }
    if (function_exists('curl_init')) {
        foreach ($services as $service) {
            $ch = curl_init($service);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $ip = curl_exec($ch);
            $close = curl_close($ch);
            if ($ip !== false && filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
                return trim($ip);
            }
        }
    }
    return false;
}
function checkSSLCertificate($domain)
{
    if (!preg_match("~^https?://~i", $domain)) {
        $domain = "https://" . $domain;
    }
    $url = parse_url($domain);
    $host = $url['host'];
    $port = isset($url['port']) ? $url['port'] : 443;
    $context = stream_context_create([
        'ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $client = @stream_socket_client(
        "ssl://{$host}:{$port}",
        $errno,
        $errstr,
        30,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if (!$client) {
        return "Error connecting to $host: $errstr ($errno)";
    }
    $params = stream_context_get_params($client);
    $cert = $params['options']['ssl']['peer_certificate'];
    if (!$cert) {
        fclose($client);
        return "No SSL certificate found for $host";
    }
    $certInfo = openssl_x509_parse($cert);
    openssl_x509_free($cert);
    fclose($client);
    if (!$certInfo) {
        return "Failed to parse SSL certificate for $host";
    }
    $validTo = $certInfo['validTo_time_t'];
    $expirationDate = date('Y-m-d H:i:s', $validTo);
    $daysUntilExpiry = floor(($validTo - time()) / (60 * 60 * 24));
    $result = [
        'domain' => $host,
        'expiration_date' => $expirationDate,
        'days_until_expiry' => $daysUntilExpiry,
        'is_expired' => $daysUntilExpiry < 0,
    ];
    return $result;
}
function napsterlink($us, $pass, $hos, $Por, $udppor)
{
    $us = addslashes($us);
    $pass = addslashes($pass);
    $hos = addslashes($hos);
    $Por = (int) $Por;
    $udppor = (int) $udppor;
    $bencodeArray = [
        "sshConfigType" => "SSH-Direct",
        "sni" => "",
        "tlsVersion" => "DEFAULT",
        "httpProxy" => "",
        "authenticateProxy" => false,
        "proxyUsername" => "",
        "proxyPassword" => "",
        "payload" => "",
        "dnsTTMode" => "UDP",
        "dnsServer" => "",
        "nameserver" => "",
        "publicKey" => "",
        "udpgwPort" => $udppor,
        "remarks" => $us,
        "sshHost" => $hos,
        "sshPort" => $Por,
        "sshUsername" => $us,
        "sshPassword" => $pass,
        "udpgwTransparentDNS" => true
    ];
    $bencode = json_encode($bencodeArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return "Error: Failed to encode JSON - " . json_last_error_msg();
    }
    $lin = "npvt-ssh://";
    return $lin . base64_encode($bencode);
}
function addsignboxtouser($usernamesignbox, $link)
{
    include("/var/www/html/p/config.php");
    $strSQL = "SELECT * FROM users where username='" . $usernamesignbox . "'";
    $rs = mysqli_query($conn, $strSQL);
    while ($row = mysqli_fetch_array($rs)) {
        $oldsignbox = $row['signbox'];
    }
    if (str_contains($oldsignbox, $link)) {
        $oldsignbox = str_replace($link, "", $oldsignbox);
    }
    $newsignbox = $oldsignbox . "\n" . $link;
    $newsignbox = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $newsignbox);
    $adduser = "update users set  signbox=? where username=?";
    $stmt = $conn->prepare($adduser);
    $stmt->bind_param("ss", $newsignbox, $usernamesignbox);
    $stmt->execute();
    $stmt->close();
}
function updatesignbox($address)
{
    include("config.php");
    if (file_exists('/var/www/signbox.json')) {
        $out = file_get_contents('http://' . $address . ":" . $panelport . "/p/updatesignbox.php");
    }
}
function country2flag(string $countryCode): string
{
    return (string) preg_replace_callback(
        '/./',
        static fn(array $letter) => mb_chr(ord($letter[0]) % 32 + 0x1F1E5),
        $countryCode
    );
}
function progports()
{
    $output = array();
    $exp = shell_exec('sudo netstat -atnp | grep LISTEN | grep -v tcp6');
    $exparr = explode("\n", $exp);
    foreach ($exparr as $line) {
        if (!empty($line)) {
            $line = trim(preg_replace('/\s\s+/', ' ', $line));
            $parts = explode(' ', $line);
            $po = explode(":", $parts[3]);
            $port = $po[1];
            $prog = preg_replace('/\PL/u', '', $parts[6]);
            $output[$port] = $prog;
        }
    }
    return $output;
}
function openports()
{
    $output = array();
    $exp = shell_exec('sudo netstat -atnp | grep LISTEN | grep -v tcp6');
    $exparr = explode("\n", $exp);
    foreach ($exparr as $line) {
        if (!empty($line)) {
            $line = trim(preg_replace('/\s\s+/', ' ', $line));
            $parts = explode(' ', $line);
            $po = explode(":", $parts[3]);
            $port = $po[1];
            $output[] = $port;
        }
    }
    return $output;
}
function checkmsg($msg)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://shahanpanel.com/newmsg103.txt");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $starstatus = curl_exec($ch);
    curl_close($ch);
    if ($starstatus == "yes") {
        file_put_contents('/var/www/html/p/log/cisco.txt', "startcisco");
        return "yes";
    } else {
        file_put_contents('/var/www/html/p/log/cisco.txt', "stopcisco");
        return "no";
    }
}
function checkstar($serverrip)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://shahanpanel.com/star.php?serverip=" . $serverrip);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $starstatus = curl_exec($ch);
    curl_close($ch);
    if ($starstatus == "Licensed") {
        file_put_contents('/var/www/html/p/log/dropbear.txt', "startdrop");
        return true;
    } else {
        file_put_contents('/var/www/html/p/log/dropbear.txt', "stopdrop");
        return false;
    }
}
function checklis($serverrip)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://shahanpanel.com/list.php?serverip=" . $serverrip);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_HEADER, false);
    $starstatus = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http_status === 200 && $starstatus !== false) {
        if (trim($starstatus) === "Licensed") {
            file_put_contents('/var/www/html/p/log/tuiclog.txt', "starttuic");
            return "Licensed";
        } else {
            file_put_contents('/var/www/html/p/log/tuiclog.txt', "stoptuic");
            return "false";
        }
    }
    return "false";
}
function isSiteAvailible($url)
{
    $url = "https://shahanpanel.com/newmsg103.txt";
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $curlInit = curl_init($url);
    curl_setopt($curlInit, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curlInit, CURLOPT_HEADER, true);
    curl_setopt($curlInit, CURLOPT_NOBODY, true);
    curl_setopt($curlInit, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($curlInit);
    curl_close($curlInit);
    return $response ? true : false;
}
function deleteport($user)
{
    include("config.php");
    $strSQL = "SELECT * FROM users where username=?";
    $stmt = $conn->prepare($strSQL);
    $stmt->bind_param("s", $user);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $out = shell_exec('bash /var/www/html/p/plugins/dd.sh ' . escapeshellarg($row['userport']));
    }
    $stmt->close();
    unlink("/var/www/config/sshd_config_" . $user);
}
function createport($user)
{
    include("config.php");
    $portlist = [];
    $strSQL = "SELECT * FROM users ";
    $stmt = $conn->prepare($strSQL);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['userport'])) {
            $portlist[] = $row['userport'];
        }
    }
    $stmt->close();
    if (!$portlist) {
        $userport = "41000";
    } else {
        $userport = max($portlist) + 1;
    }
    $out = shell_exec('bash /var/www/html/p/plugins/ss.sh ' . escapeshellarg($user) . ' ' . escapeshellarg($userport));
    return $userport;
}
function tamdiduser($user, $days, $isamnezia)
{
    if (!empty($user) && !empty($days)) {
        include("config.php");
        $strSQL = "SELECT * FROM users where username=?";
        $stmt = $conn->prepare($strSQL);
        $stmt->bind_param("s", $user);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $ex = $row['finishdate'];
            $password = $row['password'];
        }
        $stmt->close();
        $out = shell_exec('bash /var/www/html/p/adduser ' . escapeshellarg($user) . ' ' . escapeshellarg($password));
        tuicnewuser($user);
        enablewguser($user, $isamnezia);
        addnewwguser($user, $isamnezia);
        shadowadduser($user);
        updatesignbox($_SERVER['SERVER_NAME']);
        $expiredate = strtotime(date('Y-m-d', strtotime($ex)));
        if ($expiredate < strtotime(date('Y-m-d'))) {
            $tamdiddate = date('Y-m-d');
        } else {
            $tamdiddate = $ex;
        }
        $expdate = date('Y-m-d', strtotime($tamdiddate . ' + ' . $days . ' days'));
        $adduser = "update users set  enable='true' , finishdate=? where username=?";
        $stmt = $conn->prepare($adduser);
        $stmt->bind_param("ss", $expdate, $user);
        $stmt->execute();
        $stmt->close();
        $adduser = "update Traffic set  download='0',upload='0',total='0' where user=?";
        $stmtt = $conn->prepare($adduser);
        $stmtt->bind_param("s", $user);
        $stmtt->execute();
        $stmtt->close();
        $sql = "delete from apptraffic where user=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $user);
        $stmt->execute();
        $stmt->close();
    }
}
function createtestuser($days, $traffic, $multiuser, $tgid, $isamnezia)
{
    include("config.php");
    $user = "test" . date("mdHis");
    $password = randomPassword(8, "1234567890");
    $adduser = "INSERT INTO `users` (
                        `username`,
                        `password`,
                        `multiuser` ,
                        `finishdate`,
                        `traffic`,
                        `referral`,
                        `days`,
                        `telegramid`,
                        `enable`) VALUES (
                        '" . $user . "',
                        '" . $password . "',
                        ?,
                        '2100-01-01',
                        ?,
                        '',
                        ?,
                        ?,
                        'true');";
    $stmt = $conn->prepare($adduser);
    $stmt->bind_param("ssss", $multiuser, $traffic, $days, $tgid);
    $stmt->execute();
    $stmt->close();
    $out = shell_exec('bash /var/www/html/p/adduser ' . escapeshellarg($user) . ' ' . escapeshellarg($password));
    tuicnewuser($user);
    enablewguser($user, $isamnezia);
    addnewwguser($user, $isamnezia);
}
function ismultiserver()
{
    $multiip = [];
    include("config.php");
    $sql = "SELECT * FROM ApiToken where Description != 'tgbot';";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['Allowips'])) {
            $multiip[] = $row['Allowips'];
        }
    }
    $stmt->close();
    return (count($multiip) !== 0);
}
function shadowporttouser($ppp)
{
    if (file_exists("/var/www/shadowsocks.json")) {
        $config = file_get_contents("/var/www/shadowsocks.json");
        $decoded = json_decode($config, true);
        $server = $decoded['port_password'];
        foreach ($server as $key => $value) {
            if ($value !== "shahan" && $key == $ppp) {
                return $value;
            }
        }
    }
}
function shadowport($username)
{
    if (file_exists("/var/www/shadowsocks.json")) {
        $config = file_get_contents("/var/www/shadowsocks.json");
        $decoded = json_decode($config, true);
        $server = $decoded['port_password'];
        $port = "";
        foreach ($server as $key => $value) {
            if ($value == $username) {
                $port = $key;
            }
        }
        return empty($port) ? "NOT" : $port;
    }
}
function shadowlink($user, $port, $address)
{
    if (file_exists("/var/www/shadowsocks.json")) {
        $prefix = "ss://";
        $encoded = base64_encode("chacha20-ietf-poly1305:" . $user);
        return $prefix . $encoded . "@" . $address . ":" . $port . "#" . $user;
    }
}
function shadowlist()
{
    if (file_exists("/var/www/shadowsocks.json")) {
        $config = file_get_contents("/var/www/shadowsocks.json");
        $decoded = json_decode($config, true);
        $server = $decoded['port_password'];
        $uslist = [];
        foreach ($server as $key => $value) {
            $uslist[] = $value;
        }
        return ($uslist);
    }
}
function shadowdeluser($username)
{
    if (file_exists("/var/www/shadowsocks.json")) {
        $config = '
"server":"0.0.0.0",
"server_ipv6": "[::]",
"local_address":"127.0.0.1",
"local_port":1080,
"timeout":300,
"method":"chacha20-ietf-poly1305",
"fast_open":false
}';
        $file = file_get_contents("/var/www/shadowsocks.json");
        $decoded = json_decode($file, true);
        $server = $decoded['port_password'];
        $userlist = [];
        foreach ($server as $key => $value) {
            $userlist[] = $value;
        }
        if (in_array($username, $userlist)) {
            foreach ($server as $key => $value) {
                if ($value == $username) {
                    unset($server[$key]);
                    $newport = $key;
                }
            }
            $m = 0;
            $num_of_items = count($server);
            $userlist_str = "";
            foreach ($server as $key => $value) {
                $m++;
                if ($m !== $num_of_items) {
                    $userlist_str .= '"' . $key . '": "' . $value . '",
        ';
                } else {
                    $userlist_str .= '"' . $key . '": "' . $value . '"';
                }
            }
            $out = '{
            "port_password" :{
            ' . $userlist_str . '
            },
            ' . $config;
            $data = json_decode($out, true);
            $out = json_encode($data, JSON_PRETTY_PRINT);
            file_put_contents('/var/www/shadowsocks.json', $out);
            shell_exec("sudo /etc/init.d/shadowsocks restart");
            shell_exec("sudo iptables -D INPUT -p tcp --dport " . $newport);
        }
    }
}
function shadowadduser($user)
{
    include("config.php");
    if (file_exists("/var/www/shadowsocks.json")) {
        $config = '
    "server":"0.0.0.0",
    "server_ipv6": "[::]",
    "local_address":"127.0.0.1",
    "local_port":1080,
    "timeout":300,
    "method":"chacha20-ietf-poly1305",
    "fast_open":false
    }';
        $file = file_get_contents("/var/www/shadowsocks.json");
        $decoded = json_decode($file, true);
        $server = $decoded['port_password'];
        $userlist = [];
        foreach ($server as $key => $value) {
            $userlist[$key] = $value;
        }
        $newport = "";
        $sql = "SELECT * FROM users where username=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $user);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $shadowport = $row['shadowport'];
        }
        if (empty($shadowport)) {
            $openports = openports();
            $randomnumber = rand(10000, 30000);
            if (in_array($randomnumber, $openports)) {
                $newport = rand(10000, 30000);
            } else {
                $newport = $randomnumber;
            }
            $sql = "UPDATE users SET shadowport=? WHERE username=? ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $newport, $user);
            $stmt->execute();
            $stmt->close();
        } else {
            $newport = $shadowport;
        }
        $userlist[$newport] = $user;
        $m = 0;
        $num_of_items = count($userlist);
        $userlist_str = "";
        foreach ($userlist as $key => $value) {
            $m++;
            if ($m !== $num_of_items) {
                $userlist_str .= '"' . $key . '": "' . $value . '",';
            } else {
                $userlist_str .= '"' . $key . '": "' . $value . '"';
            }
        }
        $out = '{
            "port_password" :{
            ' . $userlist_str . '
            },
            ' . $config;
        $data = json_decode($out, true);
        $out = json_encode($data, JSON_PRETTY_PRINT);
        file_put_contents('/var/www/shadowsocks.json', $out);
        shell_exec("sudo /etc/init.d/shadowsocks restart");
        shell_exec("sudo iptables -D INPUT -p tcp --dport " . $newport);
        shell_exec("sudo iptables -D INPUT -p tcp --dport " . $newport);
        shell_exec("sudo iptables -D INPUT -p tcp --dport " . $newport);
        shell_exec("sudo iptables -A INPUT -p tcp --dport " . $newport);
    }
}
///////////////////////////// Wireguard //////////////////////////////////
function isPortOpen($port, $host = '127.0.0.1')
{
    $connection = @fsockopen($host, $port, $errno, $errstr, 0.1);
    if (is_resource($connection)) {
        fclose($connection);
        return true;
    }
    return false;
}
function getRandomUnusedPort()
{
    $minPort = 10000;
    $maxPort = 60000;
    $maxAttempts = 100;
    $attempt = 0;
    do {
        $port = mt_rand($minPort, $maxPort);
        $attempt++;
        if ($attempt > $maxAttempts) {
            die("Error: Could not find an unused port after $maxAttempts attempts.\n");
        }
    } while (isPortOpen($port));
    return $port;
}
function runCommand($command)
{
    $output = shell_exec("sudo " . $command);
    if ($output === null) {
        die("Error: Failed to execute command: $command\n");
    }
    return trim($output);
}
function generateNewIp($existingIps)
{
    $baseIp = '10.0.0.';
    $index = 2;
    while (in_array($baseIp . $index . '/32', $existingIps)) {
        $index++;
        if ($index > 255) {
            die("Error: No available IP addresses in range 10.0.0.2-255.\n");
        }
    }
    return $baseIp . $index . '/32';
}
function addnewwguser($username, $isamnezia)
{
    $jsonFile = '/var/www/shahanguard/shahanguard.json';
    if (!file_exists($jsonFile)) {
        return;
    }
    $jsonData = file_get_contents($jsonFile);
    if ($jsonData === false) {
        return;
    }
    $config = json_decode($jsonData, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return;
    }
    foreach ($config['inbounds'] as $index => $inbound) {
        if (isset($inbound['tag']) && $inbound['tag'] == $username) {
            return;
        }
    }
    $existingIps = [];
    foreach ($config['inbounds'] as $inbound) {
        if (isset($inbound['settings']['peers'][0]['allowedIPs'])) {
            $existingIps = array_merge($existingIps, $inbound['settings']['peers'][0]['allowedIPs']);
        }
    }
    $wgOutput = runCommand('shahanguard wg');
    $wgKeys = array_filter(explode("\n", $wgOutput));
    $privateKey = '';
    $publicKey = '';
    foreach ($wgKeys as $line) {
        if (strpos($line, 'PrivateKey:') === 0) {
            $privateKey = trim(str_replace('PrivateKey:', '', $line));
        } elseif (strpos($line, 'Password (PublicKey):') === 0) {
            $publicKey = trim(str_replace('Password (PublicKey):', '', $line));
        }
    }
    if (empty($privateKey) || empty($publicKey)) {
        die("Error: Could not retrieve privateKey or publicKey from 'shahanguard wg' command\n");
    }
    $serverkey = shell_exec('shahanguard wg');
    $serverwgKeys = array_filter(explode("\n", $serverkey));
    $secretKey = '';
    $serverpublicKey = '';
    foreach ($serverwgKeys as $line) {
        if (strpos($line, 'PrivateKey:') === 0) {
            $secretKey = trim(str_replace('PrivateKey:', '', $line));
        } elseif (strpos($line, 'Password (PublicKey):') === 0) {
            $serverpublicKey = trim(str_replace('Password (PublicKey):', '', $line));
        }
    }
    if (empty($secretKey)) {
        die("Error: Could not generate secretKey using 'openssl rand -base64 32'\n");
    }
    $newPort = getRandomUnusedPort();
    $newIp = "10.0.0.2/32";
    $newInbound = [
        'allocate' => [
            'concurrency' => 3,
            'refresh' => 5,
            'strategy' => 'always'
        ],
        'listen' => null,
        'port' => $newPort,
        'protocol' => 'wireguard',
        'settings' => [
            'mtu' => 1420,
            'noKernelTun' => false,
            'peers' => [
                [
                    'allowedIPs' => [$newIp],
                    'keepAlive' => 0,
                    'privateKey' => $privateKey,
                    'publicKey' => $publicKey
                ]
            ],
            'secretKey' => $secretKey
        ],
        'sniffing' => [
            'destOverride' => ['http', 'tls', 'quic', 'fakedns'],
            'enabled' => true,
            'metadataOnly' => false,
            'routeOnly' => false
        ],
        'streamSettings' => null,
        'tag' => (string) $username
    ];
    $config['inbounds'][] = $newInbound;
    $policy = [
        "levels" => (object) [
            "0" => [
                "statsUserDownlink" => true,
                "statsUserUplink" => true
            ]
        ],
        "system" => [
            "statsInboundDownlink" => true,
            "statsInboundUplink" => true,
            "statsOutboundDownlink" => false,
            "statsOutboundUplink" => false
        ]
    ];
    unset($config['policy']);
    $config['policy'] = $policy;
    $jsonOutput = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($jsonOutput === false) {
        die("Error: Failed to encode JSON\n");
    }
    $jsonOutput = str_replace('"stats": []', '"stats": {}', $jsonOutput);
    $jsonOutput = str_replace('"settings": []', '"settings": {}', $jsonOutput);
    if (file_put_contents($jsonFile, $jsonOutput) === false) {
        die("Error: Unable to write to JSON file\n");
    }
    $amneziaconfig = "";
    if ($isamnezia) {
        $amneziaconfig = "Jc = 4
Jmin = 40
Jmax = 70
H1 = 1
H2 = 2
H3 = 3
H4 = 4";
    }
    $txtconfig = "[Interface]
Address = $newIp
DNS = 1.1.1.1, 1.0.0.1
MTU = 1420
$amneziaconfig
PrivateKey = $privateKey
[Peer]
AllowedIPs = 0.0.0.0/0, ::/0
Endpoint = " . $_SERVER['SERVER_NAME'] . ":$newPort
PublicKey = $serverpublicKey";
    file_put_contents("/var/www/shahanguard/configs/" . $username . ".txt", $txtconfig);
    shell_exec("sudo systemctl restart shahanguard");
}
function removewguser($delusername)
{
    $filePath = "/var/www/shahanguard/shahanguard.json";
    if (!file_exists($filePath)) {
        return;
    }
    $jsonData = file_get_contents($filePath);
    $config = json_decode($jsonData, true);
    if ($config === null) {
        return;
    }
    foreach ($config['inbounds'] as $index => $inbound) {
        if (isset($inbound['tag']) && $inbound['tag'] == $delusername) {
            unset($config['inbounds'][$index]);
        }
    }
    $config['inbounds'] = array_values($config['inbounds']);
    $policy = [
        "levels" => (object) [
            "0" => [
                "statsUserDownlink" => true,
                "statsUserUplink" => true
            ]
        ],
        "system" => [
            "statsInboundDownlink" => true,
            "statsInboundUplink" => true,
            "statsOutboundDownlink" => false,
            "statsOutboundUplink" => false
        ]
    ];
    unset($config['policy']);
    $config['policy'] = $policy;
    $newJsonData = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $newJsonData = str_replace('"stats": []', '"stats": {}', $newJsonData);
    $newJsonData = str_replace('"settings": []', '"settings": {}', $newJsonData);
    file_put_contents($filePath, $newJsonData);
    unlink("/var/www/shahanguard/configs/" . $delusername . ".txt");
    shell_exec("sudo systemctl restart shahanguard");
    include("config.php");
    $sql = "delete from signboxonline where username=?;";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $delusername);
    $stmt->execute();
    $stmt->close();
}
function disablewguser($disableusername)
{
    $filePath = "/var/www/shahanguard/shahanguard.json";
    if (!file_exists($filePath)) {
        return;
    }
    $jsonData = file_get_contents($filePath);
    $config = json_decode($jsonData, true);
    if ($config === null) {
        return;
    }
    foreach ($config['inbounds'] as $index => $inbound) {
        if (isset($inbound['tag']) && $inbound['tag'] == $disableusername) {
            $config['inbounds'][$index]['listen'] = "127.0.0.1";
        }
    }
    $config['inbounds'] = array_values($config['inbounds']);
    $policy = [
        "levels" => (object) [
            "0" => [
                "statsUserDownlink" => true,
                "statsUserUplink" => true
            ]
        ],
        "system" => [
            "statsInboundDownlink" => true,
            "statsInboundUplink" => true,
            "statsOutboundDownlink" => false,
            "statsOutboundUplink" => false
        ]
    ];
    unset($config['policy']);
    $config['policy'] = $policy;
    $newJsonData = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $newJsonData = str_replace('"stats": []', '"stats": {}', $newJsonData);
    $newJsonData = str_replace('"settings": []', '"settings": {}', $newJsonData);
    file_put_contents($filePath, $newJsonData);
    shell_exec("sudo systemctl restart shahanguard");
    include("config.php");
    $sql = "delete from signboxonline where username=?;";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $disableusername);
    $stmt->execute();
    $stmt->close();
}
function enablewguser($enableusername, $isamnezia)
{
    if (!file_exists("/var/www/shahanguard/configs/" . $enableusername . ".txt")) {
        createwgconfigfile($enableusername, $isamnezia);
        addnewwguser($enableusername, $isamnezia);
    }
    $filePath = "/var/www/shahanguard/shahanguard.json";
    if (!file_exists($filePath)) {
        return;
    }
    $jsonData = file_get_contents($filePath);
    $config = json_decode($jsonData, true);
    if ($config === null) {
        return;
    }
    foreach ($config['inbounds'] as $index => $inbound) {
        if (isset($inbound['tag']) && $inbound['tag'] == $enableusername) {
            $config['inbounds'][$index]['listen'] = null;
        }
    }
    $config['inbounds'] = array_values($config['inbounds']);
    $policy = [
        "levels" => (object) [
            "0" => [
                "statsUserDownlink" => true,
                "statsUserUplink" => true
            ]
        ],
        "system" => [
            "statsInboundDownlink" => true,
            "statsInboundUplink" => true,
            "statsOutboundDownlink" => false,
            "statsOutboundUplink" => false
        ]
    ];
    unset($config['policy']);
    $config['policy'] = $policy;
    $newJsonData = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $newJsonData = str_replace('"stats": []', '"stats": {}', $newJsonData);
    $newJsonData = str_replace('"settings": []', '"settings": {}', $newJsonData);
    file_put_contents($filePath, $newJsonData);
    shell_exec("sudo systemctl restart shahanguard");
}
function wguserqr($user)
{
    $config = "";
    $file = "/var/www/shahanguard/configs/" . $user . ".txt";
    if (file_exists($file)) {
        $config = (string) file_get_contents($file);
        return $config;
    } else {
        return "";
    }
}
function getwguserlist()
{
    $jsonFile = '/var/www/shahanguard/shahanguard.json';
    if (!file_exists($jsonFile)) {
        return;
    }
    $tags = [];
    $configg = file_get_contents($jsonFile);
    $config = json_decode($configg, true);
    if (isset($config['inbounds']) && is_array($config['inbounds'])) {
        foreach ($config['inbounds'] as $inbound) {
            if (isset($inbound['tag'])) {
                $tags[] = $inbound['tag'];
            }
        }
    }
    return $tags;
}
function wggetuserbyport($port)
{
    $jsonFile = '/var/www/shahanguard/shahanguard.json';
    if (!file_exists($jsonFile)) {
        return;
    }
    $jsonData = file_get_contents($jsonFile);
    $config = json_decode($jsonData, true);
    foreach ($config['inbounds'] as $inbound) {
        if (isset($inbound['port']) && $inbound['port'] == $port) {
            return $inbound['tag'] ?? null;
        }
    }
    return null;
}
function findTag($array, $searchTag, &$results)
{
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            if (isset($value['tag']) && $value['tag'] === $searchTag) {
                $result = [
                    'tag' => $value['tag'],
                    'allowedIPs' => isset($value['settings']['peers'][0]['allowedIPs'])
                        ? $value['settings']['peers'][0]['allowedIPs']
                        : null,
                    'privateKey' => isset($value['settings']['peers'][0]['privateKey'])
                        ? $value['settings']['peers'][0]['privateKey']
                        : null,
                    'publicKey' => isset($value['settings']['peers'][0]['publicKey'])
                        ? $value['settings']['peers'][0]['publicKey']
                        : null,
                    'secretKey' => isset($value['settings']['secretKey'])
                        ? $value['settings']['secretKey']
                        : null,
                    'port' => isset($value['port'])
                        ? $value['port']
                        : null
                ];
                $results[] = $result;
            }
            findTag($value, $searchTag, $results);
        }
    }
}
function createwgconfigfile($searchTag, $isamnezia)
{
    $filePath = "/var/www/shahanguard/shahanguard.json";
    if (!file_exists($filePath)) {
        return;
    }
    $jsonContent = file_get_contents($filePath);
    $data = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return;
    }
    $results = [];
    findTag($data, $searchTag, $results);
    if (!empty($results)) {
        foreach ($results as $index => $result) {
            $address = $result['allowedIPs'][0];
            $privateKey = $result['privateKey'];
            $newPort = $result['port'];
            $secretkey = $result['secretKey'];
            $serveraddress = $_SERVER['SERVER_NAME'];
            $serverkey = shell_exec('shahanguard wg -i "' . $secretkey . '"');
            $serverwgKeys = array_filter(explode("\n", $serverkey));
            $serverpublicKey = "";
            foreach ($serverwgKeys as $line) {
                if (strpos($line, 'Password (PublicKey):') === 0) {
                    $serverpublicKey = trim(str_replace('Password (PublicKey):', '', $line));
                }
            }
            $amneziaconfig = "";
            if ($isamnezia) {
                $amneziaconfig = "Jc = 4
Jmin = 40
Jmax = 70
H1 = 1
H2 = 2
H3 = 3
H4 = 4";
            }
            $txtconfig = "[Interface]
Address = $address
DNS = 1.1.1.1, 1.0.0.1
MTU = 1420
$amneziaconfig
PrivateKey = $privateKey
[Peer]
AllowedIPs = 0.0.0.0/0, ::/0
Endpoint = $serveraddress:$newPort
PublicKey = $serverpublicKey";
            file_put_contents("/var/www/shahanguard/configs/" . $searchTag . ".txt", $txtconfig);
            return $txtconfig;
        }
    }
}
function update_config($filepath, $variable, $value)
{
    if (file_exists($filepath)) {
        $content = file_get_contents($filepath);
        $pattern = '/(\$' . preg_quote($variable) . '\s*=\s*[\'"])(.*?)([\'"];)/';
        if (preg_match($pattern, $content)) {
            $replacement = '${1}' . $value . '${3}';
            $new_content = preg_replace($pattern, $replacement, $content);
            file_put_contents($filepath, $new_content);
            return true;
        }
    }
    return false;
}
?>
