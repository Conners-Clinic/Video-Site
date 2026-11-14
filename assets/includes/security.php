<?php
// Shared request-security primitives. No application or database initialization.
function PT_SecurityToken($key) {
    if (!isset($_SESSION[$key]) || !is_string($_SESSION[$key]) ||
        !preg_match('/\A[a-f0-9]{64}\z/D', $_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }
    return $_SESSION[$key];
}
function PT_SecurityTokenMatches($key, $token) {
    return is_string($token) && isset($_SESSION[$key]) &&
        is_string($_SESSION[$key]) && strlen($_SESSION[$key]) === 64 &&
        strlen($token) === 64 && hash_equals($_SESSION[$key], $token);
}
function PT_AdminCsrfValid() {
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    return PT_SecurityTokenMatches('hash_id', $header) ||
        PT_SecurityTokenMatches('main_hash_id', $header) ||
        PT_SecurityTokenMatches('hash_id', $_POST['hash_id'] ?? null) ||
        PT_SecurityTokenMatches('main_hash_id', $_POST['hash'] ?? ($_GET['hash'] ?? null));
}
function PT_RequireAdminCsrf() {
    if (!PT_AdminCsrfValid()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('status' => 403, 'message' => 'Invalid request token; reload the page'));
        exit();
    }
}
function PT_VerifyPassword($password, $storedHash) {
    if (!is_string($password) || !is_string($storedHash) || $password === '' || $storedHash === '') {
        return false;
    }
    if (preg_match('/\A[a-f0-9]{40}\z/Di', $storedHash)) {
        return hash_equals(strtolower($storedHash), sha1($password));
    }
    return password_verify($password, $storedHash);
}

function PT_SecurityIdList($values, $allowComma = false) {
    if ($allowComma && is_string($values)) {
        $values = explode(',', $values);
    }
    if (!is_array($values) || count($values) > 1000) {
        return false;
    }
    $ids = array();
    foreach ($values as $value) {
        if ((!is_string($value) && !is_int($value)) ||
            !preg_match('/\A[0-9]{1,18}\z/D', (string) $value)) {
            return false;
        }
        $ids[] = (int) $value;
    }
    return array_values(array_unique($ids));
}
function PT_SecurityBadInput() {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('status' => 400, 'message' => 'Invalid numeric query parameters'));
    exit();
}

function PT_SafeAuthRedirect($candidate, $baseUrl) {
    $fallback = rtrim($baseUrl, '/') . '/';
    if (!is_string($candidate) || $candidate === '' ||
        preg_match('/[\x00-\x20\x7f\\\\]/', $candidate)) {
        return $fallback;
    }
    if ($candidate[0] === '/' && substr($candidate, 0, 2) !== '//') {
        return rtrim($baseUrl, '/') . $candidate;
    }
    $base = parse_url($baseUrl);
    $target = parse_url($candidate);
    if (!$base || !$target || isset($target['user']) || isset($target['pass']) ||
        empty($target['host']) || empty($target['scheme']) ||
        strtolower($target['scheme']) !== strtolower($base['scheme'] ?? 'https') ||
        strtolower($target['host']) !== strtolower($base['host'] ?? '') ||
        ($target['port'] ?? (strtolower($target['scheme']) === 'https' ? 443 : 80)) !==
        ($base['port'] ?? (strtolower($base['scheme'] ?? 'https') === 'https' ? 443 : 80))) {
        return $fallback;
    }
    return $candidate;
}
function PT_SecurityIpInCidr($ip, $cidr) {
    list($network, $prefix) = explode('/', $cidr, 2);
    $address = @inet_pton($ip); $base = @inet_pton($network); $prefix = (int) $prefix;
    if ($address === false || $base === false || strlen($address) !== strlen($base)) { return false; }
    $whole = intdiv($prefix, 8); $remaining = $prefix % 8;
    if (substr($address, 0, $whole) !== substr($base, 0, $whole)) { return false; }
    return !$remaining || ((ord($address[$whole]) & (255 << (8 - $remaining))) ===
        (ord($base[$whole]) & (255 << (8 - $remaining))));
}
function PT_SecurityPublicIp($ip) {
    $packed = @inet_pton($ip);
    if ($packed === false) { return false; }
    if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
        $ip = inet_ntop(substr($packed, 12));
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return false; }
    if (strpos($ip, ':') !== false) {
        if (!PT_SecurityIpInCidr($ip, '2000::/3')) { return false; }
        $denied = array('2001::/32', '2001:db8::/32', '2002::/16', '3fff::/20');
    } else {
        $denied = array('0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8',
            '169.254.0.0/16','172.16.0.0/12','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24',
            '192.168.0.0/16','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4');
    }
    foreach ($denied as $network) { if (PT_SecurityIpInCidr($ip, $network)) { return false; } }
    return true;
}
function PT_SecurityRemoteTarget($url) {
    if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) ||
        preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { return false; }
    $parts = parse_url($url);
    if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
        !in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true)) { return false; }
    $host = trim($parts['host'], '[]');
    $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
    if (!in_array($port, array(80, 443), true)) { return false; }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return PT_SecurityPublicIp($host) ? array($host, $port, $host) : false;
    }
    if (!preg_match('/\A[a-z0-9][a-z0-9.-]*\z/Di', $host)) { return false; }
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (!$records) { return false; }
    $ips = array();
    foreach ($records as $record) {
        $ip = $record['ip'] ?? ($record['ipv6'] ?? null);
        if ($ip !== null) {
            if (!PT_SecurityPublicIp($ip)) { return false; }
            $ips[] = $ip;
        }
    }
    return $ips ? array($host, $port, $ips[0]) : false;
}
function PT_SecurityRemoteRequest($url, $config = array(), $includeHeaders = false) {
    // Every redirect is independently validated and DNS is pinned for the connection.
    for ($redirect = 0; $redirect <= 3; $redirect++) {
        $target = PT_SecurityRemoteTarget($url);
        if ($target === false) { return false; }
        list($host, $port, $ip) = $target;
        $curl = curl_init($url);
        $body = ''; $headers = ''; $location = ''; $tooLarge = false;
        $options = array(
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
            CURLOPT_RESOLVE => array($host . ':' . $port . ':' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip)),
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, &$tooLarge) {
                if (strlen($body) + strlen($chunk) > 32 * 1024 * 1024) { $tooLarge = true; return 0; }
                $body .= $chunk; return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers, &$location) {
                if (strlen($headers) + strlen($line) > 65536) { return 0; }
                $headers .= $line;
                if (stripos($line, 'Location:') === 0) { $location = trim(substr($line, 9)); }
                return strlen($line);
            }
        );
        if (!empty($config['POST'])) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $config['POST'];
        }
        if (!empty($config['bearer']) && is_string($config['bearer']) && !preg_match('/[\r\n]/', $config['bearer'])) {
            $options[CURLOPT_HTTPHEADER] = array('Authorization: Bearer ' . $config['bearer']);
        }
        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl); $error = curl_errno($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($ok === false || $error || $tooLarge) { return false; }
        if ($status >= 300 && $status < 400 && $location !== '') {
            if ($redirect === 3 || !empty($config['POST']) || !empty($config['bearer'])) { return false; }
            if (preg_match('#\Ahttps?://#i', $location)) { $url = $location; }
            else {
                $parts = parse_url($url);
                $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
                if (substr($location, 0, 2) === '//') { $url = $parts['scheme'] . ':' . $location; }
                elseif (substr($location, 0, 1) === '/') { $url = $origin . $location; }
                elseif (substr($location, 0, 1) === '?') { $url = $origin . ($parts['path'] ?? '/') . $location; }
                else { $url = $origin . rtrim(dirname($parts['path'] ?? '/'), '/.') . '/' . $location; }
            }
            continue;
        }
        if ($status < 200 || $status >= 300) { return false; }
        return $includeHeaders ? $headers . $body : $body;
    }
    return false;
}

function PT_SecurityImageUpload($filename, $extension) {
    if (!is_string($filename) || !is_file($filename) || filesize($filename) > 20 * 1024 * 1024) { return false; }
    $filename = realpath($filename);
    if ($filename === false || preg_match('/[\x00-\x1f\x7f]/', $filename)) { return false; }
    $image = @getimagesize($filename);
    $mime = array('jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp');
    if (!$image || !isset($mime[$extension]) || ($image['mime'] ?? '') !== $mime[$extension] ||
        $image[0] < 1 || $image[1] < 1 || $image[0] > 12000 || $image[1] > 12000 ||
        $image[0] * $image[1] > 20000000) { return false; }
    $file = @fopen($filename, 'rb');
    if (!$file) { return false; }
    $tail = ''; $shortTag = false;
    while (!feof($file)) {
        $data = fread($file, 65536);
        if ($data === false) { fclose($file); return false; }
        $chunk = $tail . $data;
        if (preg_match('/<\?php\s/i', $chunk)) { fclose($file); return false; }
        if (strpos($chunk, '<?=') !== false) { $shortTag = true; }
        $tail = substr($chunk, -16);
    }
    fclose($file);
    if (!$shortTag) { return true; }

    // A short tag can occur randomly in compressed image data. Compile only:
    // the fixed -n -l command never runs the uploaded file or PHP startup files.
    foreach (array('proc_open', 'proc_get_status', 'proc_terminate', 'proc_close') as $function) {
        if (!function_exists($function)) { return false; }
    }
    if (!is_executable('/usr/bin/php8.2')) { return false; }
    $command = array('/usr/bin/php8.2', '-n', '-d', 'memory_limit=96M', '-l', $filename);
    $descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $process = @proc_open($command, $descriptors, $pipes, null, array('PATH'=>'/usr/bin:/bin', 'LANG'=>'C'), array('bypass_shell'=>true));
    if (!is_resource($process)) { return false; }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = ''; $exitCode = null; $timedOut = false;
    $deadline = microtime(true) + 2.0;
    do {
        foreach (array(1, 2) as $pipe) {
            $data = fread($pipes[$pipe], 4096);
            if ($data !== false && $data !== '') { $output = substr($output . $data, -8192); }
        }
        $status = proc_get_status($process);
        if (!$status['running']) { $exitCode = $status['exitcode']; break; }
        if (microtime(true) >= $deadline) { $timedOut = true; @proc_terminate($process, 9); break; }
        usleep(10000);
    } while (true);
    foreach (array(1, 2) as $pipe) {
        while (!feof($pipes[$pipe])) {
            $data = fread($pipes[$pipe], 4096);
            if ($data === false || $data === '') { break; }
            $output = substr($output . $data, -8192);
        }
        fclose($pipes[$pipe]);
    }
    $closed = proc_close($process);
    if ($exitCode === null || $exitCode < 0) { $exitCode = $closed; }
    if ($timedOut) { return false; }
    // A valid PHP file, a resource failure, or an unknown outcome is rejected.
    return $exitCode > 0 && preg_match('/^(?:PHP )?Parse error:/m', $output) === 1;
}

function PT_SecuritySameOrigin($candidate, $baseUrl) {
    if (!is_string($candidate) || !is_string($baseUrl)) { return false; }
    $a = parse_url($candidate); $b = parse_url($baseUrl);
    if (!$a || !$b || empty($a['host']) || empty($a['scheme']) || empty($b['host']) ||
        isset($a['user']) || isset($a['pass'])) { return false; }
    $scheme = strtolower($a['scheme']); $baseScheme = strtolower($b['scheme'] ?? 'https');
    return $scheme === $baseScheme && strtolower($a['host']) === strtolower($b['host']) &&
        ($a['port'] ?? ($scheme === 'https' ? 443 : 80)) ===
        ($b['port'] ?? ($baseScheme === 'https' ? 443 : 80));
}
function PT_RequireSameOriginPost($baseUrl) {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { return; }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    $referer = $_SERVER['HTTP_REFERER'] ?? null;
    $crossSite = strtolower($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site';
    $valid = !$crossSite && ($origin !== null ? PT_SecuritySameOrigin($origin, $baseUrl) :
        ($referer !== null ? PT_SecuritySameOrigin($referer, $baseUrl) : PT_AdminCsrfValid()));
    if (!$valid) {
        http_response_code(403);
        exit('Invalid request origin; reload the sign-in page');
    }
}
