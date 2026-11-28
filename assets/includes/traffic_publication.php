<?php
/** Bounded publication work shared by CLI scheduling and the temporary browser fallback. */
function PT_PublishDueVideos($connection, $table = T_VIDEOS, $limit = 100, $now = null) {
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) { throw new InvalidArgumentException('Invalid publication table'); }
    $limit = min(100, max(1, (int) $limit)); $now = $now === null ? time() : (int) $now;
    $lock = $connection->query("SELECT GET_LOCK(CONCAT('pt-publish-', LEFT(SHA2(DATABASE(), 256), 32)), 0) AS acquired")->fetch_assoc();
    if ((int) ($lock['acquired'] ?? 0) !== 1) { return array('published' => 0, 'locked' => true); }
    try {
        $connection->begin_transaction();
        $stmt = $connection->prepare('SELECT id FROM `' . $table . '` WHERE privacy <> 0 AND approved = 1 AND publication_date > 0 AND publication_date <= ? ORDER BY publication_date, id LIMIT ' . $limit . ' FOR UPDATE');
        $stmt->bind_param('i', $now); $stmt->execute(); $stmt->bind_result($id); $ids = array();
        while ($stmt->fetch()) { $ids[] = (int) $id; } $stmt->close();
        $published = 0;
        if ($ids) {
            // IDs come from this locked query, never from an HTTP parameter.
            $connection->query('UPDATE `' . $table . '` SET privacy = 0, publication_date = 0 WHERE id IN (' . implode(',', $ids) . ')');
            $published = $connection->affected_rows;
        }
        $connection->commit();
        return array('published' => $published, 'locked' => false);
    } catch (Throwable $error) { $connection->rollback(); throw $error; }
    finally { $connection->query("SELECT RELEASE_LOCK(CONCAT('pt-publish-', LEFT(SHA2(DATABASE(), 256), 32)))"); }
}
