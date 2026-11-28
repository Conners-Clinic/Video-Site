<?php
function PT_ListPageNumber($value) {
    return is_scalar($value) && ctype_digit((string) $value) ? min(10000, max(1, (int) $value)) : 1;
}

function PT_ListScope($watchLater, $listId, $ownerId) {
    global $pt;
    $table = $watchLater ? T_WLATER : T_PLAYLISTS;
    $where = $watchLater ? 'm.user_id = ?' : 'm.list_id = ?';
    $params = array($watchLater ? (int) $ownerId : $listId);
    $viewer = defined('IS_LOGGED') && IS_LOGGED ? (int) $pt->user->id : 0;
    $where .= ' AND (v.privacy <> 1 OR v.user_id = ?) AND (v.approved = 1 OR v.user_id = ?)';
    $params[] = $viewer; $params[] = $viewer;
    $blocked = array_values(array_unique(array_map('intval', $pt->blocked_array ?: array(0))));
    $where .= ' AND v.user_id NOT IN (' . implode(',', array_fill(0, count($blocked), '?')) . ')';
    return array('from' => $table . ' m JOIN ' . T_VIDEOS . ' v ON v.id = m.video_id',
        'where' => $where, 'params' => array_merge($params, $blocked));
}

function PT_ListWindow($watchLater, $listId, $ownerId, $currentVideo = 0, $requestedPage = null, $size = 50) {
    global $db;
    $size = min(50, max(1, (int) $size));
    $scope = PT_ListScope($watchLater, $listId, $ownerId);
    $base = ' FROM ' . $scope['from'] . ' WHERE ' . $scope['where'];
    $count = $db->rawQuery('SELECT COUNT(*) AS total' . $base, $scope['params']);
    $total = (int) $count[0]->total;
    $position = 0; $next = null;
    if ($currentVideo > 0) {
        $current = $db->rawQuery('SELECT m.id' . $base . ' AND v.id = ? ORDER BY m.id ASC LIMIT 1', array_merge($scope['params'], array((int) $currentVideo)));
        if ($current) {
            $rowId = (int) $current[0]->id;
            $before = $db->rawQuery('SELECT COUNT(*) AS total' . $base . ' AND m.id <= ?', array_merge($scope['params'], array($rowId)));
            $position = (int) $before[0]->total;
            $nextRows = $db->rawQuery('SELECT v.video_id, v.title' . $base . ' AND m.id > ? ORDER BY m.id ASC LIMIT 1', array_merge($scope['params'], array($rowId)));
            $next = $nextRows[0] ?? null;
        }
    }
    $pages = max(1, (int) ceil($total / $size));
    $page = $requestedPage === null ? intdiv(max(0, $position - 1), $size) + 1 : PT_ListPageNumber($requestedPage);
    $page = min($pages, $page); $offset = ($page - 1) * $size;
    $columns = implode(', ', array_map(static function ($column) { return 'v.' . $column; }, PT_VideoCardColumns()));
    $rows = $db->rawQuery('SELECT ' . $columns . $base . ' ORDER BY m.id ASC LIMIT ' . $offset . ', ' . ($size + 1), $scope['params']);
    return array('after_window' => $rows[$size] ?? null, 'rows' => array_slice($rows, 0, $size), 'total' => $total, 'position' => $position, 'page' => $page, 'pages' => $pages, 'offset' => $offset, 'next' => $next);
}

function PT_ListNavigation($base, $page, $pages, $parameter = 'list_page') {
    if ($pages <= 1) { return ''; }
    global $lang;
    $separator = strpos($base, '?') === false ? '?' : '&';
    $link = static function ($number, $label) use ($base, $separator, $parameter) {
        return '<a class="btn btn-default" href="' . htmlspecialchars($base . $separator . $parameter . '=' . $number, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a> ';
    };
    $html = '<nav class="playlist-pagination" aria-label="Playlist pages">';
    if ($page > 1) { $html .= $link($page - 1, $lang->previous ?? 'Previous'); }
    $html .= '<span>' . (int) $page . ' / ' . (int) $pages . '</span> ';
    if ($page < $pages) { $html .= $link($page + 1, $lang->next ?? 'Next'); }
    return $html . '</nav>';
}

/** Reorder only the submitted membership slots. Unloaded items retain their positions. */
function PT_ReorderListWindow($database, $watchLater, $listId, $ownerId, $values) {
    if (!is_array($values) || count($values) < 2 || count($values) > 50) { return false; }
    $ids = array();
    foreach ($values as $value) {
        if (!is_scalar($value) || !ctype_digit((string) $value) || (int) $value <= 0) { return false; }
        $ids[] = (int) $value;
    }
    if (count(array_unique($ids)) !== count($ids) || (!$watchLater && (!is_string($listId) || strlen($listId) > 100 || $listId === ''))) { return false; }
    $table = $watchLater ? T_WLATER : T_PLAYLISTS;
    $where = 'user_id = ?'; $params = array((int) $ownerId);
    if (!$watchLater) { $where .= ' AND list_id = ?'; $params[] = $listId; }
    $database->startTransaction();
    try {
        if (!$watchLater) {
            $owned = $database->rawQuery('SELECT id FROM ' . T_LISTS . ' WHERE list_id = ? AND user_id = ? FOR UPDATE', array($listId, (int) $ownerId));
            if (!$owned) { throw new RuntimeException('List ownership changed'); }
        }
        $rows = $database->rawQuery('SELECT id, video_id FROM ' . $table . ' WHERE ' . $where . ' AND video_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id ASC FOR UPDATE', array_merge($params, $ids));
        if (count($rows) !== count($ids)) { throw new RuntimeException('List membership changed'); }
        foreach ($rows as $index => $row) {
            if (!$database->where('id', (int) $row->id)->where('user_id', (int) $ownerId)->update($table, array('video_id' => $ids[$index]))) {
                throw new RuntimeException('List update failed');
            }
        }
        if (!$database->commit()) { throw new RuntimeException('List commit failed'); }
        return true;
    } catch (Throwable $error) {
        $database->rollback();
        return false;
    }
}
