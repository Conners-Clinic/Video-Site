<?php
// CLI ONLY. Reject web requests before anything else loads (this file sits inside the web root).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Read-only exporter for the Kodara transcription backfill.
 *
 * Default run : writes an inventory CSV + a skipped-items CSV to a private directory outside the web root.
 * Sample run  : --sample-ids=<video_id>[,<video_id>] copies already-saved audio (if any) named <video_id>.<ext>,
 *               otherwise reports the original media source. It never runs FFmpeg (or any external command).
 *
 * Safety: only SELECT statements, inside a READ ONLY transaction. Does not load assets/init.php (it starts a session,
 * sets cookies, can redirect/exit and writes to the database). Uses the same config.php + mysqli connection that
 * assets/includes/app_start.php uses. Never prints or exports credentials, signed URLs, or whole tables.
 *
 * Usage: php scripts/export_transcription_inventory.php --help
 */

const KX_EMBED_FIELDS = array('youtube', 'vimeo', 'daily', 'facebook', 'twitch', 'instagram', 'ok');
const KX_AUDIO_EXTS = array('wav', 'mp3', 'm4a', 'aac', 'flac', 'ogg', 'opus');
const KX_MAX_SAMPLES = 10;
const KX_CSV_COLUMNS = array(
    'video_id', 'db_id', 'title', 'duration', 'duration_seconds', 'source_type', 'source_ref', 'source_exists',
    'source_size_bytes', 'db_size_bytes', 'saved_audio_available', 'saved_audio_location', 'saved_audio_file',
    'saved_audio_size_bytes',
    'cached_wav_key', 'cached_wav_size_bytes', 'cached_wav_copies', 'cached_wav_check',
    'cached_mp4_key', 'cached_mp4_size_bytes', 'cached_mp4_copies', 'cached_mp4_check', 'temp_copy_flag',
    'transcript_status', 'privacy', 'zoom_word_in_title', 'qa_verified', 'qa_title_hint', 'review_flag',
    'eligible', 'reason',
);

function kx_usage() {
    return <<<TXT
Read-only Kodara transcription backfill exporter (CLI only).

  php scripts/export_transcription_inventory.php [options]

Options:
  --out-dir=DIR          Private output directory (must be OUTSIDE the web root).
                         Default: <parent of web root>/kodara-export
  --qa-ids-file=FILE     Optional: verified Q&A clip video_ids, one per line, only to fill the qa_verified
                         column. It does not change eligibility (any title with ZOOM is excluded).
  --exclude-ids-file=FILE  Extra video_ids to treat as private patient recordings (always skipped).
  --all-channels         Include every channel (default: the transcript channels from site config).
  --audio-dir=DIR        Extra directory to look in for saved audio named <video_id>.<ext> (repeatable).
  --audio-manifest=FILE  Listing of audio kept in object storage (R2), e.g. from
                         rclone lsf --format "pst" r2:videos/upload/temp/transcribe/ > FILE
                         (also accepts `aws s3 ls` output). Reads audio_<db id>_<unix ts>.wav and
                         video_<db id>_<unix ts>.mp4 temp copies. All are reported as UNVERIFIED temp
                         copies (separate CSV). Listing only: nothing is downloaded, no URLs exported.
  --r2-remote=REMOTE     rclone remote:bucket used in the retrieve hints (default r2:videos).
  --sample-ids=ID[,ID]   Sample mode (max 10): export saved audio for these video_ids (or numeric db ids)
                         as <video_id>.<ext>; if no audio is retained, report the original media source.
  --help                 Show this help.

TXT;
}

function kx_parse_args(array $argv) {
    $o = array('out_dir' => null, 'qa_file' => null, 'exclude_file' => null, 'all_channels' => false,
        'audio_dirs' => array(), 'manifest' => null, 'r2_remote' => 'r2:videos', 'sample_ids' => array(), 'help' => false, 'errors' => array());
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') { $o['help'] = true; continue; }
        if ($arg === '--all-channels') { $o['all_channels'] = true; continue; }
        if (!preg_match('/^--([a-z-]+)=(.*)$/s', $arg, $m)) { $o['errors'][] = "Unknown argument: $arg"; continue; }
        list(, $k, $v) = $m;
        switch ($k) {
            case 'out-dir': $o['out_dir'] = $v; break;
            case 'qa-ids-file': $o['qa_file'] = $v; break;
            case 'exclude-ids-file': $o['exclude_file'] = $v; break;
            case 'audio-dir': $o['audio_dirs'][] = $v; break;
            case 'audio-manifest': $o['manifest'] = $v; break;
            case 'r2-remote':
                if (!preg_match('/^[A-Za-z0-9_.-]+:[A-Za-z0-9._-]+$/', $v)) { $o['errors'][] = 'Bad --r2-remote (expected remote:bucket).'; break; }
                $o['r2_remote'] = $v; break;
            case 'sample-ids':
                foreach (explode(',', $v) as $id) {
                    $id = trim($id);
                    if ($id === '') { continue; }
                    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) { $o['errors'][] = 'Bad sample id.'; continue; }
                    $o['sample_ids'][$id] = $id;
                }
                $o['sample_ids'] = array_values($o['sample_ids']);
                break;
            default: $o['errors'][] = "Unknown option: --$k";
        }
    }
    if (count($o['sample_ids']) > KX_MAX_SAMPLES) { $o['errors'][] = 'At most ' . KX_MAX_SAMPLES . ' sample ids.'; }
    return $o;
}

function kx_read_id_file($path) {
    $ids = array();
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) { return false; }
    foreach ($lines as $line) {
        $line = trim(preg_replace('/#.*$/', '', $line));
        if ($line !== '') { $ids[$line] = true; }
    }
    return $ids;
}

/** ZOOM as a whole word, case-insensitive: "Zoom call" yes, "Zoomed"/"Zoomer" no. */
function kx_title_has_zoom($title) {
    return (bool) preg_match('/(?<![\p{L}\p{N}_])zoom(?![\p{L}\p{N}_])/iu', (string) $title);
}

/** Hint only (never changes eligibility): title looks like a Q&A clip. */
function kx_title_has_qa_hint($title) {
    return (bool) preg_match('/(?<![\p{L}\p{N}])Q\s*(?:&|&amp;|and|\/)\s*A(?![\p{L}\p{N}])/iu', (string) $title);
}

/** Review hint only: title looks patient-related. Not an exclusion rule. */
function kx_title_patient_hint($title) {
    return (bool) preg_match('/(?<![\p{L}\p{N}])(patient|consult(ation)?|intake|follow[- ]?up|private)(?![\p{L}\p{N}])/iu', (string) $title);
}

function kx_duration_seconds($input) {
    $input = trim((string) $input);
    $parts = explode(':', $input);
    if (count($parts) < 2 || count($parts) > 3) { return 0; }
    foreach ($parts as $p) { if ($p === '' || !ctype_digit($p)) { return 0; } }
    $p = array_map('intval', $parts);
    if (count($p) === 2) { return $p[1] >= 60 ? 0 : $p[0] * 60 + $p[1]; }
    return ($p[1] >= 60 || $p[2] >= 60) ? 0 : $p[0] * 3600 + $p[1] * 60 + $p[2];
}

/** Storage reference without credentials or signed-URL query strings. */
function kx_redact_source($location) {
    $location = trim((string) $location);
    if ($location === '' || preg_match('/[\x00-\x1F\x7F]/', $location)) { return ''; }
    if (preg_match('#^https?://#i', $location) || preg_match('#^https?://#i', urldecode($location))) {
        $u = parse_url($location);
        if (!$u || empty($u['host'])) { return '(remote url)'; }
        return $u['scheme'] . '://' . $u['host'] . (isset($u['path']) ? $u['path'] : '');
    }
    return $location;
}

function kx_source_info(array $row, $root) {
    foreach (KX_EMBED_FIELDS as $f) {
        if (!empty($row[$f])) {
            return array('type' => 'embed', 'ref' => $f . ':' . $row[$f], 'exists' => '', 'size' => '');
        }
    }
    $loc = trim((string) ($row['video_location'] ?? ''));
    if ($loc === '') { return array('type' => 'none', 'ref' => '', 'exists' => '', 'size' => ''); }
    if (preg_match('#^https?://#i', $loc) || preg_match('#^https?://#i', urldecode($loc))) {
        return array('type' => 'remote_url', 'ref' => kx_redact_source($loc), 'exists' => '', 'size' => '');
    }
    $ref = kx_redact_source($loc);
    $path = $root . '/' . $loc;
    $real = (strpos($loc, '..') === false) ? realpath($path) : false;
    if ($real !== false && kx_inside($real, $root . '/upload') && is_file($real)) {
        return array('type' => 'local_file', 'ref' => $ref, 'exists' => 'yes', 'size' => (string) filesize($real));
    }
    // Not on this server's disk: may live in remote object storage (the site's S3 option) or be missing.
    return array('type' => 'local_path_not_on_disk', 'ref' => $ref, 'exists' => 'no', 'size' => '');
}

function kx_inside($path, $dir) {
    $d = realpath($dir);
    $p = realpath($path);
    if ($d === false || $p === false) { return false; }
    return $p === $d || strpos($p, rtrim($d, '/') . '/') === 0;
}

/**
 * Parse an object-storage listing of the worker's temp copies:
 *   audio_<db id>_<unix ts>.wav   (extracted audio)     video_<db id>_<unix ts>.mp4   (downloaded original)
 * Returns db id => array('wav' => best|null, 'mp4' => best|null, 'objects' => every copy). "Best" = largest,
 * then newest. Anything else in the listing (folders, index.php, ...) is ignored.
 */
function kx_load_manifest($path, $prefix = 'upload/temp/transcribe/') {
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) { return false; }
    $out = array();
    foreach ($lines as $line) {
        // rclone "pst": path;size;time   |   aws s3 ls: "2026-11-14 10:58:42  102799462 audio_1_2.wav"
        if (!preg_match('/(?:^|[\s\/;])((audio)_(\d+)_(\d+)\.wav|(video)_(\d+)_(\d+)\.mp4)(?:;(\d+)|\s*$)/', $line, $m)) { continue; }
        $kind = $m[2] !== '' ? 'wav' : 'mp4';
        $id = (int) ($kind === 'wav' ? $m[3] : $m[6]);
        $ts = (int) ($kind === 'wav' ? $m[4] : $m[7]);
        $size = isset($m[8]) && $m[8] !== '' ? (int) $m[8] : (preg_match('/^\S+\s+\S+\s+(\d+)\s/', $line, $n) ? (int) $n[1] : 0);
        $cand = array('kind' => $kind, 'key' => $prefix . $m[1], 'size' => $size, 'ts' => $ts, 'copies' => 1);
        if (!isset($out[$id])) { $out[$id] = array('wav' => null, 'mp4' => null, 'objects' => array()); }
        $out[$id]['objects'][] = $cand;
        $cur = $out[$id][$kind];
        if ($cur === null) { $out[$id][$kind] = $cand; continue; }
        $copies = $cur['copies'] + 1;
        if ($cand['size'] > $cur['size'] || ($cand['size'] === $cur['size'] && $cand['ts'] > $cur['ts'])) { $cur = $cand; }
        $cur['copies'] = $copies;
        $out[$id][$kind] = $cur;
    }
    return $out;
}

/** Plausibility only (not verification): 16 kHz mono 16-bit WAV is 32000 bytes per second plus a 44-byte header. */
function kx_wav_check($size, $secs) {
    if ($size < 1048576) { return 'under_1MB'; }
    if ($secs <= 0) { return 'duration_unknown'; }
    $exp = $secs * 32000 + 44;
    if (abs($size - $exp) / $exp <= 0.03) { return 'matches_duration'; }
    return $size < $exp ? 'shorter_than_duration' : 'longer_than_duration';
}

function kx_mp4_check($size, $original_size) {
    if ($size < 1048576) { return 'under_1MB'; }
    if ($original_size <= 0) { return 'original_size_unknown'; }
    return $size === (int) $original_size ? 'matches_original_size' : 'differs_from_original_size';
}

/**
 * Saved audio: the live worker deletes its temp WAV when a job ends, so this finds only leftovers
 * (audio_<db id>_<ts>.wav in upload/temp/transcribe) or files in --audio-dir named <video_id>.<ext>.
 * Files modified in the last 5 minutes are skipped: they may belong to a running job.
 */
function kx_find_audio($root, $db_id, $video_id, array $audio_dirs) {
    $found = array();
    $tmp = $root . '/upload/temp/transcribe';
    if (is_dir($tmp)) {
        foreach ((array) glob($tmp . '/audio_' . (int) $db_id . '_*.wav') as $f) {
            if (preg_match('/^audio_' . (int) $db_id . '_\d+\.wav$/', basename($f))) { $found[] = $f; }
        }
    }
    foreach ($audio_dirs as $dir) {
        foreach (KX_AUDIO_EXTS as $ext) {
            $f = rtrim($dir, '/') . '/' . $video_id . '.' . $ext;
            if (is_file($f)) { $found[] = $f; }
        }
    }
    $best = null;
    foreach ($found as $f) {
        if (is_link($f) || filesize($f) < 1024 || time() - filemtime($f) < 300) { continue; }
        if ($best === null || filemtime($f) > filemtime($best)) { $best = $f; }
    }
    return $best;
}

/**
 * Classification. Order matters: private/excluded always wins and can never be overridden.
 * Returns array(eligible bool, rule string, reason string).
 */
function kx_classify(array $row, array $ctx) {
    $vid = (string) $row['video_id'];
    $title = (string) $row['title'];
    $qa = isset($ctx['qa_ids'][$vid]);
    if ((int) $row['privacy'] !== 0 || isset($ctx['exclude_ids'][$vid])) {
        return array(false, 'private', isset($ctx['exclude_ids'][$vid]) ? 'On the private/patient exclude list' : 'Not public (privacy=' . (int) $row['privacy'] . '): treated as a private recording');
    }
    if (!$ctx['source']['type'] || in_array($ctx['source']['type'], array('embed', 'remote_url', 'none'), true) || (int) $row['converted'] === 2) {
        $why = (int) $row['converted'] === 2 ? 'converted=2' : $ctx['source']['type'];
        return array(false, 'not_self_hosted', "No self-hosted upload to transcribe ($why)");
    }
    if ((int) $row['is_movie'] === 1 || (int) $row['is_short'] === 1) {
        return array(false, 'movie_or_short', 'Movie or short (excluded by the existing backfill rules)');
    }
    if (kx_title_has_zoom($title)) {
        return array(false, 'full_zoom_call', 'Title contains the whole word ZOOM (all ZOOM titles are excluded)');
    }
    return array(true, $qa ? 'verified_qa' : 'regular', $qa ? 'Verified individual Q&A clip' : 'Self-hosted upload, no ZOOM in title');
}

function kx_build_record(array $row, $root, array $ctx, array $audio_dirs) {
    $ctx['source'] = $src = kx_source_info($row, $root);
    list($eligible, $rule, $reason) = kx_classify($row, $ctx);
    $vid = (string) $row['video_id'];
    $audio = kx_find_audio($root, $row['id'], $vid, $audio_dirs);
    $tmp = isset($ctx['manifest'][(int) $row['id']]) ? $ctx['manifest'][(int) $row['id']] : array('wav' => null, 'mp4' => null);
    $wav = $tmp['wav'];
    $mp4 = $tmp['mp4'];
    $secs = kx_duration_seconds($row['duration']);
    $wav_check = $wav ? kx_wav_check($wav['size'], $secs) : '';
    $mp4_check = $mp4 ? kx_mp4_check($mp4['size'], $src['size'] !== '' ? (int) $src['size'] : (int) $row['size']) : '';
    $flags = array();
    if ($wav || $mp4) { $flags[] = 'unverified_temp_copy'; }
    if ($wav && $wav_check !== 'matches_duration') { $flags[] = 'wav_' . $wav_check; }
    $review = array();
    if (kx_title_patient_hint($row['title'])) { $review[] = 'title_looks_patient_related'; }
    $max = (int) $ctx['max_duration'];
    if ($max > 0 && $secs > $max) { $review[] = 'over_worker_max_duration_' . $max . 's'; }
    return array(
        'video_id' => $vid,
        'db_id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'duration' => (string) $row['duration'],
        'duration_seconds' => kx_duration_seconds($row['duration']),
        'source_type' => $src['type'],
        'source_ref' => $src['ref'],
        'source_exists' => $src['exists'],
        'source_size_bytes' => $src['size'],
        'db_size_bytes' => (int) $row['size'],
        'saved_audio_available' => $audio ? 'yes' : 'no',
        'saved_audio_location' => $audio ? 'server_disk' : 'none',
        'saved_audio_file' => $audio ? basename($audio) : '',
        'saved_audio_size_bytes' => $audio ? filesize($audio) : '',
        'cached_wav_key' => $wav ? $wav['key'] : '',
        'cached_wav_size_bytes' => $wav ? $wav['size'] : '',
        'cached_wav_copies' => $wav ? $wav['copies'] : '',
        'cached_wav_check' => $wav_check,
        'cached_mp4_key' => $mp4 ? $mp4['key'] : '',
        'cached_mp4_size_bytes' => $mp4 ? $mp4['size'] : '',
        'cached_mp4_copies' => $mp4 ? $mp4['copies'] : '',
        'cached_mp4_check' => $mp4_check,
        'temp_copy_flag' => implode(';', $flags),
        'transcript_status' => (string) ($row['transcript_status'] ?? ''),
        'privacy' => (int) $row['privacy'],
        'zoom_word_in_title' => kx_title_has_zoom($row['title']) ? 'yes' : 'no',
        'qa_verified' => isset($ctx['qa_ids'][$vid]) ? 'yes' : 'no',
        'qa_title_hint' => kx_title_has_qa_hint($row['title']) ? 'yes' : 'no',
        'review_flag' => implode(';', $review),
        'eligible' => $eligible ? 'yes' : 'no',
        'reason' => $reason,
        '_rule' => $rule,
        '_audio_path' => $audio,
        '_cached_wav_key' => $wav ? $wav['key'] : '',
        '_cached_mp4_key' => $mp4 ? $mp4['key'] : '',
    );
}

/** Neutralise spreadsheet formula injection from titles. */
function kx_csv_cell($v) {
    $v = (string) $v;
    return (isset($v[0]) && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}

function kx_write_csv($path, array $columns, array $records) {
    $tmp = $path . '.part';
    $fh = fopen($tmp, 'wb');
    if (!$fh) { return false; }
    fputcsv($fh, $columns, ',', '"', '\\');
    foreach ($records as $r) {
        $line = array();
        foreach ($columns as $c) { $line[] = kx_csv_cell($r[$c] ?? ''); }
        fputcsv($fh, $line, ',', '"', '\\');
    }
    fclose($fh);
    chmod($tmp, 0600);
    return rename($tmp, $path);
}

function kx_prepare_out_dir($root, $requested) {
    $dir = $requested !== null ? $requested : dirname($root) . '/kodara-export';
    umask(077);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) { return array(null, 'Cannot create output directory.'); }
    $real = realpath($dir);
    if ($real === false || kx_inside($real, $root)) {
        return array(null, 'Output directory must be OUTSIDE the web root (' . $root . '). Use --out-dir.');
    }
    @chmod($real, 0700);
    return array($real, null);
}

// ---------------------------------------------------------------- database (SELECT only)

function kx_connect($root) {
    $sql_db_host = $sql_db_user = $sql_db_pass = $sql_db_name = null;
    ob_start();
    require $root . '/config.php';   // same variables app_start.php consumes
    ob_end_clean();
    mysqli_report(MYSQLI_REPORT_OFF);
    $m = @new mysqli($sql_db_host, $sql_db_user, $sql_db_pass, $sql_db_name);
    unset($sql_db_host, $sql_db_user, $sql_db_pass, $sql_db_name);
    if ($m->connect_errno) { fwrite(STDERR, 'Database connection failed (errno ' . (int) $m->connect_errno . ").\n"); exit(1); }
    $m->set_charset('utf8mb4');
    if (!$m->query('SET SESSION TRANSACTION READ ONLY') || !$m->query('START TRANSACTION READ ONLY')) {
        fwrite(STDERR, "Could not open a READ ONLY transaction; refusing to continue.\n");
        exit(1);
    }
    return $m;
}

function kx_select(mysqli $m, $sql) {
    if (!preg_match('/^\s*SELECT\s/i', $sql)) { throw new RuntimeException('Only SELECT is allowed.'); }
    $res = $m->query($sql);
    if (!$res) { fwrite(STDERR, 'Query failed (errno ' . (int) $m->errno . ").\n"); exit(1); }
    $rows = array();
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    $res->free();
    return $rows;
}

function kx_load_context(mysqli $m, $opts) {
    $cfg = array();
    foreach (kx_select($m, "SELECT `name`, `value` FROM `config` WHERE `name` IN ('transcript_max_duration','transcript_channel_usernames')") as $r) {
        $cfg[$r['name']] = $r['value'];
    }
    $max = isset($cfg['transcript_max_duration']) && $cfg['transcript_max_duration'] !== '' ? (int) $cfg['transcript_max_duration'] : 7200;
    $channel_ids = null;
    if (!$opts['all_channels']) {
        $names = array_filter(array_map('trim', explode(',', !empty($cfg['transcript_channel_usernames']) ? $cfg['transcript_channel_usernames'] : 'DrKevinConners,ConnersClinic')));
        $quoted = array();
        foreach ($names as $n) { $quoted[] = "'" . $m->real_escape_string(strtolower($n)) . "'"; }
        $channel_ids = array(0);
        if ($quoted) {
            foreach (kx_select($m, 'SELECT `id` FROM `users` WHERE LOWER(`username`) IN (' . implode(',', $quoted) . ')') as $r) { $channel_ids[] = (int) $r['id']; }
        }
    }
    return array($max, $channel_ids);
}

function kx_video_select($where) {
    // Explicit column list: no descriptions, no user data, no tokens.
    return 'SELECT v.id, v.video_id, v.title, v.duration, v.size, v.video_location, v.privacy, v.is_movie, v.is_short, v.converted, '
        . 'v.youtube, v.vimeo, v.daily, v.facebook, v.twitch, v.instagram, v.ok, t.status AS transcript_status '
        . 'FROM `videos` v LEFT JOIN `video_transcripts` t ON t.video_id = v.id WHERE ' . $where . ' ORDER BY v.id ASC';
}

// ---------------------------------------------------------------- modes

const KX_TEMP_COLUMNS = array('video_id', 'db_id', 'in_inventory', 'eligible', 'kind', 'key', 'size_bytes', 'created_utc_from_name',
    'is_best_copy', 'copies_of_kind', 'check', 'flag');

/** One row per temp object in the listing, kept apart from the inventory. Includes ids not in the inventory. */
function kx_temp_copy_rows(array $manifest, array $records) {
    $by = array();
    foreach ($records as $r) { $by[$r['db_id']] = $r; }
    $rows = array();
    ksort($manifest);
    foreach ($manifest as $id => $m) {
        $r = isset($by[$id]) ? $by[$id] : null;
        foreach ($m['objects'] as $o) {
            $best = $m[$o['kind']];
            $check = $r ? ($o['kind'] === 'wav' ? kx_wav_check($o['size'], $r['duration_seconds'])
                : kx_mp4_check($o['size'], $r['source_size_bytes'] !== '' ? (int) $r['source_size_bytes'] : (int) $r['db_size_bytes'])) : 'not_checked';
            $rows[] = array('video_id' => $r ? $r['video_id'] : '', 'db_id' => $id,
                'in_inventory' => $r ? 'yes' : 'no_not_in_selected_channels', 'eligible' => $r ? $r['eligible'] : '',
                'kind' => $o['kind'], 'key' => $o['key'], 'size_bytes' => $o['size'], 'created_utc_from_name' => gmdate('Y-m-d H:i:s', $o['ts']),
                'is_best_copy' => $o['key'] === $best['key'] ? 'yes' : 'no', 'copies_of_kind' => $best['copies'], 'check' => $check,
                'flag' => 'unverified_temp_copy');
        }
    }
    return $rows;
}

function kx_run_inventory($root, $out_dir, array $rows, array $ctx, array $audio_dirs) {
    $records = array();
    foreach ($rows as $row) { $records[] = kx_build_record($row, $root, $ctx, $audio_dirs); }
    $skipped = array_values(array_filter($records, function ($r) { return $r['eligible'] === 'no'; }));
    usort($skipped, function ($a, $b) { return strcmp($a['_rule'], $b['_rule']) ?: $a['db_id'] <=> $b['db_id']; });
    $stamp = gmdate('Ymd_His');
    $inv = $out_dir . "/inventory_$stamp.csv";
    $skp = $out_dir . "/skipped_$stamp.csv";
    $cols_skipped = array_merge(array('_rule'), KX_CSV_COLUMNS);
    if (!kx_write_csv($inv, KX_CSV_COLUMNS, $records) || !kx_write_csv($skp, $cols_skipped, $skipped)) {
        return array(null, null, $records, null);
    }
    $tmp_csv = null;
    if (!empty($ctx['manifest'])) {
        $tmp_csv = $out_dir . "/unverified_temp_copies_$stamp.csv";
        if (!kx_write_csv($tmp_csv, KX_TEMP_COLUMNS, kx_temp_copy_rows($ctx['manifest'], $records))) { return array(null, null, $records, null); }
    }
    return array($inv, $skp, $records, $tmp_csv);
}

function kx_run_samples($out_dir, array $records, array $wanted, array $ctx, $r2_remote = 'r2:videos') {
    $dir = $out_dir . '/samples';
    if (!is_dir($dir)) { mkdir($dir, 0700, true); }
    $report = array();
    $cols = array('requested', 'video_id', 'kind', 'status', 'file', 'bytes', 'sha256', 'original_source_type', 'original_source_ref', 'original_source_exists', 'original_source_size_bytes', 'retrieve_command', 'note');
    foreach ($wanted as $req) {
        $rec = null;
        foreach ($records as $r) { if ($r['video_id'] === $req || (string) $r['db_id'] === $req) { $rec = $r; break; } }
        if ($rec === null) { $report[] = array('requested' => $req, 'status' => 'not_found', 'note' => 'No such video in the selected channels.'); continue; }
        $row = array('requested' => $req, 'video_id' => $rec['video_id'], 'kind' => $rec['qa_verified'] === 'yes' ? 'qa_clip' : 'regular',
            'original_source_type' => $rec['source_type'], 'original_source_ref' => $rec['source_ref'],
            'original_source_exists' => $rec['source_exists'], 'original_source_size_bytes' => $rec['source_size_bytes']);
        if ($rec['_rule'] === 'private') {
            $report[] = $row + array('status' => 'refused_private', 'note' => 'Private recordings are never exported.');
            continue;
        }
        if ($rec['_audio_path']) {
            $ext = strtolower(pathinfo($rec['_audio_path'], PATHINFO_EXTENSION));
            $dest = $dir . '/' . $rec['video_id'] . '.' . $ext;
            if (copy($rec['_audio_path'], $dest . '.part') && rename($dest . '.part', $dest)) {
                chmod($dest, 0600);
                $report[] = $row + array('status' => 'copied', 'file' => basename($dest), 'bytes' => filesize($dest), 'sha256' => hash_file('sha256', $dest), 'note' => '');
                continue;
            }
            $report[] = $row + array('status' => 'copy_failed', 'note' => 'Could not copy saved audio.');
            continue;
        }
        if ($rec['_cached_wav_key'] !== '') {
            $report[] = $row + array('status' => 'unverified_wav_in_r2', 'file' => $rec['video_id'] . '.wav', 'bytes' => $rec['cached_wav_size_bytes'],
                'retrieve_command' => 'rclone copyto ' . $r2_remote . '/' . $rec['_cached_wav_key'] . ' ./' . $rec['video_id'] . '.wav',
                'note' => 'Unverified temp WAV (check: ' . $rec['cached_wav_check'] . ') in R2, not on this server. Run the command with your own R2 credentials; the exporter downloads nothing.');
            continue;
        }
        if ($rec['_cached_mp4_key'] !== '') {
            $report[] = $row + array('status' => 'unverified_mp4_in_r2', 'file' => $rec['video_id'] . '.mp4', 'bytes' => $rec['cached_mp4_size_bytes'],
                'retrieve_command' => 'rclone copyto ' . $r2_remote . '/' . $rec['_cached_mp4_key'] . ' ./' . $rec['video_id'] . '.mp4',
                'note' => 'No WAV; unverified temp MP4 copy (check: ' . $rec['cached_mp4_check'] . ') in R2. Original media source is reported above.');
            continue;
        }
        $report[] = $row + array('status' => 'audio_not_retained', 'note' => 'Worker deletes its temp WAV after each job. Original media source reported; FFmpeg was not run.');
    }
    $path = $dir . '/sample_report_' . gmdate('Ymd_His') . '.csv';
    $norm = array_map(function ($r) use ($cols) { return array_merge(array_fill_keys($cols, ''), $r); }, $report);
    kx_write_csv($path, $cols, $norm);
    return array($path, $norm);
}

function kx_main(array $argv) {
    $opts = kx_parse_args($argv);
    if ($opts['help']) { echo kx_usage(); return 0; }
    if ($opts['errors']) { fwrite(STDERR, implode("\n", $opts['errors']) . "\n\n" . kx_usage()); return 2; }
    $root = realpath(dirname(__DIR__));
    list($out_dir, $err) = kx_prepare_out_dir($root, $opts['out_dir']);
    if ($err) { fwrite(STDERR, $err . "\n"); return 2; }

    $qa = $opts['qa_file'] !== null ? kx_read_id_file($opts['qa_file']) : array();
    $ex = $opts['exclude_file'] !== null ? kx_read_id_file($opts['exclude_file']) : array();
    if ($qa === false || $ex === false) { fwrite(STDERR, "Cannot read an ids file.\n"); return 2; }
    foreach ($opts['audio_dirs'] as $d) { if (!is_dir($d)) { fwrite(STDERR, "Not a directory: $d\n"); return 2; } }

    $m = kx_connect($root);
    list($max, $channel_ids) = kx_load_context($m, $opts);
    $where = $channel_ids === null ? '1=1' : 'v.user_id IN (' . implode(',', array_map('intval', $channel_ids)) . ')';
    $rows = kx_select($m, kx_video_select($where));
    $m->rollback();
    $m->close();

    $ctx = array('qa_ids' => $qa, 'exclude_ids' => $ex, 'max_duration' => $max);
    $ctx['manifest'] = array();
    if ($opts['manifest'] !== null) {
        $ctx['manifest'] = kx_load_manifest($opts['manifest']);
        if ($ctx['manifest'] === false) { fwrite(STDERR, "Cannot read the audio manifest.\n"); return 2; }
    }
    list($inv, $skp, $records, $tmp_csv) = kx_run_inventory($root, $out_dir, $rows, $ctx, $opts['audio_dirs']);
    if ($inv === null) { fwrite(STDERR, "Could not write CSV files.\n"); return 1; }

    $counts = array();
    foreach ($records as $r) { $k = $r['eligible'] === 'yes' ? 'eligible' : 'skipped: ' . $r['_rule']; $counts[$k] = ($counts[$k] ?? 0) + 1; }
    ksort($counts);
    $known = array();
    foreach ($records as $r) { $known[$r['video_id']] = true; }
    $unknown_qa = array_diff(array_keys($qa), array_keys($known));
    echo 'Videos examined: ' . count($records) . "\n";
    foreach ($counts as $k => $n) { echo str_pad($k, 36) . $n . "\n"; }
    $elig = array_filter($records, function ($r) { return $r['eligible'] === 'yes'; });
    $n_wav = count(array_filter($elig, function ($r) { return $r['cached_wav_key'] !== ''; }));
    $n_mp4 = count(array_filter($elig, function ($r) { return $r['cached_mp4_key'] !== ''; }));
    $n_none = count(array_filter($elig, function ($r) { return $r['cached_wav_key'] === '' && $r['saved_audio_available'] !== 'yes'; }));
    $n_disk = count(array_filter($elig, function ($r) { return $r['saved_audio_available'] === 'yes'; }));
    echo "Eligible with a temp WAV in the listing: $n_wav | with a temp MP4: $n_mp4 | WAV on server disk: $n_disk | no WAV anywhere: $n_none\n";
    if ($unknown_qa) { echo 'Warning: Q&A ids not found in selected channels: ' . implode(', ', $unknown_qa) . "\n"; }
    echo "Inventory: $inv\nSkipped:   $skp\n";
    if ($tmp_csv) { echo "Unverified temp copies: $tmp_csv\n"; }

    if ($opts['sample_ids']) {
        list($rep, $res) = kx_run_samples($out_dir, $records, $opts['sample_ids'], $ctx, $opts['r2_remote']);
        echo "\nSample export:\n";
        foreach ($res as $r) { echo '  ' . $r['requested'] . ' -> ' . $r['status'] . ($r['file'] !== '' ? ' (' . $r['file'] . ')' : '') . "\n"; }
        echo "Sample report: $rep\n";
    }
    return 0;
}

if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(kx_main($argv));
}
