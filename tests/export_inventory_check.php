<?php
/** Offline checks for scripts/export_transcription_inventory.php (no database, no network). Run: php tests/export_inventory_check.php */
require dirname(__DIR__) . '/scripts/export_transcription_inventory.php';
$fail = 0;
function ok($c, $m) { global $fail; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) { $fail++; } }

foreach (array('Full ZOOM call' => 1, 'zoom: weekly' => 1, 'Zoom-call' => 1, 'Q&A (Zoom)' => 1, 'Zoomer tips' => 0, 'Zoomed in' => 0, 'Bazooms' => 0, 'Lecture' => 0) as $t => $e) {
    ok(kx_title_has_zoom($t) === (bool) $e, "zoom word: $t");
}
ok(kx_title_has_qa_hint('Q&A: sleep') && kx_title_has_qa_hint('Q and A') && !kx_title_has_qa_hint('QAnon'), 'qa hint');
ok(kx_redact_source('https://cdn.example.com/a/b.mp4?X-Amz-Signature=abc&token=1') === 'https://cdn.example.com/a/b.mp4', 'signed url stripped');
ok(kx_redact_source('https://u:p@h.com/x.mp4') === 'https://h.com/x.mp4', 'userinfo stripped');
ok(kx_csv_cell('=cmd()') === "'=cmd()", 'csv formula guard');

$root = sys_get_temp_dir() . '/kx_' . getmypid();
@mkdir("$root/upload/videos", 0777, true); @mkdir("$root/upload/temp/transcribe", 0777, true); @mkdir("$root/web", 0777, true);
file_put_contents("$root/upload/videos/a.mp4", str_repeat('x', 2000));
$old = time() - 3600;
file_put_contents("$root/upload/temp/transcribe/audio_2_111.wav", str_repeat('w', 4000)); touch("$root/upload/temp/transcribe/audio_2_111.wav", $old);
file_put_contents("$root/upload/temp/transcribe/audio_3_111.wav", str_repeat('w', 4000)); // fresh: may be a running job
function row($id, $vid, $title, $o = array()) {
    return $o + array('id' => $id, 'video_id' => $vid, 'title' => $title, 'duration' => '10:00', 'size' => 5, 'video_location' => 'upload/videos/a.mp4',
        'privacy' => 0, 'is_movie' => 0, 'is_short' => 0, 'converted' => 1, 'youtube' => '', 'vimeo' => '', 'daily' => '', 'facebook' => '', 'twitch' => '', 'instagram' => '', 'ok' => '', 'transcript_status' => 'completed');
}
$rows = array(
    row(1, 'regular1', 'Cancer diet basics'),
    row(2, 'qa1', 'Q&A clip: ZOOM follow up', array()),
    row(3, 'zoom1', 'Weekly Zoom call', array()),
    row(4, 'priv1', 'Patient recording', array('privacy' => 1)),
    row(5, 'yt1', 'Imported', array('youtube' => 'abc', 'video_location' => '')),
    row(6, 'zoom2', 'zoom Q&A full session'),
    row(7, 'long1', 'Long talk', array('duration' => '03:00:00')),
    row(8, 'priv2', 'Verified QA but private', array('privacy' => 2)),
);
$ctx = array('qa_ids' => array('qa1' => true, 'priv2' => true), 'exclude_ids' => array(), 'max_duration' => 7200);
$out = "$root/out"; mkdir($out);
list($inv, $skp, $recs) = kx_run_inventory($root, $out, $rows, $ctx, array());
$by = array(); foreach ($recs as $r) { $by[$r['video_id']] = $r; }
ok($by['regular1']['eligible'] === 'yes', 'regular eligible');
ok($by['qa1']['eligible'] === 'yes' && $by['qa1']['_rule'] === 'verified_qa_overrides_zoom', 'verified Q&A with ZOOM eligible');
ok($by['zoom1']['_rule'] === 'full_zoom_call' && $by['zoom2']['_rule'] === 'full_zoom_call', 'zoom calls skipped (incl. unverified Q&A-titled)');
ok($by['priv1']['_rule'] === 'private' && $by['priv2']['_rule'] === 'private', 'private skipped, not overridable by Q&A list');
ok($by['yt1']['_rule'] === 'not_self_hosted', 'embed skipped');
ok($by['long1']['_rule'] === 'over_max_duration', 'over max duration');
ok($by['regular1']['source_type'] === 'local_file' && $by['regular1']['source_size_bytes'] === '2000', 'source size');
ok($by['qa1']['saved_audio_available'] === 'yes' && $by['regular1']['saved_audio_available'] === 'no', 'leftover audio found by db id');
$by3 = kx_build_record(row(3, 'zoom1', 'x'), $root, $ctx, array());
ok($by3['saved_audio_available'] === 'no', 'fresh (possibly in-use) wav ignored');
ok(count(file($skp)) === 7, 'skipped csv rows (header + 6)'); // 8 - 2 eligible
list($rep, $res) = kx_run_samples($out, $recs, array('qa1', 'regular1', 'priv1', 'nope'), $ctx);
$s = array(); foreach ($res as $r) { $s[$r['requested']] = $r; }
ok($s['qa1']['status'] === 'copied' && is_file("$out/samples/qa1.wav") && substr(sprintf('%o', fileperms("$out/samples/qa1.wav")), -3) === '600', 'sample copied as <video_id>.wav, 0600');
ok($s['regular1']['status'] === 'audio_not_retained' && $s['regular1']['original_source_ref'] === 'upload/videos/a.mp4', 'not retained -> source reported');
ok($s['priv1']['status'] === 'refused_private' && $s['nope']['status'] === 'not_found', 'private refused, unknown reported');
list($d, $e) = kx_prepare_out_dir("$root/web", "$root/web/exports");
ok($d === null && $e !== null, 'out dir inside web root refused');
$src = file_get_contents(dirname(__DIR__) . '/scripts/export_transcription_inventory.php');
ok(!preg_match('/\b(exec|shell_exec|system|passthru|proc_open|popen)\s*\(|ffmpeg/i', preg_replace('#//.*|/\*.*?\*/#s', '', $src)), 'no external command / ffmpeg calls in code');
exec('rm -rf ' . escapeshellarg($root));
exit($fail ? 1 : 0);
