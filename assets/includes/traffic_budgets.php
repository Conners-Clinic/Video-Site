<?php
/** Budgets apply only to measured viewer routes. Uploads and jobs keep their own limits. */
function PT_TrafficBudget($server, $query, $sapi = PHP_SAPI) {
    if ($sapi === 'cli') { return null; }
    $script = basename($server['SCRIPT_NAME'] ?? '');
    if ($script === 'ajax.php' && ($query['type'] ?? '') === 'search') {
        return array('memory' => '64M', 'seconds' => 8, 'sql_seconds' => 3);
    }
    if (in_array($script, array('index.php', 'page_loading.php'), true)
        && in_array($query['link1'] ?? 'home', array('home', 'watch', 'search', 'videos', 'timeline'), true)) {
        return array('memory' => '128M', 'seconds' => 20, 'sql_seconds' => 5);
    }
    return null;
}

function PT_ApplyTrafficBudget($budget) {
    if ($budget === null) { return; }
    ini_set('memory_limit', $budget['memory']);
    set_time_limit($budget['seconds']);
}

function PT_ApplyTrafficDatabaseBudget($database, $serverVersion, $budget) {
    if ($budget === null) { return; }
    // The wrapper records these statements in the opt-in aggregate profiler.
    $seconds = (int) $budget['sql_seconds'];
    if (stripos($serverVersion, 'MariaDB') !== false) {
        $database->rawQuery('SET SESSION max_statement_time = ' . $seconds);
    } elseif (version_compare($serverVersion, '5.7.8', '>=')) {
        $database->rawQuery('SET SESSION max_execution_time = ' . ($seconds * 1000));
    }
    $database->rawQuery('SET SESSION innodb_lock_wait_timeout = ' . $seconds);
}
