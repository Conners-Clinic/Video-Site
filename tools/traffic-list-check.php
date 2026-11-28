<?php
// All writes go to randomized connection-local temporary tables, never app tables.
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
    require dirname(__DIR__) . '/assets/libs/DB/vendor/autoload.php';
    $prefix = 'pt_tmp_traffic_' . bin2hex(random_bytes(5)) . '_';
    foreach (array('T_VIDEOS', 'T_LISTS', 'T_PLAYLISTS', 'T_WLATER', 'T_COMMENTS', 'T_COMM_REPLIES') as $name) { define($name, $prefix . strtolower($name)); }
    define('IS_LOGGED', true);
    require dirname(__DIR__) . '/assets/includes/traffic_lists.php';
    require dirname(__DIR__) . '/assets/includes/traffic_comments.php';
    function PT_VideoCardColumns() { return array('id', 'user_id', 'video_id', 'title'); }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); $phase = 'connection';
    $connection = mysqli_init(); $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $connection->real_connect($settings['sql_db_host'], $settings['sql_db_user'], $settings['sql_db_pass'], $settings['sql_db_name']);
    $connection->set_charset('utf8mb4'); $connection->query('SET SESSION innodb_lock_wait_timeout = 3');
    $connection->query('SET SESSION max_statement_time = 3');
    $db = new MysqliDb($connection); $db->returnType = 'object';
    $pt = (object) array('user' => (object) array('id' => 1), 'blocked_array' => array(9));
    $phase = 'temporary_fixture';
    $connection->query('CREATE TEMPORARY TABLE ' . T_VIDEOS . ' (id INT PRIMARY KEY, user_id INT, video_id VARCHAR(20), title VARCHAR(60), privacy INT DEFAULT 0, approved INT DEFAULT 1) ENGINE=InnoDB');
    $connection->query('CREATE TEMPORARY TABLE ' . T_LISTS . ' (id INT PRIMARY KEY, user_id INT, list_id VARCHAR(20)) ENGINE=InnoDB');
    foreach (array(T_PLAYLISTS, T_WLATER) as $table) { $connection->query('CREATE TEMPORARY TABLE ' . $table . ' (id INT PRIMARY KEY, user_id INT, video_id INT, list_id VARCHAR(20)) ENGINE=InnoDB'); }
    $connection->query('CREATE TEMPORARY TABLE ' . T_COMMENTS . ' (id INT PRIMARY KEY, user_id INT, video_id INT, pinned INT DEFAULT 0) ENGINE=InnoDB');
    $connection->query('CREATE TEMPORARY TABLE ' . T_COMM_REPLIES . ' (id INT PRIMARY KEY, user_id INT, comment_id INT) ENGINE=InnoDB');
    for ($i = 1; $i <= 120; $i++) {
        $connection->query('INSERT INTO ' . T_VIDEOS . " (id,user_id,video_id,title) VALUES ($i,2,'video$i','Fixture $i')");
        $connection->query('INSERT INTO ' . T_PLAYLISTS . " VALUES ($i,1,$i,'owned')");
        if ($i <= 70) { $connection->query('INSERT INTO ' . T_WLATER . " VALUES ($i,1,$i,'wl')"); }
    }
    $connection->query('INSERT INTO ' . T_LISTS . " VALUES (1,1,'owned'),(2,2,'other')");
    $connection->query('INSERT INTO ' . T_PLAYLISTS . " VALUES (121,2,1,'other')");
    $checks = 0;
    $check = static function ($ok) use (&$checks) { $checks++; if (!$ok) { throw new RuntimeException('Window fixture assertion ' . $checks); } };
    $window = PT_ListWindow(false, 'owned', 1, 50);
    $check(count($window['rows']) === 50 && $window['total'] === 120 && $window['position'] === 50);
    $check($window['next']->video_id === 'video51' && $window['after_window']->video_id === 'video51');
    $window = PT_ListWindow(false, 'owned', 1, 51);
    $check($window['page'] === 2 && (int) $window['rows'][0]->id === 51);
    $window = PT_ListWindow(false, 'owned', 1, 3, 2);
    $check($window['next']->video_id === 'video4' && (int) $window['rows'][0]->id === 51);
    $window = PT_ListWindow(false, 'owned', 1, 120, 999999);
    $check(count($window['rows']) === 20 && $window['next'] === null && $window['page'] === 3);
    $check(PT_ListWindow(true, 'wl', 1, 70)['total'] === 70);
    $check(PT_ListWindow(true, 'wl', 2)['total'] === 0);
    $connection->query('UPDATE ' . T_VIDEOS . ' SET privacy=1 WHERE id=120');
    $connection->query('UPDATE ' . T_VIDEOS . ' SET approved=0 WHERE id=119');
    $connection->query('UPDATE ' . T_VIDEOS . ' SET user_id=9 WHERE id=118');
    $check(PT_ListWindow(false, 'owned', 1)['total'] === 117);
    $check(PT_ReorderListWindow($db, false, 'owned', 1, array(5,4,3)));
    $ordered = $connection->query('SELECT video_id FROM ' . T_PLAYLISTS . ' WHERE id BETWEEN 2 AND 6 ORDER BY id')->fetch_all(MYSQLI_NUM);
    $check(array_map('intval', array_column($ordered, 0)) === array(2,5,4,3,6));
    $check(!PT_ReorderListWindow($db, false, 'other', 1, array(2,1)));
    $check(!PT_ReorderListWindow($db, false, 'owned', 1, array(3,3)));
    $check(!PT_ReorderListWindow($db, false, 'owned', 1, array(1,999)));
    $check(!PT_ReorderListWindow($db, false, 'owned', 1, range(1,51)));
    $check(PT_ReorderListWindow($db, true, '', 1, array(50,49)));
    $connection->query('INSERT INTO ' . T_COMMENTS . ' VALUES (1,2,1,0),(2,2,2,0),(3,9,1,0)');
    for ($i = 1; $i <= 12; $i++) { $connection->query('INSERT INTO ' . T_COMM_REPLIES . " VALUES ($i,2,1)"); }
    $connection->query('INSERT INTO ' . T_COMM_REPLIES . ' VALUES (13,9,1),(14,2,2)');
    $comment = (object) array('id' => 1, 'video_id' => 1); $_GET = array();
    $replies = PT_WatchReplyPage($comment);
    $check(count($replies['rows']) === 5 && $replies['more']);
    $_GET = array('cl' => 1, 'reply_page' => 2);
    $check((int) PT_WatchReplyPage($comment)['rows'][0]->id === 6);
    $_GET = array('rl' => 12);
    $replies = PT_WatchReplyPage($comment);
    $check(count($replies['rows']) === 2 && !$replies['more'] && $replies['page'] === 3);
    $check((int) PT_WatchCommentFocus(1)->id === 1);
    $_GET = array('rl' => 14); $check(PT_WatchCommentFocus(1) === null);
    $_GET = array('cl' => 3); $check(PT_WatchCommentFocus(1) === null);
    $_GET = array('rl' => 13); $check(PT_WatchCommentFocus(1) === null);
    $connection->close();
    echo 'TRAFFIC_LIST_CHECK_JSON=' . json_encode(array('status' => 'passed', 'checks' => $checks, 'application_records_changed' => false, 'fixture_kind' => 'connection_local_temporary_tables')) . "\n";
} catch (Throwable $error) {
    echo 'TRAFFIC_LIST_CHECK_JSON=' . json_encode(array('status' => 'failed', 'phase' => $phase, 'checks_completed' => $checks ?? 0, 'error_code' => (int) $error->getCode())) . "\n"; exit(1);
}
