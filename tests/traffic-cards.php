<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return true;
});
define('IS_LOGGED', in_array('--signed', $argv, true));
$repo = dirname(__DIR__);
require $repo . '/assets/includes/tables.php';
require $repo . '/assets/includes/richtext_security.php';
require $repo . '/assets/includes/functions_general.php';
require $repo . '/assets/includes/traffic_search.php';
require $repo . '/assets/includes/traffic_cards.php';
require __DIR__ . '/traffic-function-loader.php';
trafficLoadFunctions($repo . '/assets/includes/functions_one.php', array('PT_GetVideoByID', 'PT_UserData', 'PT_GetMedia', 'PT_IsAdmin', 'addhttp'));
// Date localization does not query storage; keep the comparison clock deterministic.
function TranslateDate($date) { return $date; }
function durationToSeconds($duration) { $parts = array_map('intval', explode(':', $duration)); return $parts[0] * 60 + $parts[1]; }
$config = array('site_url' => 'https://example.test', 'seo_link' => 'on', 'verification_badge' => 'on', 'date_style' => 'Y-m-d',
    's3_upload' => 'off', 'ftp_upload' => 'off', 'spaces' => 'off', 'cloud_upload' => 'off', 'wasabi_storage' => 'off', 'backblaze_storage' => 'off', 'yandex_storage' => 'off', 'theme' => 'youplay');
$site_url = $config['site_url'];
$pt = (object) array('config' => (object) $config, 'language_type' => 'ltr', 'blocked_array' => array(0), 'loggedin' => IS_LOGGED);
if (IS_LOGGED) { $pt->user = (object) array('id' => 55, 'admin' => 0); }
$lang = (object) array('year' => 'year', 'years' => 'years', 'month' => 'month', 'months' => 'months', 'day' => 'day', 'days' => 'days',
    'hour' => 'hour', 'hours' => 'hours', 'minute' => 'minute', 'minutes' => 'minutes', 'second' => 'second', 'seconds' => 'seconds', 'time_ago' => 'ago', 'male' => 'male', 'female' => 'female');
$lang_array = array('views' => 'views', 'live' => 'Live'); $categories = array('category_1' => 'Education'); $countries_name = array();
class CardFixtureDb {
    public $queries = 0; public $where = array(); public $columns = array();
    function where($name, $value, $operator = '=') { $this->where[$name] = $value; return $this; }
    function get($table, $limit = null, $columns = null) {
        $this->queries++; $conditions = $this->where; $this->where = array(); $this->columns[] = $columns;
        if ($table !== T_USERS) { return array(); }
        $rows = array();
        foreach ((array) ($conditions['id'] ?? array(1)) as $id) {
            $owner = array('id' => (int) $id, 'username' => 'owner' . $id, 'first_name' => 'Clinic', 'last_name' => 'Teacher', 'avatar' => 'upload/photos/avatar.jpg', 'verified' => 1,
                'cover' => 'upload/photos/d-cover.jpg', 'last_active' => 1, 'about' => '', 'wallet' => 0, 'balance' => 0, 'country_id' => 0, 'gender' => 'male',
                'fav_category' => '', 'privacy' => '{"show_subscriptions_count":"yes"}');
            $rows[] = (object) ($columns ? array_intersect_key($owner, array_flip($columns)) : $owner);
        }
        return $rows;
    }
    function getOne($table, $columns = null) { $rows = $this->get($table, 1, $columns); return $rows[0] ?? null; }
    function getValue($table, $column) { $this->queries++; $this->where = array(); return 0; }
}
$checks = 0;
function cardCheck($condition, $message) { global $checks; $checks++; if (!$condition) { throw new RuntimeException($message); } }
function cardFixture($id) {
    return (object) array('id' => $id, 'user_id' => ($id % 2) + 1, 'video_id' => 'abc' . $id, 'title' => 'Rife &amp; PectaSol ' . $id,
        'description' => '<p>Readable &amp; useful.</p>', 'thumbnail' => 'upload/photos/thumb.jpg', 'gif' => '', 'duration' => '01:30',
        'time' => time() - 86400, 'views' => 100, 'privacy' => $id % 3, 'is_short' => $id % 2, 'live_time' => 0,
        'is_stock' => 1, 'sell_video' => 5, 'category_id' => 'category_1', 'youtube' => '', 'daily' => '', 'vimeo' => '', 'facebook' => '', 'twitch' => '',
        'type' => 0, 'embed' => '', 'video_location' => 'upload/videos/test.mp4');
}
function renderCard($video, $template) {
    global $pt;
    $pt->q = $video; $pt->video = $video;
    return PT_LoadPage($template, array('ID' => $video->id, 'TITLE' => $video->title, 'URL' => $video->url, 'ajax_url' => $video->ajax_url,
        'THUMBNAIL' => $video->thumbnail, 'GIF' => $video->gif, 'USER_DATA' => $video->owner, 'TIME' => $video->time_ago, 'VIEWS' => $video->views,
        'VIEWS_NUM' => number_format($video->views), 'DURATION' => $video->duration, 'PRICE' => $video->sell_video, 'CURRENCY' => '$', 'DESC' => $video->list_description));
}
foreach (array(10, 20) as $size) {
    $rows = array_map('cardFixture', range(1, $size));
    $db = new CardFixtureDb(); $before = array();
    foreach ($rows as $row) { $before[] = PT_GetVideoByID(clone $row, 0, 0, 0); }
    $beforeCount = $db->queries;
    $db = new CardFixtureDb(); $after = PT_VideoCards($rows);
    cardCheck($db->queries === 1, 'One owner batch for ' . $size . ' cards');
    cardCheck($beforeCount >= $size * 2, 'Comparison exercises repeated old reads');
    foreach ($after as $key => $card) {
        foreach (array('url', 'ajax_url', 'thumbnail', 'title', 'list_description', 'gif', 'time_alpha', 'time_ago', 'category_name') as $field) {
            cardCheck($card->{$field} === $before[$key]->{$field}, 'Display field parity: ' . $field);
        }
        cardCheck($card->owner->name_v === $before[$key]->owner->name_v, 'Verified owner name parity');
        cardCheck(!isset($card->is_purchased) && !isset($card->paused_time), 'Display projection omits viewer state');
    }
    foreach (array('default', 'youplay') as $theme) {
        $config['theme'] = $theme;
        foreach (array('home/list', 'videos/list', 'search/list') as $template) {
            cardCheck(renderCard($before[0], $template) === renderCard($after[0], $template), $theme . '/' . $template . ' markup parity');
        }
    }
    $after[0]->owner->name_v = 'Mutated';
    $again = PT_VideoCards($rows);
    cardCheck($again[0]->owner->name_v !== 'Mutated', 'Owner reuse returns independent objects');
    cardCheck($db->queries === 1, 'Repeated owner read reused within request');
    cardCheck(!isset($rows[0]->owner), 'Input rows remain untouched');
}
echo "$checks traffic-card assertions passed (" . (IS_LOGGED ? 'signed-in' : 'guest') . ")\n";
