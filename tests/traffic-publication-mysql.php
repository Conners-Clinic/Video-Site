<?php
/** Operates only on a connection-local temporary fixture table, never on app tables. */
function trafficPublicationMysqlChecks($connection) {
    $table = 'pt_test_publication_' . bin2hex(random_bytes(6)); $now = time();
    $connection->query('CREATE TEMPORARY TABLE `' . $table . '` (id INT PRIMARY KEY, privacy INT NOT NULL, approved INT NOT NULL, publication_date BIGINT NOT NULL) ENGINE=InnoDB');
    $checks = 0;
    $check = static function ($condition) use (&$checks) { $checks++; if (!$condition) { throw new RuntimeException('Publication fixture assertion failed'); } };
    try {
        $connection->query('INSERT INTO `' . $table . '` VALUES (1,1,1,' . ($now - 3600) . '),(2,1,1,' . ($now - 60) . '),(3,1,1,' . ($now + 3600) . '),(4,1,0,' . ($now - 60) . '),(5,1,1,0)');
        $one = PT_PublishDueVideos($connection, $table, 1, $now); $check($one['published'] === 1);
        $rows = $connection->query('SELECT * FROM `' . $table . '` ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        $check((int) $rows[0]['privacy'] === 0 && (int) $rows[0]['publication_date'] === 0);
        $check((int) $rows[1]['privacy'] === 1); // Batch limit, with deterministic oldest-first selection.
        $check(PT_PublishDueVideos($connection, $table, 100, $now)['published'] === 1);
        $check(PT_PublishDueVideos($connection, $table, 100, $now)['published'] === 0); // Retry does not repeat work.
        $connection->query('UPDATE `' . $table . '` SET privacy = 1 WHERE id = 1');
        $check(PT_PublishDueVideos($connection, $table, 100, $now)['published'] === 0); // Later privacy edit remains private.
        $check(PT_PublishDueVideos($connection, $table, 100, $now + 7200)['published'] === 1); // Missed tick catches up.
        $rows = $connection->query('SELECT privacy FROM `' . $table . '` WHERE id IN (4,5)')->fetch_all(MYSQLI_ASSOC);
        $check((int) $rows[0]['privacy'] === 1 && (int) $rows[1]['privacy'] === 1); // Pending approval/no schedule remain private.
        return $checks;
    } finally { $connection->query('DROP TEMPORARY TABLE `' . $table . '`'); }
}
