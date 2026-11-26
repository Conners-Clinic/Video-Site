<?php
/**
 * Test bootstrap: no network, no database, no API key needed.
 * - OpenAI calls go through $GLOBALS['PT_AI_TRANSPORT'] (mock).
 * - $db is an in-memory fake implementing the small subset the code uses.
 * - content-pipeline/ is copied to a temp dir so tests never modify the real entity library.
 */
error_reporting(E_ALL);
$GLOBALS['REPO'] = dirname(__DIR__);
$GLOBALS['TMP_ROOT'] = sys_get_temp_dir() . '/pt_tests_' . getmypid() . '/';
@mkdir($GLOBALS['TMP_ROOT'], 0777, true);

function t_copy_dir($src, $dst) {
    @mkdir($dst, 0777, true);
    foreach (scandir($src) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        is_dir("$src/$f") ? t_copy_dir("$src/$f", "$dst/$f") : copy("$src/$f", "$dst/$f");
    }
}
t_copy_dir($GLOBALS['REPO'] . '/content-pipeline', $GLOBALS['TMP_ROOT'] . 'content-pipeline');
register_shutdown_function(function () {
    $rm = function ($d) use (&$rm) {
        foreach (glob($d . '/{,.}*', GLOB_BRACE) as $f) {
            if (basename($f) === '.' || basename($f) === '..') { continue; }
            is_dir($f) ? $rm($f) : @unlink($f);
        }
        @rmdir($d);
    };
    $rm(rtrim($GLOBALS['TMP_ROOT'], '/'));
});

// --- stubs for site functions the code under test calls ---
$GLOBALS['PT_ROOT_OVERRIDE'] = $GLOBALS['TMP_ROOT'];
function PT_Link($p) { return '/' . $p; }

require $GLOBALS['REPO'] . '/assets/includes/tables.php';
require $GLOBALS['REPO'] . '/assets/includes/functions_transcribe.php';
require $GLOBALS['REPO'] . '/assets/includes/functions_ai.php';
require $GLOBALS['REPO'] . '/assets/includes/functions_ai_summary.php';
require $GLOBALS['REPO'] . '/assets/includes/functions_seo_pipeline.php';

class FakeDb {
    public $tables = array();
    public $count = 0;
    public $columns = array();      // "table.col" => true when the migration is "applied"
    public $held_locks = array();   // locks held by a different "connection"
    public $own_locks = array();
    private $w = array();
    private $order = null;
    private $as_array = false;
    private $ids = array();

    public function arraybuilder() { $this->as_array = true; return $this; }
    public function where($c, $v, $op = '=') { $this->w[] = array($c, $v, $op); return $this; }
    public function orderBy($c, $d = 'ASC') { $this->order = array($c, strtoupper($d)); return $this; }

    private function matches($row) {
        foreach ($this->w as $cond) {
            list($c, $v, $op) = $cond;
            $x = isset($row[$c]) ? $row[$c] : null;
            switch ($op) {
                case '<': if (!($x < $v)) return false; break;
                case '>=': if (!($x >= $v)) return false; break;
                case '<>': if ($x == $v) return false; break;
                default: if ($x != $v) return false;
            }
        }
        return true;
    }
    private function finish() { $this->w = array(); $this->order = null; $this->as_array = false; }
    private function select($t) {
        $rows = array();
        foreach (isset($this->tables[$t]) ? $this->tables[$t] : array() as $id => $row) {
            if ($this->matches($row)) { $rows[$id] = $row; }
        }
        if ($this->order) {
            $c = $this->order[0]; $d = $this->order[1];
            uasort($rows, function ($a, $b) use ($c, $d) { $r = ($a[$c] <=> $b[$c]); return $d === 'DESC' ? -$r : $r; });
        }
        return $rows;
    }
    private function out($row) { return $this->as_array ? $row : (object) $row; }

    public function get($t, $limit = null) {
        $rows = array_values($this->select($t));
        if ($limit) { $rows = array_slice($rows, 0, (int) $limit); }
        $o = array();
        foreach ($rows as $r) { $o[] = $this->out($r); }
        $this->finish();
        return $o ?: null;
    }
    public function getOne($t) {
        $rows = array_values($this->select($t));
        $r = $rows ? $this->out($rows[0]) : null;
        $this->finish();
        return $r;
    }
    public function getValue($t, $col) {
        $rows = $this->select($t);
        $this->finish();
        if (stripos($col, 'COUNT') !== false) { return count($rows); }
        $first = reset($rows);
        return $first ? $first[$col] : null;
    }
    public function insert($t, $data) {
        if (!isset($this->ids[$t])) { $this->ids[$t] = 0; }
        $id = ++$this->ids[$t];
        $data['id'] = $id;
        $this->tables[$t][$id] = $data;
        $this->finish();
        return $id;
    }
    public function update($t, $data) {
        $n = 0;
        foreach ($this->select($t) as $id => $row) {
            $this->tables[$t][$id] = array_merge($this->tables[$t][$id], $data);
            $n++;
        }
        $this->count = $n;
        $this->finish();
        return true;
    }
    public function delete($t) {
        foreach ($this->select($t) as $id => $row) { unset($this->tables[$t][$id]); }
        $this->finish();
        return true;
    }
    public function rawQuery($sql) {
        if (preg_match("/SHOW COLUMNS FROM `(\w+)` LIKE '(\w+)'/", $sql, $m)) {
            return !empty($this->columns[$m[1] . '.' . $m[2]]) ? array(array('Field' => $m[2])) : array();
        }
        if (preg_match("/GET_LOCK\('(\w+)'/", $sql, $m)) {
            if (isset($this->held_locks[$m[1]])) { return array((object) array('got' => 0)); }
            $this->own_locks[$m[1]] = true;
            return array((object) array('got' => 1));
        }
        if (preg_match("/RELEASE_LOCK\('(\w+)'/", $sql, $m)) { unset($this->own_locks[$m[1]]); return array(); }
        return array();
    }
}

// --- mock OpenAI ---
function api_body($content, $o = array()) {
    $o = array_merge(array('prompt' => 1000, 'completion' => 200, 'reasoning' => 0, 'cached' => 0, 'finish' => 'stop'), $o);
    return json_encode(array(
        'id' => 'chatcmpl-test',
        'choices' => array(array('index' => 0, 'finish_reason' => $o['finish'], 'message' => array('role' => 'assistant', 'content' => $content))),
        'usage' => array(
            'prompt_tokens' => $o['prompt'], 'completion_tokens' => $o['completion'],
            'completion_tokens_details' => array('reasoning_tokens' => $o['reasoning']),
            'prompt_tokens_details' => array('cached_tokens' => $o['cached']),
        ),
    ));
}
function api_ok($content, $o = array()) { return array('status' => 200, 'body' => api_body($content, $o), 'error' => ''); }
function api_http($status, $message, $code = null, $param = null) {
    return array('status' => $status, 'body' => json_encode(array('error' => array('message' => $message, 'code' => $code, 'param' => $param))), 'error' => '');
}

/** Install a mock; $handler(payload, callIndex) returns an api_* array. Calls are recorded in $GLOBALS['CALLS']. */
function mock_api($handler) {
    $GLOBALS['CALLS'] = array();
    $GLOBALS['PT_AI_TRANSPORT'] = function ($payload, $timeout) use ($handler) {
        $GLOBALS['CALLS'][] = $payload;
        return $handler($payload, count($GLOBALS['CALLS']));
    };
}
function call_text($payload) {
    $t = '';
    foreach ($payload['messages'] as $m) { $t .= $m['content'] . "\n"; }
    return $t;
}
function user_text($payload) {
    $last = end($payload['messages']);
    return $last['content'];
}

function reset_world() {
    global $pt, $db;
    $pt = new stdClass();
    $pt->config = new stdClass();
    $pt->config->openai_api_key = 'sk-test-not-real';
    $pt->config->seo_pipeline_system = 'on';
    $pt->remoteStorage = false;
    $db = new FakeDb();
    $GLOBALS['PT_AI_NO_SLEEP'] = true;
    $GLOBALS['PT_AI_CONFIG_OVERRIDE'] = array();
    $GLOBALS['CALLS'] = array();
    $GLOBALS['PT_AI_COLUMN_CACHE'] = array();
    unset($GLOBALS['PT_AI_TRANSPORT'], $GLOBALS['PT_AI_BUDGET']);
    copy($GLOBALS['REPO'] . '/content-pipeline/standards/entity-library.json', $GLOBALS['TMP_ROOT'] . 'content-pipeline/standards/entity-library.json');
}

// --- deterministic transcript generators ---
/** Teaching-style transcript of about $words words, with numbered sentences so position can be asserted. */
function make_transcript($words, $marker_prefix = 'POINT') {
    $topics = array('intravenous vitamin C', 'the Rife machine', 'BRCA1 testing', 'CA 19-9 tumor markers', 'curcumin absorption', 'hyperthermia', 'detoxification pathways', 'low dose naltrexone');
    $out = array();
    $i = 0;
    $count = 0;
    while ($count < $words) {
        $topic = $topics[$i % count($topics)];
        $s = "Number $i: when we talk about $topic we always look at the whole patient, and that is $marker_prefix-$i.";
        $out[] = $s;
        $count += str_word_count($s);
        $i++;
    }
    return implode(' ', $out);
}

function sample_video($id = 1) {
    return (object) array('id' => $id, 'video_id' => 'vid' . $id, 'title' => 'Understanding Cancer Markers', 'tags' => 'cancer,markers', 'description' => 'ORIGINAL DESCRIPTION');
}
