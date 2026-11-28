<?php
/** Shared bounds and matching rules for search pages, suggestions and continuation. */
function PT_SearchTerm($value) {
    if (!is_string($value) || strlen($value) > 1600 || !mb_check_encoding($value, 'UTF-8')) {
        return null;
    }
    $term = preg_replace('/\s+/u', ' ', trim(strip_tags($value)));
    $term = trim($term);
    $length = mb_strlen($term, 'UTF-8');
    if ($length < 2 || $length > 200 || count(explode(' ', $term)) > 12) { return null; }
    return $term;
}

function PT_SearchLike($term) {
    // Treat typed percent/underscore as text, not unbounded wildcard operators.
    return '%' . strtr($term, array('!' => '!!', '%' => '!%', '_' => '!_')) . '%';
}

function PT_SearchWhere($term, $options = array()) {
    if (PT_SearchTerm($term) === null) { return array('1 = 0', array()); }
    $pattern = PT_SearchLike($term);
    $where = "(title LIKE ? ESCAPE '!' OR tags LIKE ? ESCAPE '!' OR description LIKE ? ESCAPE '!') AND approved = 1";
    $where .= !empty($options['admin']) ? ' AND privacy IN (0, 2)' : ' AND privacy = 0';
    $params = array($pattern, $pattern, $pattern);
    foreach (array('categories' => array('category_id', 'IN'), 'blocked' => array('user_id', 'NOT IN')) as $key => $rule) {
        $values = array_values(array_unique(array_map('strval', (array) ($options[$key] ?? array()))));
        if ($values) {
            $where .= ' AND ' . $rule[0] . ' ' . $rule[1] . ' (' . implode(',', array_fill(0, count($values), '?')) . ')';
            array_push($params, ...$values);
        }
    }
    $periods = array('last_hour' => 3600, 'today' => 86400, 'this_week' => 604800, 'this_month' => 2592000, 'this_year' => 31536000);
    if (isset($periods[$options['date'] ?? ''])) {
        $where .= ' AND time >= ?'; $params[] = time() - $periods[$options['date']];
    }
    if (isset($options['after_id'])) { $where .= ' AND id > ?'; $params[] = max(0, (int) $options['after_id']); }
    return array($where, $params);
}

function PT_SearchVideoRows($database, $term, $options = array()) {
    if (PT_SearchTerm($term) === null) { return array(); }
    list($where, $params) = PT_SearchWhere($term, $options);
    $limit = min(50, max(1, (int) ($options['limit'] ?? 20)));
    $page = min(10000, max(1, (int) ($options['page'] ?? 1)));
    $columns = !empty($options['suggestions']) ? 'id, video_id, title, is_short, privacy' : implode(', ', PT_VideoCardColumns());
    $order = 'id ASC';
    if (!isset($options['after_id'])) {
        $order = "CASE WHEN title LIKE ? ESCAPE '!' THEN 1 WHEN description LIKE ? ESCAPE '!' THEN 2 WHEN tags LIKE ? ESCAPE '!' THEN 3 ELSE 4 END, id ASC";
        $pattern = PT_SearchLike($term); array_push($params, $pattern, $pattern, $pattern);
    }
    return $database->objectBuilder()->rawQuery('SELECT ' . $columns . ' FROM ' . T_VIDEOS . ' WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit), $params) ?: array();
}

function PT_SearchVideoCount($database, $term, $options = array()) {
    if (PT_SearchTerm($term) === null) { return 0; }
    list($where, $params) = PT_SearchWhere($term, $options);
    $rows = $database->objectBuilder()->rawQuery('SELECT COUNT(*) AS total FROM ' . T_VIDEOS . ' WHERE ' . $where, $params);
    return (int) ($rows[0]->total ?? 0);
}

function PT_VideoCardLink($video, $seo = null) {
    global $pt;
    if ($seo === null) { $seo = $pt->config->seo_link === 'on'; }
    $id = $seo ? PT_Slug($video->title, $video->video_id) : $video->video_id;
    $route = !empty($video->is_short) ? 'shorts' : 'watch';
    return array('url' => PT_Link($route . '/' . $id), 'ajax_url' => '?link1=' . $route . '&id=' . $id);
}
