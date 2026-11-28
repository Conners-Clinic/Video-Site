<?php
/** Public display projection only. Callers retain their existing eligibility queries. */
function PT_VideoCardColumns() {
    return array('id', 'user_id', 'video_id', 'title', 'description', 'thumbnail', 'gif',
        'duration', 'time', 'views', 'privacy', 'is_short', 'live_time', 'is_stock',
        'sell_video', 'category_id', 'youtube', 'daily', 'vimeo', 'facebook', 'twitch');
}

function PT_FormatCardOwner($row) {
    global $pt;
    $owner = clone $row;
    $owner->name = !empty($owner->first_name) ? $owner->first_name . ' ' . $owner->last_name : $owner->username;
    $owner->name_v = $owner->name;
    if ($owner->verified == 1 && $pt->config->verification_badge === 'on') {
        $owner->name_v .= ' <i class="fa fa-check-circle fa-fw verified"></i>';
    }
    $owner->url = PT_Link('@' . $owner->username);
    $owner->avatar = PT_GetMedia($owner->avatar);
    return $owner;
}

function PT_VideoCardOwners($database, $ids) {
    // Store raw public fields only; every caller receives its own formatted clone.
    static $cache = null;
    if ($cache === null) { $cache = new WeakMap(); }
    $known = $cache[$database] ?? array();
    $missing = array_values(array_diff(array_unique(array_map('intval', $ids)), array_keys($known)));
    foreach (array_chunk($missing, 100) as $chunk) {
        $rows = $database->where('id', $chunk, 'IN')->get(T_USERS, null,
            array('id', 'username', 'first_name', 'last_name', 'avatar', 'verified'));
        if ($rows === false) { continue; }
        foreach ($rows as $row) { $known[(int) $row->id] = clone $row; }
    }
    $cache[$database] = $known;
    $result = array();
    foreach ($ids as $id) { if (isset($known[$id])) { $result[$id] = PT_FormatCardOwner($known[$id]); } }
    return $result;
}

function PT_VideoCards($rows, $database = null) {
    global $db, $pt, $categories;
    if ($database === null) { $database = $db; }
    $rows = (array) $rows;
    if (!$rows) { return array(); }
    $ids = array();
    foreach ($rows as $row) { if (!empty($row->user_id)) { $ids[] = $row->user_id; } }
    $owners = PT_VideoCardOwners($database, $ids);
    $cards = array();
    foreach ($rows as $key => $row) {
        $video = clone $row;
        $link = PT_VideoCardLink($video);
        $video->url = $link['url']; $video->ajax_url = $link['ajax_url'];
        $video->org_thumbnail = $video->thumbnail;
        if (strpos($video->thumbnail, 'upload/photos') !== false) { $video->thumbnail = PT_GetMedia($video->thumbnail); }
        $video->video_id_ = $video->video_id;
        foreach (array('youtube', 'daily', 'vimeo', 'facebook', 'twitch') as $provider) {
            if (!empty($video->{$provider})) { $video->video_id_ = $video->{$provider}; }
        }
        $video->list_description = PT_VideoListDescription($video->description ?? '');
        $video->title = PT_Markup($video->title, false, true, false);
        $video->owner = isset($owners[$video->user_id]) ? clone $owners[$video->user_id] : false;
        $video->time_alpha = TranslateDate(gmdate($pt->config->date_style, $video->time));
        $video->time_ago = PT_Time_Elapsed_String($video->time);
        $video->category_name = $categories[$video->category_id] ?? '';
        $video->gif = PT_GetMedia($video->gif);
        $cards[$key] = $video;
    }
    return $cards;
}
