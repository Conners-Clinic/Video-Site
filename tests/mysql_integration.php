<?php
/**
 * Real-MySQL integration checks (opt-in; NOT part of `php tests/run.php`).
 *
 * Runs against a DISPOSABLE database only. It refuses any host other than 127.0.0.1/localhost and any
 * database whose name does not start with "pt_test", and it makes no network/API calls (OpenAI is mocked).
 *
 *   PT_TEST_DB_PORT=33057 PT_TEST_DB_PASS=ptroot php tests/mysql_integration.php
 *
 * Prerequisite: a database loaded from playtube.sql plus migrations/2026..2032 (see docs/AI_PIPELINE_MODERNIZATION.md).
 * Proves: atomic queue claims across real connections, named locks across connections, interrupted-job recovery
 * without re-running finished passes, and the one-video summary action's data guarantees on real tables.
 */
$host = getenv('PT_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('PT_TEST_DB_PORT') ?: 3306);
$user = getenv('PT_TEST_DB_USER') ?: 'root';
$pass = getenv('PT_TEST_DB_PASS') ?: '';
$name = getenv('PT_TEST_DB_NAME') ?: 'pt_test';
if (!in_array($host, array('127.0.0.1', 'localhost'), true) || strpos($name, 'pt_test') !== 0) {
    fwrite(STDERR, "Refusing to run: only a local database named pt_test* is allowed.\n");
    exit(2);
}

require __DIR__ . '/bootstrap.php';
require $GLOBALS['REPO'] . '/assets/libs/DB/vendor/joshcam/mysqli-database-class/MysqliDb.php';

function real_db() {
    global $host, $port, $user, $pass, $name;
    return new MysqliDb(array('host' => $host, 'username' => $user, 'password' => $pass, 'db' => $name, 'port' => $port, 'charset' => 'utf8mb4'));
}

// ---------------------------------------------------------------- child processes (separate DB connections)
if (isset($argv[1]) && $argv[1] === 'child') {
    reset_world();
    $db = real_db();
    $mode = $argv[2];
    if ($mode === 'claim') {          // child claim <ids csv> <start microtime>
        while (microtime(true) < (float) $argv[4]) { /* spin until the shared start time */ }
        $won = array();
        foreach (explode(',', $argv[3]) as $id) {
            if (PT_AiClaimQueueRow(T_SEO_PIPELINE_QUEUE, (int) $id)) { $won[] = (int) $id; }
        }
        echo json_encode($won);
    } elseif ($mode === 'lock') {     // child lock <name> <hold seconds>
        $got = PT_AiAcquireLock($argv[3]);
        echo json_encode(array('got' => $got));
        if ($got && (int) $argv[4] > 0) { sleep((int) $argv[4]); } // exit without RELEASE_LOCK = simulated crash
    }
    exit(0);
}

$PASS = 0; $FAIL = array();
function chk($cond, $msg) { global $PASS, $FAIL; if ($cond) { $PASS++; echo "  ok   $msg\n"; } else { $FAIL[] = $msg; echo "  FAIL $msg\n"; } }
function child_run($args) {
    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d error_reporting=0 ' . escapeshellarg(__FILE__) . ' child ' . $args;
    $p = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    return array($p, $pipes);
}
function child_out($h) { $o = stream_get_contents($h[1][1]); stream_get_contents($h[1][2]); proc_close($h[0]); return $o; }

reset_world();
$db = real_db();
$v = $db->rawQuery('SELECT VERSION() AS v');
$ver = is_array($v[0]) ? $v[0]['v'] : $v[0]->v;
echo "Connected to MySQL/MariaDB $ver (database $name)\n";

// Clean slate for the rows this script owns.
foreach (array(T_SEO_PIPELINE_QUEUE, T_SEO_PIPELINE_LOGS, T_VIDEO_SEO_ARTICLES, T_VIDEO_TRANSCRIPTS, T_TRANSCRIPT_QUEUE) as $t) { $db->rawQuery("DELETE FROM `$t`"); }
$db->rawQuery("DELETE FROM `videos` WHERE id >= 900000");

echo "\n[1] Migration 2032 is applied\n";
foreach (array(array(T_SEO_PIPELINE_LOGS, 'cost_usd'), array(T_SEO_PIPELINE_LOGS, 'finish_reason'), array(T_SEO_PIPELINE_QUEUE, 'claimed_at'), array(T_VIDEO_TRANSCRIPTS, 'description_backup')) as $c) {
    chk(PT_AiColumnExists($c[0], $c[1]), "column $c[0].$c[1] exists");
}

echo "\n[2] Eight real connections race to claim the same 25 queue rows\n";
$ids = array();
for ($i = 1; $i <= 25; $i++) { $ids[] = $db->insert(T_SEO_PIPELINE_QUEUE, array('video_id' => 900000 + $i, 'processing' => 0, 'created_at' => time())); }
$start = microtime(true) + 2.0;
$hs = array();
for ($w = 0; $w < 8; $w++) { $hs[] = child_run('claim ' . implode(',', $ids) . ' ' . $start); }
$wins = array();
foreach ($hs as $h) { $r = json_decode(child_out($h), true); foreach ((array) $r as $id) { $wins[$id] = (isset($wins[$id]) ? $wins[$id] : 0) + 1; } }
chk(count($wins) === 25, 'every row was claimed by someone (' . count($wins) . '/25)');
chk(max($wins ?: array(0)) === 1, 'no row was claimed by two workers (max winners per row = ' . max($wins ?: array(0)) . ')');
$claimed = $db->rawQuery('SELECT COUNT(*) AS n FROM `' . T_SEO_PIPELINE_QUEUE . '` WHERE processing = 1 AND claimed_at > 0');
chk((int) (is_array($claimed[0]) ? $claimed[0]['n'] : $claimed[0]->n) === 25, 'all 25 rows are processing=1 with claimed_at set');
chk(PT_AiClaimQueueRow(T_SEO_PIPELINE_QUEUE, $ids[0]) === false, 'a ninth, later claim of an already-claimed row loses');
$db->rawQuery('DELETE FROM `' . T_SEO_PIPELINE_QUEUE . '`');

echo "\n[3] Named locks between connections (cron vs admin click)\n";
chk(PT_AiAcquireLock('seo_pipeline_900001') === true, 'parent takes the per-video lock');
$h = child_run('lock seo_pipeline_900001 0');
chk(strpos(child_out($h), '"got":false') !== false, 'second connection is refused while the first holds it');
PT_AiReleaseLock('seo_pipeline_900001');
$h = child_run('lock seo_pipeline_900001 0');
chk(strpos(child_out($h), '"got":true') !== false, 'second connection gets it after release');
$h = child_run('lock seo_pipeline_900002 3'); // takes the lock, then "crashes" without releasing
usleep(1200000);
chk(PT_AiAcquireLock('seo_pipeline_900002') === false, 'lock is held by the live worker');
child_out($h);                                // worker process ends -> connection closes -> MySQL frees the lock
chk(PT_AiAcquireLock('seo_pipeline_900002') === true, 'a crashed worker\'s lock is freed automatically when its connection dies');
PT_AiReleaseLock('seo_pipeline_900002');

echo "\n[4] Interrupted job recovers without re-running finished passes\n";
$vid = 900010;
$db->insert('videos', array('id' => $vid, 'video_id' => 'ptvid10', 'title' => 'Recovery test', 'description' => 'ORIGINAL DESCRIPTION', 'tags' => ''));
$db->insert(T_VIDEO_TRANSCRIPTS, array('video_id' => $vid, 'status' => 'completed', 'plain_text' => make_transcript(300), 'created_at' => time(), 'updated_at' => time()));
$db->insert(T_VIDEO_SEO_ARTICLES, array('video_id' => $vid, 'status' => 'pass_2_running', 'raw_transcript' => 'RAW', 'cleaned_transcript' => 'CLEANED-KEEP', 'entity_extraction_output' => '{"entities":[]}', 'entity_comparison_output' => '{"x":1}', 'seo_blueprint' => 'BLUEPRINT-KEEP', 'updated_at' => time() - 7200, 'created_at' => time() - 9000));
$qid = $db->insert(T_SEO_PIPELINE_QUEUE, array('video_id' => $vid, 'processing' => 1, 'claimed_at' => time() - 7200, 'created_at' => time() - 9000));
chk(PT_SeoResetStaleQueueJobs(2700) === 1, 'stale claimed job (2h old) is put back');
$row = $db->arraybuilder()->where('id', $qid)->getOne(T_SEO_PIPELINE_QUEUE);
chk((int) $row['processing'] === 0, 'queue row is processing=0 again');
mock_api(function ($p) {
    $u = user_text($p);
    if (strpos($u, 'content auditor') !== false) { return api_ok('{"score":90}'); }
    if (strpos($u, 'elite SEO content writer') !== false) { return api_ok("SEO Title: Draft\nMeta Description: d\n\n# Draft"); }
    return api_ok("SEO Title: Final\n\n# Final article");
});
PT_ProcessSeoPipelineQueueJob((object) $row);
$a = PT_GetVideoSeoArticle($vid);
chk($a->status === 'complete', 'job finished (status ' . $a->status . ', error "' . $a->error_message . '")');
chk($a->cleaned_transcript === 'CLEANED-KEEP' && $a->seo_blueprint === 'BLUEPRINT-KEEP', 'pass 0 and pass 1 outputs were kept untouched');
$kinds = array();
foreach ($GLOBALS['CALLS'] as $c) { $kinds[] = strpos(call_text($c), 'RAW TRANSCRIPT:') !== false ? 'pass_0' : (strpos(call_text($c), 'SEO strategist') !== false ? 'pass_1' : 'later'); }
chk(!in_array('pass_0', $kinds, true) && !in_array('pass_1', $kinds, true), 'model was never called for pass 0 or pass 1 (' . count($kinds) . ' calls: pass 2,3,4 only)');
chk(count($GLOBALS['CALLS']) === 3, 'exactly 3 calls (draft, audit, final); got ' . count($GLOBALS['CALLS']));
$left = $db->rawQuery('SELECT COUNT(*) AS n FROM `' . T_SEO_PIPELINE_QUEUE . '`');
chk((int) (is_array($left[0]) ? $left[0]['n'] : $left[0]->n) === 0, 'queue row removed after completion');
$logs = $db->rawQuery('SELECT COUNT(*) AS n, SUM(cost_usd) AS c FROM `' . T_SEO_PIPELINE_LOGS . '` WHERE video_id = ' . $vid);
$l = is_array($logs[0]) ? $logs[0] : (array) $logs[0];
chk((int) $l['n'] === 3, 'cost/usage log rows written to the new columns (3 rows)');

echo "\n[5] One-video summary action on real tables (transcription off)\n";
$vid = 900020;
$vtt = $GLOBALS['TMP_ROOT'] . 'sample.vtt';
file_put_contents($vtt, "WEBVTT\n\n00:00.000 --> 00:02.000\nOriginal caption\n");
$plain = make_transcript(2500) . ' FINAL-FACT: the end-of-video point is the 14-day protocol.';
$db->insert('videos', array('id' => $vid, 'video_id' => 'ptvid20', 'title' => 'Summary test', 'description' => 'ORIGINAL DESCRIPTION', 'tags' => ''));
$db->insert(T_VIDEO_TRANSCRIPTS, array('video_id' => $vid, 'status' => 'completed', 'plain_text' => $plain, 'vtt_path' => $vtt, 'seo_summary' => 'OLD SUMMARY', 'key_takeaways' => json_encode(array('Old takeaway 1', 'Old takeaway 2')), 'created_at' => time(), 'updated_at' => time()));
$db->insert(T_VIDEO_SEO_ARTICLES, array('video_id' => $vid, 'status' => 'complete', 'raw_transcript' => 'RAW-ARTICLE-SOURCE', 'final_article_markdown' => 'ARTICLE-KEEP', 'updated_at' => time()));
$db->rawQuery("UPDATE config SET value='off' WHERE name='transcript_system'");
chk(strlen($plain) > 6000, 'transcript is longer than the old 6,000-character cut-off (' . strlen($plain) . ' chars)');
$snap = function () use ($vid) {
    global $db;
    $v = $db->arraybuilder()->where('id', $vid)->getOne('videos');
    $t = $db->arraybuilder()->where('video_id', $vid)->getOne(T_VIDEO_TRANSCRIPTS);
    $s = $db->arraybuilder()->where('video_id', $vid)->getOne(T_VIDEO_SEO_ARTICLES);
    return array('desc' => $v['description'], 'plain' => $t['plain_text'], 'vtt_path' => $t['vtt_path'], 'vtt_file' => md5_file($GLOBALS['TMP_ROOT'] . 'sample.vtt'),
        'raw' => $s['raw_transcript'], 'article' => $s['final_article_markdown'], 'article_status' => $s['status'], 'summary' => $t['seo_summary'], 'takeaways' => $t['key_takeaways']);
};
$before = $snap();
$good = json_encode(array('summary' => 'NEW SUMMARY covering the 14-day protocol at the end.', 'key_takeaways' => array('New takeaway A', 'New takeaway B incl. 14-day protocol')));

// 5a failure: API error
mock_api(function () { return api_http(400, 'invalid request'); });
$r = PT_RegenerateSeoSummaryForVideo($vid, array('force' => true));
$after = $snap();
chk(empty($r['ok']), 'failed model call reports failure');
chk($after['summary'] === 'OLD SUMMARY' && $after['takeaways'] === $before['takeaways'], '5a: API error leaves old About + Key Takeaways intact');
// 5b failure: malformed JSON, 5c truncated
mock_api(function () { return api_ok('not json at all'); });
$r = PT_RegenerateSeoSummaryForVideo($vid, array('force' => true));
chk(empty($r['ok']) && $snap()['summary'] === 'OLD SUMMARY', '5b: malformed model output leaves old summary intact');
mock_api(function () use ($good) { return api_ok($good, array('finish' => 'length')); });
$r = PT_RegenerateSeoSummaryForVideo($vid, array('force' => true));
chk(empty($r['ok']) && $snap()['summary'] === 'OLD SUMMARY', '5c: truncated model output leaves old summary intact');
// 5d refuses to re-bill without force
mock_api(function () use ($good) { return api_ok($good); });
$r = PT_RegenerateSeoSummaryForVideo($vid, array());
chk(!empty($r['skipped']) && count($GLOBALS['CALLS']) === 0, '5d: without "replace existing" it refuses and makes 0 API calls');
// 5e success
mock_api(function () use ($good) { return api_ok($good, array('prompt' => 3000, 'completion' => 150)); });
$r = PT_RegenerateSeoSummaryForVideo($vid, array('force' => true));
$after = $snap();
chk(!empty($r['ok']) && $r['mode'] === 'single' && count($GLOBALS['CALLS']) === 1, '5e: success in one call (mode ' . ($r['mode'] ?? '?') . ', calls ' . count($GLOBALS['CALLS']) . ')');
chk(strpos(call_text($GLOBALS['CALLS'][0]), 'FINAL-FACT: the end-of-video point') !== false, '5e: the prompt contained the END of the stored transcript');
chk(strpos(call_text($GLOBALS['CALLS'][0]), 'POINT-0.') !== false, '5e: ...and the start');
chk(strpos($after['summary'], 'NEW SUMMARY') === 0, '5e: new About text saved');
$tk = json_decode($after['takeaways'], true);
chk(is_array($tk) && $tk[0] === 'New takeaway A', '5e: new Key Takeaways saved into the real JSON column');
foreach (array('desc', 'plain', 'vtt_path', 'vtt_file', 'raw', 'article', 'article_status') as $k) {
    chk($after[$k] === $before[$k], "5e: $k unchanged");
}
$dbk = $db->arraybuilder()->where('video_id', $vid)->getOne(T_VIDEO_TRANSCRIPTS);
chk($dbk['description_backup'] === null, '5e: description_backup untouched (description never rewritten)');
$ts = $db->rawQuery("SELECT value FROM config WHERE name='transcript_system'");
chk((is_array($ts[0]) ? $ts[0]['value'] : $ts[0]->value) === 'off', '5e: transcript_system is still off');
$q = $db->rawQuery('SELECT COUNT(*) AS n FROM `' . T_TRANSCRIPT_QUEUE . '`');
chk((int) (is_array($q[0]) ? $q[0]['n'] : $q[0]->n) === 0, '5e: nothing was added to the transcription queue (no Whisper)');

// clean up what we created
$db->rawQuery('DELETE FROM `videos` WHERE id >= 900000');
foreach (array(T_SEO_PIPELINE_QUEUE, T_SEO_PIPELINE_LOGS, T_VIDEO_SEO_ARTICLES, T_VIDEO_TRANSCRIPTS) as $t) { $db->rawQuery("DELETE FROM `$t`"); }

echo "\n" . $PASS . ' checks passed, ' . count($FAIL) . " failed\n";
foreach ($FAIL as $f) { echo " - $f\n"; }
exit(count($FAIL) ? 1 : 0);
