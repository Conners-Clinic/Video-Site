<?php
require dirname(__DIR__) . '/assets/includes/traffic_budgets.php';
$checks = 0;
$check = static function ($condition) use (&$checks) { $checks++; if (!$condition) { throw new RuntimeException('Budget isolation failed'); } };
foreach (array('home', 'watch', 'search', 'videos', 'timeline') as $route) {
    $check(PT_TrafficBudget(array('SCRIPT_NAME' => '/index.php'), array('link1' => $route), 'fpm-fcgi')['seconds'] === 20);
    $check(PT_TrafficBudget(array('SCRIPT_NAME' => '/page_loading.php'), array('link1' => $route), 'fpm-fcgi')['memory'] === '128M');
}
$check(PT_TrafficBudget(array('SCRIPT_NAME' => '/ajax.php'), array('type' => 'search'), 'fpm-fcgi')['seconds'] === 8);
foreach (array('upload', 'transcribe', 'seo_pipeline', 'payment', 'get_notifications') as $route) {
    $check(PT_TrafficBudget(array('SCRIPT_NAME' => '/ajax.php'), array('type' => $route), 'fpm-fcgi') === null);
}
$check(PT_TrafficBudget(array('SCRIPT_NAME' => '/index.php'), array('link1' => 'home'), 'cli') === null);
$check(PT_TrafficBudget(array('SCRIPT_NAME' => '/cron.php'), array(), 'fpm-fcgi') === null);
$check(PT_TrafficBudget(array('SCRIPT_NAME' => '/index.php'), array('link1' => array('home')), 'fpm-fcgi') === null);
$db = new class { public $statements = array(); function rawQuery($sql) { $this->statements[] = $sql; } };
PT_ApplyTrafficDatabaseBudget($db, '10.11.19-MariaDB', array('sql_seconds' => 3));
$check($db->statements === array('SET SESSION max_statement_time = 3', 'SET SESSION innodb_lock_wait_timeout = 3'));
$db->statements = array();
PT_ApplyTrafficDatabaseBudget($db, '5.7.44', array('sql_seconds' => 5));
$check($db->statements === array('SET SESSION max_execution_time = 5000', 'SET SESSION innodb_lock_wait_timeout = 5'));
echo $checks . " traffic budget isolation assertions passed\n";
