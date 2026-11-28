<?php
// Run from the site root or pass --site-root. No web sessions, mail or processing queues.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '0');
set_time_limit(30); ini_set('memory_limit', '128M'); date_default_timezone_set('UTC');
try {
    $options = getopt('', array('site-root:'));
    $root = realpath($options['site-root'] ?? dirname(__DIR__));
    if (!$root || !is_file($root . '/config.php')) { throw new RuntimeException('Missing site configuration'); }
    ob_start();
    try { $settings = (static function ($path) { require $path; return get_defined_vars(); })($root . '/config.php'); }
    finally { ob_end_clean(); }
    require dirname(__DIR__) . '/assets/includes/tables.php';
    require dirname(__DIR__) . '/assets/includes/traffic_publication.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = mysqli_init(); $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $connection->real_connect($settings['sql_db_host'], $settings['sql_db_user'], $settings['sql_db_pass'], $settings['sql_db_name']);
    $connection->set_charset('utf8mb4');
    $connection->query('SET SESSION innodb_lock_wait_timeout = 5');
    $result = PT_PublishDueVideos($connection);
    echo json_encode(array('status' => 'ok', 'published' => $result['published'], 'lock_busy' => $result['locked'])) . "\n";
    $connection->close();
} catch (Throwable $error) { echo '{"status":"failed"}' . "\n"; exit(1); }
