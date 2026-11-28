<?php
// Offline behavioral checks. No site bootstrap, database, credentials or external calls.
error_reporting(E_ALL);
require dirname(__DIR__) . '/assets/includes/tables.php';
require dirname(__DIR__) . '/assets/includes/traffic_reads.php';
require dirname(__DIR__) . '/assets/includes/functions_transcribe.php';
function PT_Link($path) { return '/fixture/' . $path; }

class TrafficReadFixtureDb {
    public $tables = array();
    public $queries = 0;
    public $failNext = false;
    private $where = array();
    private $arrays = false;
    public function where($key, $value, $operator = '=') { $this->where[] = array($key, $value, $operator); return $this; }
    public function arraybuilder() { $this->arrays = true; return $this; }
    public function get($table, $limit = null, $columns = null) {
        $this->queries++;
        $conditions = $this->where; $arrays = $this->arrays;
        $this->where = array(); $this->arrays = false;
        if ($this->failNext) { $this->failNext = false; return false; }
        $out = array();
        foreach ($this->tables[$table] ?? array() as $row) {
            foreach ($conditions as $condition) {
                list($key, $value, $operator) = $condition;
                if ($operator === 'IN' ? !in_array($row[$key] ?? null, $value, true) : ($row[$key] ?? null) !== $value) { continue 2; }
            }
            if ($columns !== null) { $row = array_intersect_key($row, array_flip($columns)); }
            $out[] = $arrays ? $row : (object) $row;
        }
        return $limit === null ? $out : array_slice($out, 0, $limit);
    }
    public function getOne($table) { $rows = $this->get($table, 1); return $rows ? $rows[0] : null; }
}

$assertions = 0;
function trafficSame($expected, $actual, $message) {
    global $assertions;
    $assertions++;
    if ($expected !== $actual) { throw new RuntimeException($message . ': ' . var_export($actual, true)); }
}

$database = new TrafficReadFixtureDb();
$database->tables[T_LANG_ISO] = array(
    array('lang_name' => 'english', 'iso' => 'en', 'status' => 1),
    array('lang_name' => 'spanish', 'iso' => 'es', 'status' => 0),
    array('lang_name' => 'empty', 'iso' => '', 'status' => null),
    array('lang_name' => 'english', 'iso' => 'wrong-duplicate', 'status' => 0),
);
$languages = array(3 => 'spanish', 4 => 'english', 5 => 'missing', 6 => 'empty');
trafficSame(array('spanish' => 'es', 'english' => 'en'), PT_TrafficLanguageMap($database, $languages, 'iso'), 'Language order, missing metadata and duplicate semantics');
trafficSame(array('spanish' => 0, 'english' => 1), PT_TrafficLanguageMap($database, $languages, 'status'), 'Disabled languages retain zero status');
trafficSame(1, $database->queries, 'Both language maps share one read');
trafficSame(array(), PT_TrafficLanguageMap($database, array(), 'iso'), 'Empty list');
trafficSame(1, $database->queries, 'Empty list does no work');
trafficSame(array(), PT_TrafficLanguageMap($database, $languages, 'credentials'), 'Only supported metadata fields');
trafficSame(1, $database->queries, 'Invalid field does no work');
trafficSame(array('english' => 'en'), PT_TrafficLanguageMap($database, array('english'), 'iso'), 'Different list is scoped');
trafficSame(2, $database->queries, 'Different list gets its own read');
$other = new TrafficReadFixtureDb();
$other->tables[T_LANG_ISO] = array(array('lang_name' => 'english', 'iso' => 'changed', 'status' => 1));
trafficSame(array('english' => 'changed'), PT_TrafficLanguageMap($other, array('english'), 'iso'), 'Separate connection cannot reuse another catalog');
$failing = new TrafficReadFixtureDb(); $failing->tables = $other->tables; $failing->failNext = true;
trafficSame(array(), PT_TrafficLanguageMap($failing, array('english'), 'iso'), 'Failed read is safe');
trafficSame(array('english' => 'changed'), PT_TrafficLanguageMap($failing, array('english'), 'iso'), 'Failed read does not poison request cache');
trafficSame(2, $failing->queries, 'Failure allows retry');

foreach (array(1, 23, 100) as $size) {
    $large = new TrafficReadFixtureDb(); $names = array();
    for ($i = 0; $i < $size; $i++) {
        $names[] = 'language_' . $i;
        $large->tables[T_LANG_ISO][] = array('lang_name' => 'language_' . $i, 'iso' => 'x-' . $i, 'status' => 1);
    }
    trafficSame($size, count(PT_TrafficLanguageMap($large, $names, 'iso')), 'All ISO values retained');
    trafficSame($size, count(PT_TrafficLanguageMap($large, $names, 'status')), 'All statuses retained');
    trafficSame(1, $large->queries, 'Language query count does not grow with language count');
}

$navigation = new TrafficReadFixtureDb();
$navigation->tables[T_LANGS] = array(
    array('lang_key' => 'other', 'type' => 'category'),
    array('lang_key' => 'rife', 'type' => 'category'),
    array('lang_key' => 'nutrition', 'type' => 'category'),
    array('lang_key' => 'untranslated', 'type' => 'category'),
    array('lang_key' => 'devices', 'type' => 'rife'),
    array('lang_key' => 'missing_child', 'type' => 'rife'),
    array('lang_key' => 'food', 'type' => 'nutrition'),
    array('lang_key' => 'orphan', 'type' => 'not_a_category'),
);
$language = (object) array('other' => 'Other', 'rife' => 'Rife', 'nutrition' => 'Nutrition', 'devices' => 'Devices', 'food' => 'Food', 'orphan' => 'Orphan');
$result = PT_TrafficCategoryNavigation($navigation, $language);
trafficSame(array('rife' => 'Rife', 'nutrition' => 'Nutrition', 'other' => 'Other'), $result['categories'], 'Visible parent ordering with Other last');
trafficSame(array('rife' => array(array('devices' => 'Devices')), 'nutrition' => array(array('food' => 'Food'))), $result['sub_categories'], 'Translated children attach only to known parents');
trafficSame(2, $navigation->queries, 'One parent and one child read');
foreach (array(1, 14, 100) as $size) {
    $large = new TrafficReadFixtureDb(); $translations = (object) array('other' => 'Other');
    for ($i = 0; $i < $size; $i++) {
        $large->tables[T_LANGS][] = array('lang_key' => 'p' . $i, 'type' => 'category');
        $large->tables[T_LANGS][] = array('lang_key' => 'c' . $i, 'type' => 'p' . $i);
        $translations->{'p' . $i} = 'Parent ' . $i; $translations->{'c' . $i} = 'Child ' . $i;
    }
    $result = PT_TrafficCategoryNavigation($large, $translations);
    trafficSame($size + 1, count($result['categories']), 'Every category retained');
    trafficSame($size, count($result['sub_categories']), 'Every child group retained');
    trafficSame(2, $large->queries, 'Category query count is independent of parent count');
}
$empty = new TrafficReadFixtureDb();
trafficSame(array('categories' => array(), 'sub_categories' => array()), PT_TrafficCategoryNavigation($empty, $language), 'Empty catalog');
trafficSame(1, $empty->queries, 'No child query for empty catalog');
$onlyOther = new TrafficReadFixtureDb(); $onlyOther->tables[T_LANGS] = array(array('lang_key' => 'other', 'type' => 'category'));
trafficSame(array('categories' => array('other' => 'Other'), 'sub_categories' => array()), PT_TrafficCategoryNavigation($onlyOther, $language), 'Other-only catalog');
trafficSame(1, $onlyOther->queries, 'No child query for Other');

$db = new TrafficReadFixtureDb();
$db->tables[T_VIDEO_TRANSCRIPTS] = array(array('video_id' => 7, 'status' => 'completed', 'vtt_path' => 'upload/transcripts/test.vtt', 'language' => 'en', 'key_takeaways' => '[]'));
$video = PT_AttachTranscriptToVideo((object) array('id' => 7, 'video_id' => 'fixture'));
trafficSame('/fixture/vtt/fixture', $video->transcript_vtt_url, 'Caption URL is still access-checked route');
trafficSame(true, $video->has_completed_transcript, 'Completed transcript retained');
trafficSame(1, $db->queries, 'Caption attachment reuses loaded transcript');
trafficSame('/fixture/vtt/fixture', PT_GetTranscriptVttUrl((object) array('id' => 7, 'video_id' => 'fixture')), 'Standalone caller still loads metadata');
trafficSame(2, $db->queries, 'Standalone lookup counted');
trafficSame('', PT_GetTranscriptVttUrl((object) array('id' => 7, 'video_id' => 'fixture'), null), 'Explicit absent row avoids a fallback read');
trafficSame(2, $db->queries, 'Explicit absence does no read');
$db->tables[T_VIDEO_TRANSCRIPTS][0]['status'] = 'pending';
trafficSame('', PT_GetTranscriptVttUrl((object) array('id' => 7, 'video_id' => 'fixture')), 'Pending transcript cannot produce captions');
echo $assertions . " traffic-read assertions passed\n";
