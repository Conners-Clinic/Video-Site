<?php
/** Exact-revision release with private snapshots, migrations, smoke tests and guarded rollback. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '0');
set_time_limit(240); ini_set('memory_limit', '256M'); umask(0077); putenv('GIT_TERMINAL_PROMPT=0');
require_once __DIR__ . '/traffic-rebase-evidence.php';
$phase = 'arguments'; $journal = null; $changed = false; $snapshot = null; $root = null;
$report = array('status' => 'failed');
function releaseCommand($arguments, $log, $allowed = array(0)) {
    $process = proc_open($arguments, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', $log, 'a')), $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Command unavailable'); }
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]); $code = proc_close($process);
    if (!in_array($code, $allowed, true)) { file_put_contents($log, $output, FILE_APPEND); throw new RuntimeException('Release command failed', $code); }
    return $output;
}
function releaseGit($root, $arguments, $log) { return rtrim(releaseCommand(array_merge(array('git', '-c', 'core.hooksPath=/dev/null', '--no-optional-locks', '-C', $root), $arguments), $log), "\r\n"); }
function releaseWrite($path, $bytes, $mode = 0644) {
    $temporary = dirname($path) . '/.traffic-release-' . bin2hex(random_bytes(6));
    if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !chmod($temporary, $mode) || !rename($temporary, $path)) { throw new RuntimeException('Atomic write failed'); }
}
function releaseFpmSource($tokenHash) {
    return '<?php if (!hash_equals(' . var_export($tokenHash, true)
        . ', hash("sha256", $_SERVER["HTTP_X_TRAFFIC_RELEASE"] ?? ""))) { http_response_code(404); exit; }'
        . 'header("Content-Type: application/json"); header("Cache-Control: no-store");'
        . '$enabled=function_exists("opcache_get_status") && (bool)ini_get("opcache.enable"); $invalidated=0; $ok=true;'
        . 'if($enabled){$status=opcache_get_status(true); if(!is_array($status)){$ok=false;}else{'
        . 'foreach(array_keys($status["scripts"]??array()) as $path){if(strpos($path, __DIR__."/")===0){'
        . 'if(!opcache_invalidate($path,true)){$ok=false;}else{$invalidated++;}}}}}'
        . 'echo json_encode(array("ok"=>$ok,"php"=>PHP_VERSION,"sapi"=>PHP_SAPI,"opcache_enabled"=>$enabled,"site_scripts_invalidated"=>$invalidated));';
}
function releaseFpmCache($root, $site) {
    $token = bin2hex(random_bytes(32)); $name = '_traffic_release_' . bin2hex(random_bytes(16)) . '.php';
    $file = $root . '/' . $name;
    $source = releaseFpmSource(hash('sha256', $token));
    try {
        releaseWrite($file, $source, 0600);
        $curl = curl_init($site . '/' . $name);
        curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => array('X-Traffic-Release: ' . $token)));
        $body = curl_exec($curl); $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        $result = $code === 200 && is_string($body) ? json_decode($body, true) : null;
        if (!is_array($result) || empty($result['ok'])) { throw new RuntimeException('Web runtime cache verification failed'); }
        return $result;
    } finally { if (is_file($file)) { unlink($file); } }
}
function releaseSmoke($site, $watchKey = null) {
    $routes = array('/' => 'html', '/search?keyword=Rife' => 'html',
        '/assets/js/search-suggestions.js' => 'script', '/assets/js/background-poll.js' => 'script', '/assets/js/playlist-navigation.js' => 'script');
    if ($watchKey !== null) { $routes['/watch/' . rawurlencode($watchKey)] = 'html'; }
    foreach ($routes as $path => $kind) {
        $curl = curl_init($site . $path . (strpos($path, '?') === false ? '?' : '&') . 'release_probe=' . bin2hex(random_bytes(5)));
        curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => false, CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => array('Cache-Control: no-cache')));
        $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        if ($status !== 200 || !is_string($body) || ($kind === 'html' && (strlen($body) < 1000 || stripos($body, '</html>') === false))
            || ($kind === 'script' && strpos($body, 'function') === false)) { throw new RuntimeException('Release smoke failed', (int) $status); }
    }
    return count($routes);
}
try {
    $options = getopt('', array('site-root:', 'private-base:', 'target:', 'environment:', 'recover-known-rebase'));
    $root = realpath($options['site-root'] ?? ''); $private = realpath($options['private-base'] ?? '');
    $target = $options['target'] ?? ''; $environment = $options['environment'] ?? '';
    $candidate = dirname(__DIR__);
    if (!$root || !$private || strpos($private . '/', $root . '/') === 0 || !preg_match('/^[a-f0-9]{40}$/D', $target)
        || !in_array($environment, array('staging', 'production'), true)) { throw new RuntimeException('Invalid release arguments'); }
    $lock = fopen($private . '/release-' . $environment . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Release already running'); }
    $journal = $private . '/release-' . $environment . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
    if (!mkdir($journal, 0700)) { throw new RuntimeException('Private journal unavailable'); }
    $log = $journal . '/operations.log';
    if (releaseGit($candidate, array('rev-parse', 'HEAD'), $log) !== $target) { throw new RuntimeException('Candidate revision mismatch'); }
    $branch = $environment === 'production' ? 'super' : 'main';
    $host = $environment === 'production' ? 'videos.connersclinic.com' : 'test-videos.connersclinic.com';
    ob_start();
    try { $settings = (static function ($file) { require $file; return get_defined_vars(); })($root . '/config.php'); }
    finally { ob_end_clean(); }
    $site = rtrim($settings['site_url'] ?? '', '/');
    if (parse_url($site, PHP_URL_HOST) !== $host) { throw new RuntimeException('Environment hostname mismatch'); }
    $phase = 'revision_guards';
    if (realpath(releaseGit($root, array('rev-parse', '--show-toplevel'), $log)) !== $root) { throw new RuntimeException('Checkout points outside expected site'); }
    $remote = releaseGit($root, array('ls-remote', 'https://github.com/Conners-Clinic/Video-Site.git', 'refs/heads/' . $branch), $log);
    if (substr($remote, 0, 40) !== $target) { throw new RuntimeException('A newer branch revision superseded this release'); }
    $originalHead = releaseGit($root, array('rev-parse', 'HEAD'), $log);
    $preservedDirectories = array();
    $recovery = is_dir($root . '/.git/rebase-merge');
    if ($recovery) {
        if ($environment !== 'production' || !isset($options['recover-known-rebase'])) { throw new RuntimeException('Unfinished Git operation'); }
        $evidence = trafficRebaseEvidence($root, $private);
        if (!$evidence['safe_to_recover_known_rebase']) { throw new RuntimeException('Unrecognized production edits'); }
        if (trim(file_get_contents($root . '/.git/rebase-merge/head-name')) !== 'refs/heads/super') { throw new RuntimeException('Unexpected rebase branch'); }
    } else {
        foreach (array('rebase-apply', 'MERGE_HEAD', 'CHERRY_PICK_HEAD', 'REVERT_HEAD') as $operation) {
            if (file_exists($root . '/.git/' . $operation)) { throw new RuntimeException('Unfinished Git operation'); }
        }
        if (releaseGit($root, array('symbolic-ref', '--short', 'HEAD'), $log) !== $branch) { throw new RuntimeException('Unexpected checkout branch'); }
    }
    $dirty = trim(releaseGit($root, array('status', '--porcelain', '--untracked-files=no'), $log));
    if (!$recovery && $dirty !== '' && !($environment === 'staging' && $dirty === 'M assets/includes/functions_one.php')) { throw new RuntimeException('Unrecognized local changes'); }
    releaseGit($root, array('fetch', '--quiet', '--no-tags', 'https://github.com/Conners-Clinic/Video-Site.git', $target), $log);
    releaseGit($root, array('merge-base', '--is-ancestor', $recovery ? '07ec37f96dd0c3f9dc0fca256a9d849b37be0a46' : $originalHead, $target), $log);
    $phase = 'candidate_regression';
    foreach (array('tests/run.php', 'tests/watch-library.php', 'tests/sidebar-menu.php', 'tests/export_inventory_check.php', 'tests/traffic-reads.php', 'tests/traffic-search.php', 'tests/traffic-cards.php', 'tests/traffic-budgets.php', 'tests/traffic-metrics.php', 'tests/traffic-release.php') as $suite) {
        releaseCommand(array(PHP_BINARY, $candidate . '/' . $suite), $log);
    }
    releaseCommand(array(PHP_BINARY, $candidate . '/tests/traffic-cards.php', '--signed'), $log);
    if ($environment === 'staging') {
        foreach (array('tools/traffic-db-read-check.php', 'tools/traffic-publication-check.php', 'tools/traffic-list-check.php') as $suite) {
            releaseCommand(array(PHP_BINARY, $candidate . '/' . $suite, '--site-root=' . $root), $log);
        }
    }
    $phase = 'private_snapshot';
    $tracked = array_values(array_unique(array_filter(explode("\0", releaseCommand(array('git', '-C', $root, 'ls-files', '-z'), $log)))));
    $snapshotFiles = array();
    foreach (array_unique(array_merge($tracked, array('.git', 'config.php', 'phpMailer_config.php', 'nodejs/config.json', '.htaccess', '.user.ini'))) as $path) {
        if (file_exists($root . '/' . $path) || is_link($root . '/' . $path)) { $snapshotFiles[] = $path; }
    }
    file_put_contents($journal . '/files.nul', implode("\0", $snapshotFiles) . "\0");
    $snapshot = $journal . '/snapshot.tar.gz';
    releaseCommand(array('tar', '-C', $root, '-czf', $snapshot, '--null', '-T', $journal . '/files.nul'), $log);
    releaseCommand(array('tar', '-tzf', $snapshot), $log);
    $configHash = hash_file('sha256', $root . '/config.php');
    $localFunction = file_get_contents($root . '/assets/includes/functions_one.php');
    $overlay = null;
    if (!$recovery && $dirty !== '') {
        $normalize = static function ($text) { return rtrim(str_replace("\r\n", "\n", $text)) . "\n"; };
        file_put_contents($journal . '/base.php', $normalize(releaseGit($root, array('show', 'HEAD:assets/includes/functions_one.php'), $log)));
        file_put_contents($journal . '/local.php', $normalize($localFunction));
        file_put_contents($journal . '/new.php', $normalize(file_get_contents($candidate . '/assets/includes/functions_one.php')));
        $overlay = releaseCommand(array('git', 'merge-file', '-p', $journal . '/local.php', $journal . '/base.php', $journal . '/new.php'), $log);
        if (strpos(file_get_contents($candidate . '/assets/includes/functions_one.php'), "\r\n") !== false) { $overlay = str_replace("\n", "\r\n", $overlay); }
        file_put_contents($journal . '/merged.php', $overlay);
        releaseCommand(array(PHP_BINARY, '-l', $journal . '/merged.php'), $log);
    }
    file_put_contents($journal . '/state.json', json_encode(array('original' => $originalHead, 'target' => $target, 'branch' => $branch, 'recovery' => $recovery, 'config_hash' => $configHash)));
    $phase = 'migrations';
    $migrationOutput = releaseCommand(array(PHP_BINARY, $candidate . '/tools/migrate-traffic.php', '--site-root=' . $root, '--apply'), $log);
    file_put_contents($journal . '/migrations.log', $migrationOutput);
    $phase = 'checkout';
    releaseGit($root, array('fetch', '--quiet', '--no-tags', 'https://github.com/Conners-Clinic/Video-Site.git', $target), $log);
    $changed = true; umask(0022);
    if ($recovery) {
        // These paths are tracked public symlinks but currently contain local directories.
        // Preserve every byte outside the webroot before restoring the public symlinks.
        mkdir($journal . '/preserved-helper-directories', 0700);
        foreach (array('.claude/skills/continuity-onboard', '.claude/skills/handoff', '.claude/skills/pickup') as $path) {
            if (is_dir($root . '/' . $path) && !is_link($root . '/' . $path)) {
                if (!rename($root . '/' . $path, $journal . '/preserved-helper-directories/' . basename($path))) { throw new RuntimeException('Helper preservation failed'); }
                $preservedDirectories[] = $path;
            }
        }
        releaseGit($root, array('rebase', '--abort'), $log);
    }
    elseif ($overlay !== null) {
        releaseGit($root, array('-c', 'user.name=Traffic Release', '-c', 'user.email=traffic-release@localhost', 'stash', 'push', '--quiet', '-m', 'Preserve staging media customization before traffic release', '--', 'assets/includes/functions_one.php'), $log);
    }
    if (releaseGit($root, array('symbolic-ref', '--short', 'HEAD'), $log) !== $branch) { throw new RuntimeException('Recovery did not restore expected branch'); }
    if (trim(releaseGit($root, array('status', '--porcelain', '--untracked-files=no'), $log)) !== '') { throw new RuntimeException('Checkout is still modified after preservation'); }
    releaseGit($root, array('merge', '--ff-only', '--no-edit', $target), $log);
    umask(0077);
    if ($overlay !== null) { releaseWrite($root . '/assets/includes/functions_one.php', $overlay, 0755); }
    if (releaseGit($root, array('rev-parse', 'HEAD'), $log) !== $target || hash_file('sha256', $root . '/config.php') !== $configHash) { throw new RuntimeException('Revision/configuration verification failed'); }
    $phase = 'smoke';
    $runtime = releaseFpmCache($root, $site);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = new mysqli($settings['sql_db_host'], $settings['sql_db_user'], $settings['sql_db_pass'], $settings['sql_db_name']);
    $connection->query('SET SESSION max_statement_time = 5');
    $watch = $connection->query('SELECT video_id FROM videos WHERE approved=1 AND privacy=0 AND sell_video=0 AND live_time=0 AND is_short=0 AND is_movie=0 ORDER BY id DESC LIMIT 1')->fetch_assoc();
    $connection->close();
    $smokes = releaseSmoke($site, $watch['video_id'] ?? null);
    $report = array('status' => 'passed', 'environment' => $environment, 'revision' => $target,
        'known_rebase_recovered' => $recovery, 'helper_directories_preserved' => count($preservedDirectories), 'private_backup_verified' => true, 'local_media_overlay_preserved' => $overlay !== null,
        'configuration_unchanged' => true, 'http_smoke_checks' => $smokes, 'migrations_passed' => true);
    $report['web_runtime'] = $runtime;
    file_put_contents($private . '/latest-release-' . $environment . '.json', json_encode(array('journal' => $journal, 'target' => $target)));
} catch (Throwable $error) {
    $report = array('status' => 'failed', 'phase' => $phase, 'error_code' => (int) $error->getCode());
    if ($journal) { file_put_contents($journal . '/failure.txt', $error->getMessage()); }
    if ($changed && $snapshot) {
        try {
            $phase = 'rollback';
            $currentHead = releaseGit($root, array('rev-parse', 'HEAD'), $log);
            $expectedHeads = array($target, $originalHead, '07ec37f96dd0c3f9dc0fca256a9d849b37be0a46');
            if (!in_array($currentHead, $expectedHeads, true)) { throw new RuntimeException('Concurrent release detected'); }
            $currentDirty = trim(releaseGit($root, array('status', '--porcelain', '--untracked-files=no'), $log));
            if ($currentHead === $target && $currentDirty !== '' && !($overlay !== null && $currentDirty === 'M assets/includes/functions_one.php'
                && file_get_contents($root . '/assets/includes/functions_one.php') === $overlay)) { throw new RuntimeException('Concurrent edit detected'); }
            $newFiles = array_filter(explode("\0", releaseCommand(array('git', '-C', $root, 'ls-tree', '-rz', '--name-only', $target), $log)));
            foreach (array_diff($newFiles, $tracked) as $path) {
                $file = $root . '/' . $path;
                if (!is_file($file) && !is_link($file)) { continue; }
                $expected = releaseGit($root, array('rev-parse', $target . ':' . $path), $log);
                $bytes = is_link($file) ? readlink($file) : file_get_contents($file);
                if (sha1('blob ' . strlen($bytes) . "\0" . $bytes) !== $expected) { throw new RuntimeException('New file changed during release'); }
                unlink($file);
            }
            foreach ($preservedDirectories as $path) {
                $file = $root . '/' . $path;
                if (is_link($file)) {
                    $bytes = readlink($file);
                    $expected = releaseGit($root, array('rev-parse', $currentHead . ':' . $path), $log);
                    if (sha1('blob ' . strlen($bytes) . "\0" . $bytes) !== $expected) { throw new RuntimeException('Helper symlink changed during release'); }
                    unlink($file);
                } elseif (file_exists($file)) { throw new RuntimeException('Helper directory recreated concurrently'); }
            }
            releaseCommand(array('tar', '-C', $root, '-xzf', $snapshot), $log);
            if (releaseGit($root, array('rev-parse', 'HEAD'), $log) !== $originalHead || hash_file('sha256', $root . '/config.php') !== $configHash) { throw new RuntimeException('Rollback identity mismatch'); }
            releaseFpmCache($root, $site);
            $report['original_checkout_restored'] = true;
        } catch (Throwable $rollbackError) {
            $report['manual_recovery_required'] = true;
            file_put_contents($journal . '/rollback-failure.txt', $rollbackError->getMessage());
        }
    }
}
if ($journal) { file_put_contents($journal . '/report.json', json_encode($report)); }
echo 'TRAFFIC_RELEASE_JSON=' . json_encode($report) . "\n";
exit($report['status'] === 'passed' ? 0 : 1);
