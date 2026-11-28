<?php
/** Compare old/new read behavior using the staging DB, with a read-only transaction. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '0');
set_time_limit(90);
$mysqli = null; $phase = 'arguments';
$report = array('kind' => 'staging_component_read_comparison', 'application_bootstrap_used' => false,
    'database_writes' => false, 'full_http_request_benchmark' => false);

function trafficMeasure($db, $callback) {
    $db->trace = array(); $db->setTrace(true, ''); $start = microtime(true);
    $value = $callback(); $elapsed = (microtime(true) - $start) * 1000;
    $sqlTime = 0.0;
    foreach ($db->trace as $entry) { $sqlTime += (float) $entry[1] * 1000; }
    $queries = count($db->trace); $db->setTrace(false); $db->trace = array();
    return array('value' => $value, 'queries' => $queries, 'elapsed_ms' => round($elapsed, 3), 'sql_ms' => round($sqlTime, 3));
}
function trafficComparison($before, $after) {
    return array('same_result' => $before['value'] === $after['value'],
        'before_queries' => $before['queries'], 'after_queries' => $after['queries'],
        'before_elapsed_ms' => $before['elapsed_ms'], 'after_elapsed_ms' => $after['elapsed_ms'],
        'before_sql_ms' => $before['sql_ms'], 'after_sql_ms' => $after['sql_ms']);
}


try {
    $options = getopt('', array('site-root:'));
    $siteRoot = isset($options['site-root']) ? realpath($options['site-root']) : false;
    if ($siteRoot === false || !is_readable($siteRoot . '/config.php')) { throw new RuntimeException('Invalid site root'); }
    $phase = 'load_staging_config';
    $level = ob_get_level(); ob_start();
    try {
        $config = (static function ($file) { require $file; return get_defined_vars(); })($siteRoot . '/config.php');
    } finally { while (ob_get_level() > $level) { ob_end_clean(); } }
    if (strtolower((string) parse_url($config['site_url'] ?? '', PHP_URL_HOST)) !== 'test-videos.connersclinic.com') {
        throw new RuntimeException('Only staging configuration is accepted');
    }
    error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '0');
    $repo = dirname(__DIR__);
    require $repo . '/assets/includes/tables.php';
    require $repo . '/assets/libs/DB/vendor/autoload.php';
    require $repo . '/assets/includes/traffic_reads.php';
    require $repo . '/assets/includes/functions_transcribe.php';
    require $repo . '/assets/includes/richtext_security.php';
    require $repo . '/assets/includes/functions_general.php';
    require $repo . '/assets/includes/traffic_search.php';
    require $repo . '/assets/includes/traffic_cards.php';
    require $repo . '/tests/traffic-function-loader.php';
    trafficLoadFunctions($repo . '/assets/includes/functions_one.php', array('PT_GetVideoByID', 'PT_UserData', 'PT_GetMedia', 'PT_IsAdmin', 'addhttp', 'TranslateDate'));
    define('IS_LOGGED', false);
    $site_url = $config['site_url'];
    $phase = 'database_connection';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $mysqli = mysqli_init(); $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $mysqli->real_connect($config['sql_db_host'], $config['sql_db_user'], $config['sql_db_pass'], $config['sql_db_name']);
    $mysqli->set_charset('utf8mb4');
    $mysqli->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
    $db = new MysqliDb($mysqli);
    $phase = 'language_inventory';
    $columns = $db->rawQuery('DESCRIBE `' . T_LANGS . '`');
    $languages = array_slice(array_map(static function ($row) { return ((object) $row)->Field; }, $columns), 3);
    $report['language_count'] = count($languages);
    $phase = 'language_comparison';
    $before = trafficMeasure($db, static function () use ($db, $languages) {
        $iso = array(); $status = array();
        foreach ($languages as $name) {
            $row = $db->where('lang_name', $name)->getOne(T_LANG_ISO);
            if (!empty($row) && !empty($row->iso)) { $iso[$name] = $row->iso; }
        }
        foreach ($languages as $name) {
            $row = $db->where('lang_name', $name)->getOne(T_LANG_ISO);
            if (!empty($row) && isset($row->status)) { $status[$name] = $row->status; }
        }
        return array($iso, $status);
    });
    $after = trafficMeasure($db, static function () use ($db, $languages) {
        return array(PT_TrafficLanguageMap($db, $languages, 'iso'), PT_TrafficLanguageMap($db, $languages, 'status'));
    });
    $report['languages'] = trafficComparison($before, $after);
    $phase = 'category_comparison';
    $configured = $db->where('name', 'language')->getOne(T_CONFIG);
    $locale = isset($configured->value) && in_array($configured->value, $languages, true) ? $configured->value : 'english';
    if (!in_array($locale, $languages, true)) { throw new RuntimeException('Missing fixture language'); }
    $strings = $db->rawQuery('SELECT `lang_key`, `' . str_replace('`', '``', $locale) . '` FROM `' . T_LANGS . '`');
    $lang = (object) array();
    foreach ($strings as $row) { $lang->{$row->lang_key} = $row->{$locale}; }
    $before = trafficMeasure($db, static function () use ($db, $lang) {
        // Original startup loop, including its ordering, for direct output comparison.
        $categories = array(); $sub_categories = array();
        $all_categories = $db->where('type', 'category')->get(T_LANGS);
        foreach ($all_categories as $key => $value) {
            $array_keys = array_keys($all_categories);
            if ($value->lang_key != 'other') {
                if (!empty($value->lang_key) && !empty($lang->{$value->lang_key})) { $categories[$value->lang_key] = $lang->{$value->lang_key}; }
                $all_sub_categories = $db->where('type', $value->lang_key)->get(T_LANGS);
                foreach ((array) $all_sub_categories as $key => $sub) {
                    if (!empty($sub->lang_key) && !empty($lang->{$sub->lang_key})) { $sub_categories[$value->lang_key][] = array($sub->lang_key => $lang->{$sub->lang_key}); }
                }
            }
            if (end($array_keys) == $key) { $categories['other'] = $lang->other; }
        }
        return array('categories' => $categories, 'sub_categories' => $sub_categories);
    });
    $after = trafficMeasure($db, static function () use ($db, $lang) { return PT_TrafficCategoryNavigation($db, $lang); });
    $navigation = $after['value'];
    $report['categories'] = trafficComparison($before, $after);
    $report['category_count'] = count($before['value']['categories']);
    $phase = 'transcript_comparison';
    $sample = $db->where('status', 'completed')->where('vtt_path', '', '<>')->getOne(T_VIDEO_TRANSCRIPTS, array('video_id'));
    if (!empty($sample)) {
        $video = (object) array('id' => (int) $sample->video_id, 'video_id' => 'benchmark');
        $before = trafficMeasure($db, static function () use ($video) {
            $row = PT_GetVideoTranscript($video->id);
            return array($row, PT_GetTranscriptVttUrl($video));
        });
        $after = trafficMeasure($db, static function () use ($video) {
            $attached = PT_AttachTranscriptToVideo(clone $video);
            return array($attached->transcript_row, $attached->transcript_vtt_url);
        });
        $report['transcript'] = trafficComparison($before, $after);
    } else { $report['transcript'] = array('skipped' => 'no_completed_caption_fixture'); }
    $phase = 'card_context';
    $display_config = array();
    foreach ($db->get(T_CONFIG) as $setting) { $display_config[$setting->name] = $setting->value; }
    $display_config['site_url'] = $site_url;
    $pt = (object) array('config' => (object) $display_config, 'language' => $locale, 'language_type' => 'ltr', 'loggedin' => false, 'blocked_array' => array(0));
    $categories = $navigation['categories'];
    $countries_name = array();
    $sample_videos = $db->where('privacy', 0)->where('approved', 1)->orderBy('id', 'DESC')->get(T_VIDEOS, 20);
    $project = static function ($rows) {
        $out = array();
        foreach ($rows as $video) {
            $item = array();
            foreach (array('id', 'title', 'url', 'ajax_url', 'thumbnail', 'gif', 'list_description', 'time_ago', 'time_alpha', 'category_name', 'video_id_') as $field) { $item[$field] = $video->{$field}; }
            foreach (array('name', 'name_v', 'avatar', 'url', 'username') as $field) { $item['owner_' . $field] = $video->owner->{$field} ?? null; }
            $out[] = $item;
        }
        return $out;
    };
    $phase = 'card_comparison';
    foreach (array(10, 20) as $size) {
        $rows = array_slice($sample_videos, 0, $size);
        $before = trafficMeasure($db, static function () use ($rows, $project) {
            $out = array(); foreach ($rows as $row) { $out[] = PT_GetVideoByID(clone $row, 0, 0, 0); }
            return $project($out);
        });
        $after = trafficMeasure($db, static function () use ($rows, $project) { return $project(PT_VideoCards($rows)); });
        $report['guest_cards_' . $size] = trafficComparison($before, $after);
        $report['guest_cards_' . $size]['cards'] = count($rows);
    }
    $phase = 'search_comparison';
    foreach (array('Rife', 'PectaSol', 'IV', 'B12', '5-FU') as $term) {
        $pattern = '%' . $term . '%';
        $before = trafficMeasure($db, static function () use ($db, $pattern) {
            $rows = $db->rawQuery("SELECT id FROM " . T_VIDEOS . " WHERE (title LIKE ? OR tags LIKE ? OR description LIKE ?) AND approved = 1 AND privacy = 0 ORDER BY CASE WHEN title LIKE ? THEN 1 WHEN description LIKE ? THEN 2 WHEN tags LIKE ? THEN 3 ELSE 4 END, id ASC LIMIT 20", array_fill(0, 6, $pattern));
            return array_map(static function ($row) { return (int) $row->id; }, $rows);
        });
        $after = trafficMeasure($db, static function () use ($db, $term) {
            return array_map(static function ($row) { return (int) $row->id; }, PT_SearchVideoRows($db, $term, array('limit' => 20)));
        });
        $report['search_fixtures'][$term] = trafficComparison($before, $after);
        $report['search_fixtures'][$term]['returned_rows'] = count($after['value']);
    }
    $mysqli->rollback();
    $pass = $report['languages']['same_result'] && $report['categories']['same_result']
        && (!isset($report['transcript']['same_result']) || $report['transcript']['same_result']);
    foreach (array(10, 20) as $size) { $pass = $pass && $report['guest_cards_' . $size]['same_result']; }
    foreach ($report['search_fixtures'] as $comparison) { $pass = $pass && $comparison['same_result']; }
    $report['status'] = $pass ? 'passed' : 'behavior_difference';
    echo 'TRAFFIC_READ_CHECK_JSON=' . json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
    exit($pass ? 0 : 2);
} catch (Throwable $error) {
    if ($mysqli instanceof mysqli) { try { $mysqli->rollback(); } catch (Throwable $ignored) {} }
    $report['status'] = 'failed'; $report['phase'] = $phase; $report['error_code'] = (int) $error->getCode();
    echo 'TRAFFIC_READ_CHECK_JSON=' . json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
    exit(1);
}
