<?php
/** Bounded read helpers. Request-local caches contain no cross-request viewer state. */

function PT_TrafficLanguageInfo($database, $languages) {
    static $requests;
    if ($requests === null) { $requests = new WeakMap(); }
    $names = array_values(array_unique(array_filter((array) $languages, 'is_string')));
    if (!$names) { return array(); }
    $key = json_encode($names);
    $cache = isset($requests[$database]) ? $requests[$database] : array();
    if (array_key_exists($key, $cache)) { return $cache[$key]; }
    $rows = $database->where('lang_name', $names, 'IN')->get(T_LANG_ISO, null, array('lang_name', 'iso', 'status'));
    if ($rows === false) { return array(); }
    $map = array();
    foreach ((array) $rows as $row) {
        $row = (object) $row;
        if (isset($row->lang_name) && !isset($map[$row->lang_name])) { $map[$row->lang_name] = $row; }
    }
    $cache[$key] = $map;
    $requests[$database] = $cache;
    return $map;
}

function PT_TrafficLanguageMap($database, $languages, $field) {
    if (!in_array($field, array('iso', 'status'), true)) { return array(); }
    $map = PT_TrafficLanguageInfo($database, $languages);
    $result = array();
    foreach ((array) $languages as $name) {
        if (!is_string($name) || !isset($map[$name])) { continue; }
        $row = $map[$name];
        if ($field === 'iso' ? !empty($row->iso) : isset($row->status)) { $result[$name] = $row->{$field}; }
    }
    return $result;
}

function PT_TrafficCategoryNavigation($database, $language) {
    $parents = $database->where('type', 'category')->get(T_LANGS, null, array('lang_key'));
    $categories = array(); $subcategories = array(); $keys = array();
    foreach ((array) $parents as $parent) {
        $key = (string) ((object) $parent)->lang_key;
        if ($key === '' || $key === 'other') { continue; }
        $keys[] = $key;
        if (!empty($language->{$key})) { $categories[$key] = $language->{$key}; }
    }
    if ($keys) {
        $children = $database->where('type', array_values(array_unique($keys)), 'IN')
            ->get(T_LANGS, null, array('lang_key', 'type'));
        foreach ((array) $children as $child) {
            $child = (object) $child;
            if (!empty($child->lang_key) && !empty($language->{$child->lang_key})) {
                $subcategories[$child->type][] = array($child->lang_key => $language->{$child->lang_key});
            }
        }
    }
    if (!empty($parents)) { $categories['other'] = isset($language->other) ? $language->other : ''; }
    return array('categories' => $categories, 'sub_categories' => $subcategories);
}
