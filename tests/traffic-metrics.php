<?php
require dirname(__DIR__) . '/assets/includes/traffic_metrics.php';
$db = (object) array('trace' => array(array('SELECT private medical search FROM account_secret', 0.01), array('SELECT private_caption', 0.02)));
$_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 0.1;
$_GET = array('link1' => 'search', 'keyword' => 'private medical search');
$metrics = PT_TrafficRequestMetrics($db);
if ($metrics['instrumented_query_count'] !== 3 || $metrics['wrapper_sql_ms'] !== 30.0 || $metrics['route'] !== 'search') { throw new RuntimeException('Aggregate measurement mismatch'); }
$json = json_encode($metrics);
foreach (array('private', 'secret', 'SELECT', 'caption', 'keyword') as $text) {
    if (strpos($json, $text) !== false) { throw new RuntimeException('Sensitive diagnostic field emitted'); }
}
$_GET['link1'] = 'private_route_name';
if (PT_TrafficRequestMetrics($db)['route'] !== 'other') { throw new RuntimeException('Route allowlist failed'); }
echo "Aggregate metrics and diagnostic privacy checks passed\n";
