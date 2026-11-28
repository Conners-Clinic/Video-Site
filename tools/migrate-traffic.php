<?php
/** CLI migration entrypoint. Uses server config directly, without sessions or app jobs. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
ini_set('display_errors', '0'); ini_set('log_errors', '0'); error_reporting(E_ALL);
ini_set('memory_limit', '128M'); set_time_limit(120);
$phase = 'manifest'; $connection = null; $locked = false;
try {
    $options = getopt('', array('site-root:', 'apply', 'check'));
    if (isset($options['apply']) && isset($options['check'])) { throw new RuntimeException('Choose one mode'); }
    $apply = isset($options['apply']);
    $files = glob(dirname(__DIR__) . '/migrations/traffic/[0-9]*.php'); sort($files, SORT_STRING);
    $migrations = array();
    foreach ($files as $file) {
        $id = basename($file, '.php');
        if (!preg_match('/^[0-9]{14}_[a-z0-9_]+$/D', $id)) { throw new RuntimeException('Invalid migration identifier'); }
        $migration = require $file;
        if (!is_array($migration) || ($migration['backward_compatible'] ?? false) !== true
            || !is_callable($migration['up'] ?? null) || !is_callable($migration['verify'] ?? null)) { throw new RuntimeException('Invalid or incompatible migration'); }
        $migrations[$id] = array('hash' => hash_file('sha256', $file), 'definition' => $migration);
    }
    if (!$migrations) { echo "TRAFFIC_MIGRATIONS_JSON={\"status\":\"passed\",\"pending\":0,\"applied\":0,\"schema_changed\":false}\n"; exit(0); }
    $phase = 'configuration'; $root = realpath($options['site-root'] ?? dirname(__DIR__));
    if (!$root || !is_file($root . '/config.php')) { throw new RuntimeException('Server configuration missing'); }
    ob_start();
    try { $settings = (static function ($file) { require $file; return get_defined_vars(); })($root . '/config.php'); }
    finally { ob_end_clean(); }
    if (!in_array(parse_url($settings['site_url'] ?? '', PHP_URL_HOST), array('videos.connersclinic.com', 'test-videos.connersclinic.com'), true)) { throw new RuntimeException('Unexpected environment'); }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = mysqli_init(); $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $connection->real_connect($settings['sql_db_host'], $settings['sql_db_user'], $settings['sql_db_pass'], $settings['sql_db_name']);
    $connection->set_charset('utf8mb4'); $connection->query('SET SESSION lock_wait_timeout = 5');
    $connection->query('SET SESSION innodb_lock_wait_timeout = 5');
    $lock = 'traffic_migrations_' . substr(hash('sha256', $settings['sql_db_name']), 0, 32);
    $statement = $connection->prepare('SELECT GET_LOCK(?, 0)'); $statement->bind_param('s', $lock); $statement->execute();
    $locked = (int) $statement->get_result()->fetch_row()[0] === 1; $statement->close();
    if (!$locked) { throw new RuntimeException('Another migration runner is active'); }
    $phase = 'ledger'; $ledger = 'traffic_schema_migrations';
    $exists = $connection->query("SHOW TABLES LIKE 'traffic_schema_migrations'")->num_rows > 0;
    $recorded = array();
    if ($exists) {
        foreach ($connection->query('SELECT migration_id, checksum, status FROM ' . $ledger)->fetch_all(MYSQLI_ASSOC) as $row) { $recorded[$row['migration_id']] = $row; }
    }
    $pending = array();
    foreach ($migrations as $id => $migration) {
        if (isset($recorded[$id]) && !hash_equals($recorded[$id]['checksum'], $migration['hash'])) { throw new RuntimeException('An existing migration was edited'); }
        if (($recorded[$id]['status'] ?? '') !== 'applied') { $pending[$id] = $migration; }
    }
    $applied = 0;
    if ($apply && $pending) {
        if (!$exists) { $connection->query('CREATE TABLE ' . $ledger . ' (migration_id VARCHAR(180) PRIMARY KEY, checksum CHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, applied_at DATETIME NULL) ENGINE=InnoDB'); }
        foreach ($pending as $id => $migration) {
            $phase = 'migration'; $hash = $migration['hash'];
            $statement = $connection->prepare('INSERT INTO ' . $ledger . " (migration_id, checksum, status) VALUES (?, ?, 'running') ON DUPLICATE KEY UPDATE status='running'");
            $statement->bind_param('ss', $id, $hash); $statement->execute(); $statement->close();
            // DDL may commit implicitly. Each up must be resumable; verify handles an interrupted successful build.
            if (!$migration['definition']['verify']($connection)) { $migration['definition']['up']($connection); }
            if (!$migration['definition']['verify']($connection)) { throw new RuntimeException('Migration verification failed'); }
            $statement = $connection->prepare('UPDATE ' . $ledger . " SET status='applied', applied_at=UTC_TIMESTAMP() WHERE migration_id=?");
            $statement->bind_param('s', $id); $statement->execute(); $statement->close(); $applied++;
        }
    }
    echo 'TRAFFIC_MIGRATIONS_JSON=' . json_encode(array('status' => 'passed', 'pending' => $apply ? 0 : count($pending), 'applied' => $applied, 'schema_changed' => $applied > 0)) . "\n";
} catch (Throwable $error) {
    echo 'TRAFFIC_MIGRATIONS_JSON=' . json_encode(array('status' => 'failed', 'phase' => $phase, 'error_code' => (int) $error->getCode())) . "\n"; exit(1);
} finally {
    if ($connection && $locked) { $statement = $connection->prepare('SELECT RELEASE_LOCK(?)'); $statement->bind_param('s', $lock); $statement->execute(); }
    if ($connection) { $connection->close(); }
}
