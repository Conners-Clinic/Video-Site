<?php
/** Server-local recovery evidence. Never prints local source, credentials or paths. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
error_reporting(0); ini_set('display_errors', '0'); ini_set('log_errors', '0');
set_time_limit(120); umask(0077);
function checkoutGit($root, $args) {
    $lines = array(); $status = 1;
    exec('git --no-optional-locks -C ' . escapeshellarg($root) . ' ' . $args . ' 2>/dev/null', $lines, $status);
    if ($status !== 0) { throw new RuntimeException('Git inspection failed'); }
    return implode("\n", $lines);
}
function checkoutFunctions($source) {
    $tokens = token_get_all($source); $functions = array(); $outside = '';
    for ($i = 0; $i < count($tokens); $i++) {
        $token = $tokens[$i];
        if (is_array($token) && in_array($token[0], array(T_COMMENT, T_DOC_COMMENT, T_WHITESPACE), true)) { continue; }
        if (!is_array($token) || $token[0] !== T_FUNCTION) { $outside .= is_array($token) ? $token[1] : $token; continue; }
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) { $outside .= 'function'; continue; }
        $name = $tokens[$j][1]; $depth = 0; $opened = false; $body = '';
        for (; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || !in_array($token[0], array(T_COMMENT, T_DOC_COMMENT, T_WHITESPACE), true)) { $body .= is_array($token) ? $token[1] : $token; }
            if ($token === '{' || (is_array($token) && in_array($token[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) { $depth++; $opened = true; }
            if ($token === '}') { $depth--; if ($opened && $depth === 0) { break; } }
        }
        $functions[$name] = hash('sha256', $body);
    }
    return array('functions' => $functions, 'outside' => hash('sha256', $outside));
}
function checkoutProbe($root, $host) {
    $token = bin2hex(random_bytes(32)); $name = '_traffic_runtime_' . bin2hex(random_bytes(16)) . '.php';
    $file = $root . '/' . $name;
    $source = '<?php if (!hash_equals(' . var_export(hash('sha256', $token), true) . ', hash("sha256", $_SERVER["HTTP_X_TRAFFIC_PROBE"] ?? ""))) { http_response_code(404); exit; } '
        . 'header("Content-Type: application/json"); header("Cache-Control: private, no-store"); echo json_encode(array("php"=>PHP_VERSION,"sapi"=>PHP_SAPI,"memory_limit"=>ini_get("memory_limit"),"max_execution_time"=>ini_get("max_execution_time"),"session_handler"=>ini_get("session.save_handler"),"opcache_enabled"=>ini_get("opcache.enable"),"opcache_validate_timestamps"=>ini_get("opcache.validate_timestamps")));';
    try {
        if (file_put_contents($file, $source, LOCK_EX) !== strlen($source)) { throw new RuntimeException('Probe write failed'); }
        chmod($file, 0600);
        $curl = curl_init('https://' . $host . '/' . $name);
        curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => array('X-Traffic-Probe: ' . $token)));
        $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        $data = $status === 200 && is_string($body) ? json_decode($body, true) : null;
        return is_array($data) ? array_intersect_key($data, array_flip(array('php', 'sapi', 'memory_limit', 'max_execution_time', 'session_handler', 'opcache_enabled', 'opcache_validate_timestamps')))
            : array('available' => false, 'http_status' => $status);
    } finally { if (is_file($file)) { unlink($file); } }
}
$phase = 'arguments';
try {
    $options = getopt('', array('production:', 'staging:', 'private-base:'));
    $private = realpath($options['private-base'] ?? '');
    if (!$private || !is_writable($private)) { throw new RuntimeException('Private destination missing'); }
    $report = array('application_deployed' => false, 'database_mutations' => false);
    $candidate = checkoutFunctions(file_get_contents(dirname(__DIR__) . '/assets/includes/functions_one.php'));
    foreach (array('production' => 'videos.connersclinic.com', 'staging' => 'test-videos.connersclinic.com') as $label => $host) {
        $phase = $label . '_function_comparison';
        $root = realpath($options[$label] ?? '');
        if (!$root || !is_dir($root . '/.git') || str_starts_with($private . '/', $root . '/')) { throw new RuntimeException('Invalid checkout or backup destination'); }
        $result = array(); $path = 'assets/includes/functions_one.php';
        $base = checkoutFunctions(checkoutGit($root, 'show HEAD:' . $path));
        $working = checkoutFunctions(file_get_contents($root . '/' . $path));
        $changed = array(); $represented = true;
        foreach ($base['functions'] as $name => $digest) {
            if (($working['functions'][$name] ?? null) !== $digest) {
                // Only names already present in the public source may leave the host.
                if (isset($candidate['functions'][$name])) { $changed[] = $name; }
                $represented = $represented && (($working['functions'][$name] ?? null) === ($candidate['functions'][$name] ?? null));
            }
        }
        $result['public_function_names_changed'] = $changed;
        $result['additional_function_count'] = count(array_diff_key($working['functions'], $base['functions']));
        $result['top_level_tokens_match_head'] = $working['outside'] === $base['outside'];
        $result['changed_functions_already_in_candidate'] = $represented;
        $result['unstaged_change_count'] = count(array_filter(explode("\n", checkoutGit($root, 'diff --name-only'))));
        $phase = $label . '_runtime_probe';
        $result['fpm_before_application_bootstrap'] = checkoutProbe($root, $host);
        // Preserve the Git operation, tracked files and local configuration before any recovery.
        $phase = $label . '_backup';
        $snapshot = $private . '/checkout-' . $label . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        if (!mkdir($snapshot, 0700)) { throw new RuntimeException('Snapshot directory failed'); }
        $listFile = $snapshot . '/files.nul';
        $list = checkoutGit($root, 'ls-files');
        $paths = array_unique(array_filter(array_merge(explode("\n", $list), array('.git', 'config.php', 'phpMailer_config.php', 'nodejs/config.json', '.htaccess', '.user.ini'))));
        $included = array();
        foreach ($paths as $file) { if (is_file($root . '/' . $file) || is_link($root . '/' . $file) || $file === '.git') { $included[] = $file; } }
        file_put_contents($listFile, implode("\0", $included) . "\0");
        $archive = $snapshot . '/snapshot.tar.gz'; $log = $snapshot . '/snapshot.log'; $status = 1;
        exec('tar -C ' . escapeshellarg($root) . ' -czf ' . escapeshellarg($archive) . ' --null -T ' . escapeshellarg($listFile) . ' >' . escapeshellarg($log) . ' 2>&1', $ignored, $status);
        if ($status !== 0) { throw new RuntimeException('Snapshot archive failed'); }
        exec('tar -tzf ' . escapeshellarg($archive) . ' >/dev/null 2>>' . escapeshellarg($log), $ignored, $status);
        $result['private_backup_created'] = is_file($archive); $result['private_backup_verified'] = $status === 0;
        if ($status !== 0) { throw new RuntimeException('Snapshot verification failed'); }
        file_put_contents($private . '/latest-' . $label . '-snapshot', $snapshot);
        $report[$label] = $result;
    }
    echo 'TRAFFIC_CHECKOUT_CHECK_JSON=' . json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) { echo 'TRAFFIC_CHECKOUT_CHECK_JSON=' . json_encode(array('status' => 'failed', 'phase' => $phase, 'details_retained_on_server' => true)) . "\n"; exit(1); }
