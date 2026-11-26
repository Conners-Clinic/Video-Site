<?php
// Read-only policy for responses that expose full media or transcript contents.
function PT_VideoSecurityValue($row, $key, $default = null) {
    if (is_array($row)) { return array_key_exists($key, $row) ? $row[$key] : $default; }
    return is_object($row) && isset($row->$key) ? $row->$key : $default;
}
function PT_SecurityCanViewVideo($video) {
    global $pt, $db;
    $video_id = (int) PT_VideoSecurityValue($video, 'id', 0);
    $owner_id = (int) PT_VideoSecurityValue($video, 'user_id', 0);
    if ($video_id < 1 || $owner_id < 1 || empty($pt->config)) { return false; }
    $actor_id = IS_LOGGED && !empty($pt->user->id) ? (int) $pt->user->id : 0;
    if (PT_IsAdmin() || ($actor_id > 0 && $actor_id === $owner_id)) { return true; }
    $blocked = PT_SecurityIdList($pt->blocked_array ?? array());
    if ($blocked === false || in_array($owner_id, $blocked, true)) { return false; }
    $privacy_level = PT_VideoSecurityValue($video, 'privacy', null);
    if ((!is_int($privacy_level) && !is_string($privacy_level))
        || !preg_match('/\A[012]\z/D', (string) $privacy_level) || (int) $privacy_level === 1) { return false; }
    if (($pt->config->require_login ?? 'off') === 'on' && !$actor_id) { return false; }
    if (($pt->config->require_subcription ?? 'off') === 'on'
        && (!$actor_id || empty($pt->user->is_pro))) { return false; }
    $sell_price = (float) PT_VideoSecurityValue($video, 'sell_video', 0);
    $rent_price = (float) PT_VideoSecurityValue($video, 'rent_price', 0);
    if ((($pt->config->approve_videos ?? 'off') === 'on'
        || (($pt->config->auto_approve_ ?? 'yes') === 'no' && ($sell_price > 0 || $rent_price > 0)))
        && (int) PT_VideoSecurityValue($video, 'approved', 0) !== 1) { return false; }
    if ((int) PT_VideoSecurityValue($video, 'age_restriction', 0) === 2
        && (!$actor_id || !function_exists('is_age') || !is_age($actor_id))) { return false; }
    if (($pt->config->geo_blocking ?? 'off') === 'on') {
        $geo = PT_VideoSecurityValue($video, 'geo_blocking', '');
        $raw_geo = $geo;
        $geo = is_string($geo) ? json_decode($geo, true) : $geo;
        if (!empty($raw_geo) && !is_array($geo)) { return false; }
        // A browser-writable continent cookie cannot establish trusted geographic access.
        if (!empty($geo)) { return false; }
    }
    $owner = PT_UserData($owner_id);
    if (empty($owner) || (int) PT_VideoSecurityValue($owner, 'active', 1) !== 1) { return false; }
    $privacy = PT_VideoSecurityValue($owner, 'privacy', null);
    if (is_string($privacy)) { $privacy = json_decode($privacy); }
    $watch_policy = PT_VideoSecurityValue($privacy, 'who_can_watch_my_videos', 'all');
    if (!in_array($watch_policy, array('all', 'subscribers', 'only_me'), true) || $watch_policy === 'only_me') { return false; }
    $channel_paid = (float) PT_VideoSecurityValue($owner, 'subscriber_price', 0) > 0;
    $needs_subscription = $channel_paid || $watch_policy === 'subscribers';
    $subscribed = false;
    if ($actor_id && ($needs_subscription || $sell_price > 0 || $rent_price > 0)) {
        $subscribed = (int) $db->where('user_id', $owner_id)->where('subscriber_id', $actor_id)
            ->where('active', 1)->getValue(T_SUBSCRIPTIONS, 'count(*)') > 0;
    }
    if ($needs_subscription && !$subscribed) { return false; }
    $paid_video = (($pt->config->sell_videos_system ?? 'off') === 'on' && $sell_price > 0)
        || (($pt->config->rent_videos_system ?? 'off') === 'on' && $rent_price > 0);
    if ($paid_video && !($channel_paid && $subscribed)) {
        if (!$actor_id) { return false; }
        $paid = $db->rawQuery('SELECT id FROM ' . T_VIDEOS_TRSNS
            . " WHERE video_id = ? AND paid_id = ? AND (type <> 'rent' OR time > ?) LIMIT 1",
            array($video_id, $actor_id, time() - 172800));
        if (empty($paid)) { return false; }
    }
    return true;
}
function PT_SecurityTranscriptPath($relative, $document_root) {
    if (!is_string($relative) || !preg_match('#\Aupload/transcripts/[A-Za-z0-9_-]{1,128}\.vtt\z#D', $relative)) { return false; }
    $root = realpath($document_root);
    $base = $root === false ? false : realpath($root . '/upload/transcripts');
    if ($base === false || strpos($base, $root . DIRECTORY_SEPARATOR) !== 0) { return false; }
    $candidate = $root . '/' . $relative;
    $resolved = realpath($candidate);
    if ($resolved === false || is_link($candidate) || !is_file($resolved) || !is_readable($resolved)
        || strpos($resolved, $base . DIRECTORY_SEPARATOR) !== 0 || filesize($resolved) > 32 * 1024 * 1024) { return false; }
    return $resolved;
}
