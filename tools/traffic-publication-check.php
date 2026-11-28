<?php
// Connection-local temporary fixtures only. Requires staging; no application bootstrap.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '0'); set_time_limit(30);
$phase = 'configuration';
try {
    $options = getopt('', array('site-root:')); $root = realpath($options['site-root'] ?? '');
    if (!$root || !is_file($root . '/config.php')) { throw new RuntimeException('Missing config'); }
    ob_start();
    try { $settings = (static function ($file) { require $file; return get_defined_vars(); })($root . '/config.php'); }
    finally { ob_end_clean(); }
    if (parse_url($settings['site_url'] ?? '', PHP_URL_HOST) !== 'test-videos.connersclinic.com') { throw new RuntimeException('Staging only'); }
    require dirname(__DIR__) . '/assets/includes/tables.php';
    require dirname(__DIR__) . '/assets/includes/traffic_publication.php';
    require dirname(__DIR__) . '/tests/traffic-publication-mysql.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); $phase = 'connection';
    $connection = mysqli_init(); $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $connection->real_connect($settings['sql_db_host'], $settings['sql_db_user'], $settings['sql_db_pass'], $settings['sql_db_name']);
    $connection->set_charset('utf8mb4'); $connection->query('SET SESSION innodb_lock_wait_timeout = 5');
    $phase = 'temporary_fixture'; $checks = trafficPublicationMysqlChecks($connection);
    $connection->close();
    echo 'TRAFFIC_PUBLICATION_CHECK_JSON=' . json_encode(array('status' => 'passed', 'checks' => $checks,
        'application_records_changed' => false, 'fixture_kind' => 'connection_local_temporary_table', 'browser_visit_used' => false)) . "\n";
} catch (Throwable $error) { echo 'TRAFFIC_PUBLICATION_CHECK_JSON=' . json_encode(array('status' => 'failed', 'phase' => $phase, 'error_code' => (int) $error->getCode())) . "\n"; exit(1); }
