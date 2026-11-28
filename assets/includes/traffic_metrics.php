<?php
/** Opt-in, expiring diagnostics for authenticated benchmark requests only. */
function PT_TrafficBeginRequest($database, $root = null) {
    $root = $root === null ? dirname(__DIR__, 2) : $root;
    $private = dirname($root) . '/.codex-video-traffic';
    $flag = $private . '/metrics-' . hash('sha256', $root) . '.json';
    if (empty($_SERVER['HTTP_X_TRAFFIC_BENCHMARK']) || !is_file($flag)) { return; }
    $settings = json_decode(file_get_contents($flag), true);
    if (!is_array($settings) || ($settings['expires'] ?? 0) < time()
        || !hash_equals($settings['key_hash'] ?? '', hash('sha256', $_SERVER['HTTP_X_TRAFFIC_BENCHMARK']))) { return; }
    $directory = realpath(dirname($settings['log_path'] ?? ''));
    $privateReal = realpath($private);
    if (!$directory || !$privateReal || strpos($directory . '/', $privateReal . '/') !== 0) { return; }
    $path = $settings['log_path'];
    $database->setTrace(true, '');
    register_shutdown_function(static function () use ($database, $path) {
        $snapshot = PT_TrafficRequestMetrics($database);
        $database->setTrace(false); $database->trace = array();
        // No SQL, medical search text, identifiers, credentials, URLs or captions.
        file_put_contents($path, json_encode($snapshot) . "\n", FILE_APPEND | LOCK_EX);
        chmod($path, 0600);
    });
}

function PT_TrafficRequestMetrics($database) {
    $route = $_GET['link1'] ?? 'home';
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'ajax.php') {
        $route = ($_GET['type'] ?? '') === 'search' ? 'suggestions' : 'other_ajax';
    }
    if (!in_array($route, array('home', 'watch', 'videos', 'search', 'timeline', 'suggestions', 'other_ajax'), true)) { $route = 'other'; }
    $sqlMs = 0.0;
    foreach ((array) $database->trace as $entry) { $sqlMs += (float) $entry[1] * 1000; }
    return array('route' => $route, 'signed_in' => defined('IS_LOGGED') && IS_LOGGED,
        'status' => http_response_code() ?: 200,
        'elapsed_ms' => round((microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000, 3),
        'instrumented_query_count' => count((array) $database->trace) + 1,
        'wrapper_sql_ms' => round($sqlMs, 3), 'peak_memory_bytes' => memory_get_peak_usage(true));
}
