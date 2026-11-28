<?php
/** Temporarily test an exact candidate on staging, preserve its local patch, then roll back. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '0'); umask(0077);
set_time_limit(900);
$phase = 'arguments'; $journal = null; $root = null; $flag = null; $stash = null;
$original = null; $originalFunction = null; $originalStartup = null; $instrumented = false; $switched = false;
$report = array('environment' => 'staging', 'production_changed' => false, 'capacity_test' => false, 'signed_in_http_test' => false);
function rehearsalGit($root, $args, $log) {
    $command = 'git --no-optional-locks -C ' . escapeshellarg($root) . ' ' . implode(' ', array_map('escapeshellarg', $args));
    $lines = array(); $code = 1; exec($command . ' 2>>' . escapeshellarg($log), $lines, $code);
    if ($code !== 0) { throw new RuntimeException('Git command failed', $code); }
    return trim(implode("\n", $lines));
}
function rehearsalWrite($path, $bytes) {
    $temporary = $path . '.traffic-tmp-' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) { throw new RuntimeException('Write failed'); }
    chmod($temporary, is_file($path) ? (fileperms($path) & 0777) : 0600);
    if (!rename($temporary, $path)) { throw new RuntimeException('Atomic file replacement failed'); }
}
function rehearsalHttp($url, $cookie, $key, $post = null) {
    $separator = strpos($url, '?') === false ? '?' : '&';
    $curl = curl_init($url . $separator . 'traffic_probe=' . bin2hex(random_bytes(6)));
    curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_ENCODING => '',
        CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_HTTPHEADER => array('X-Traffic-Benchmark: ' . $key, 'Cache-Control: no-cache')));
    if ($post !== null) { curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($curl); $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $ms = curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000; curl_close($curl);
    if (!is_string($body) || $code !== 200 || $ms > 5000 || preg_match('/<b>(?:Fatal error|Parse error)<\/b>/', $body)) {
        throw new RuntimeException('HTTP smoke or latency threshold failed', $code);
    }
    return array('body' => $body, 'ms' => $ms, 'bytes' => strlen($body));
}
function rehearsalPercentile($values, $fraction) {
    sort($values, SORT_NUMERIC); return round($values[max(0, (int) ceil(count($values) * $fraction) - 1)], 3);
}
function rehearsalConcurrency($urls, $key) {
    $result = array();
    foreach (array(2, 5) as $concurrency) {
        $times = array();
        for ($batch = 0; $batch < 4; $batch++) {
            $multi = curl_multi_init(); $handles = array();
            try {
                for ($i = 0; $i < $concurrency; $i++) {
                    $url = $urls[($batch + $i) % count($urls)];
                    $handle = curl_init($url . (strpos($url, '?') === false ? '?' : '&') . 'traffic_probe=' . bin2hex(random_bytes(6)));
                    curl_setopt_array($handle, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6, CURLOPT_ENCODING => '',
                        CURLOPT_HTTPHEADER => array('X-Traffic-Benchmark: ' . $key, 'Cache-Control: no-cache')));
                    $handles[] = $handle; curl_multi_add_handle($multi, $handle);
                }
                do { curl_multi_exec($multi, $running); if ($running) { curl_multi_select($multi, .25); } } while ($running);
                foreach ($handles as $handle) {
                    $body = curl_multi_getcontent($handle); $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
                    $ms = curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000;
                    if ($status !== 200 || !is_string($body) || strlen($body) < 1000 || $ms > 5000) { throw new RuntimeException('Concurrent smoke or latency threshold failed'); }
                    $times[] = $ms;
                }
            } finally {
                foreach ($handles as $handle) { curl_multi_remove_handle($multi, $handle); curl_close($handle); }
                curl_multi_close($multi);
            }
        }
        $result[] = array('concurrent_requests' => $concurrency, 'requests' => count($times), 'errors' => 0,
            'p50_ms' => rehearsalPercentile($times, .5), 'p95_ms' => rehearsalPercentile($times, .95));
    }
    return $result;
}
function rehearsalJourney($site, $watch, $directory, $key, $candidate) {
    $cookie = $directory . '/cookies';
    $home = rehearsalHttp($site . '/', $cookie, $key);
    $dom = new DOMDocument(); @$dom->loadHTML($home['body'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom); $nodes = $xpath->query("//input[contains(concat(' ', normalize-space(@class), ' '), ' main_session ')]");
    $csrf = $nodes->length ? $nodes->item(0)->getAttribute('value') : '';
    if ($csrf === '') { throw new RuntimeException('No suggestion CSRF token in home HTML'); }
    if ($candidate && (strpos($home['body'], '/assets/js/search-suggestions.js') === false || strpos($home['body'], '/assets/js/background-poll.js') === false)) {
        throw new RuntimeException('Candidate assets missing from served HTML');
    }
    if ($candidate) {
        foreach (array('search-suggestions', 'background-poll', 'playlist-navigation') as $asset) {
            $script = rehearsalHttp($site . '/assets/js/' . $asset . '.js', $cookie, $key);
            if (strpos($script['body'], 'function') === false) { throw new RuntimeException('JavaScript asset not served'); }
        }
    }
    $routes = array('home' => $site . '/', 'search' => $site . '/search?keyword=Rife', 'watch' => $watch);
    $result = array();
    foreach ($routes as $name => $url) {
        $times = array(); $bytes = array();
        for ($i = 0; $i < 10; $i++) {
            $response = rehearsalHttp($url, $cookie, $key); $times[] = $response['ms']; $bytes[] = $response['bytes'];
            if (strlen($response['body']) < 1000) { throw new RuntimeException('Page response unexpectedly short'); }
        }
        $result[$name] = array('samples' => 10, 'errors' => 0, 'p50_ms' => rehearsalPercentile($times, .5),
            'p95_ms' => rehearsalPercentile($times, .95), 'mean_body_bytes' => (int) round(array_sum($bytes) / 10));
    }
    $times = array();
    for ($i = 0; $i < 10; $i++) {
        $response = rehearsalHttp($site . '/aj/search', $cookie, $key, array('search_value' => 'Rife', 'hash' => $csrf));
        $data = json_decode($response['body'], true);
        if (!is_array($data) || ($data['status'] ?? 0) !== 200 || empty($data['html'])) { throw new RuntimeException('Suggestions did not return results'); }
        $times[] = $response['ms'];
    }
    $result['suggestions'] = array('samples' => 10, 'errors' => 0, 'p50_ms' => rehearsalPercentile($times, .5), 'p95_ms' => rehearsalPercentile($times, .95));
    if ($candidate) {
        $bad = rehearsalHttp($site . '/aj/search', $cookie, $key, array('search_value' => str_repeat('a', 201), 'hash' => $csrf));
        $data = json_decode($bad['body'], true);
        if (($data['status'] ?? 0) !== 400) { throw new RuntimeException('Search bounds not served'); }
        $result['oversized_suggestion_rejected'] = true;
    }
    return $result;
}
function rehearsalMetrics($path) {
    $groups = array();
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line) {
        $row = json_decode($line, true); if (!is_array($row)) { continue; }
        $groups[$row['route']][] = $row;
    }
    $result = array();
    foreach ($groups as $name => $rows) {
        $result[$name] = array('samples' => count($rows),
            'p50_instrumented_queries' => rehearsalPercentile(array_column($rows, 'instrumented_query_count'), .5),
            'p50_wrapper_sql_ms' => rehearsalPercentile(array_column($rows, 'wrapper_sql_ms'), .5),
            'p95_php_ms' => rehearsalPercentile(array_column($rows, 'elapsed_ms'), .95),
            'max_peak_memory_bytes' => max(array_column($rows, 'peak_memory_bytes')));
    }
    if (!$result) { throw new RuntimeException('No private request metrics collected'); }
    return $result;
}
try {
    $options = getopt('', array('site-root:', 'private-base:', 'target:', 'browser-window:'));
    $root = realpath($options['site-root'] ?? ''); $private = realpath($options['private-base'] ?? ''); $target = $options['target'] ?? '';
    if (!$root || !$private || strpos($private . '/', $root . '/') === 0 || !preg_match('/^[a-f0-9]{40}$/D', $target)) { throw new RuntimeException('Invalid rehearsal arguments'); }
    $journal = $private . '/rehearsal-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
    if (!mkdir($journal, 0700)) { throw new RuntimeException('Private journal unavailable'); }
    $log = $journal . '/operations.log'; $candidate = dirname(__DIR__);
    if (rehearsalGit($candidate, array('rev-parse', 'HEAD'), $log) !== $target) { throw new RuntimeException('Candidate revision mismatch'); }
    ob_start();
    try { $settings = (static function ($file) { require $file; return get_defined_vars(); })($root . '/config.php'); }
    finally { ob_end_clean(); }
    $site = rtrim($settings['site_url'] ?? '', '/');
    if (parse_url($site, PHP_URL_HOST) !== 'test-videos.connersclinic.com') { throw new RuntimeException('Only staging may be rehearsed'); }
    $phase = 'checkout_guards';
    $original = rehearsalGit($root, array('rev-parse', 'HEAD'), $log);
    if (rehearsalGit($root, array('symbolic-ref', '--short', 'HEAD'), $log) !== 'main') { throw new RuntimeException('Staging must start on main'); }
    foreach (array('rebase-merge', 'rebase-apply', 'MERGE_HEAD', 'CHERRY_PICK_HEAD') as $operation) {
        if (file_exists($root . '/.git/' . $operation)) { throw new RuntimeException('Staging Git operation in progress'); }
    }
    $status = rehearsalGit($root, array('status', '--porcelain', '--untracked-files=no'), $log);
    if ($status !== '' && $status !== 'M assets/includes/functions_one.php') { throw new RuntimeException('Unexpected staging modifications'); }
    $snapshot = trim(file_get_contents($private . '/latest-staging-snapshot'));
    if (strpos($snapshot . '/', $private . '/') !== 0 || !is_file($snapshot . '/snapshot.tar.gz')) { throw new RuntimeException('Verified private backup required'); }
    $originalFunction = file_get_contents($root . '/assets/includes/functions_one.php');
    $originalStartup = file_get_contents($root . '/assets/includes/app_start.php');
    $configHash = hash_file('sha256', $root . '/config.php');
    file_put_contents($journal . '/original-functions.php', $originalFunction);
    file_put_contents($journal . '/original-startup.php', $originalStartup);
    file_put_contents($journal . '/state.json', json_encode(array('original' => $original, 'target' => $target, 'config_hash' => $configHash)));
    $phase = 'prepare_local_merge';
    $normalize = static function ($value) { return rtrim(str_replace("\r\n", "\n", $value)) . "\n"; };
    file_put_contents($journal . '/base.php', $normalize(rehearsalGit($root, array('show', 'HEAD:assets/includes/functions_one.php'), $log)));
    file_put_contents($journal . '/local.php', $normalize($originalFunction));
    file_put_contents($journal . '/candidate.php', $normalize(file_get_contents($candidate . '/assets/includes/functions_one.php')));
    $merged = $journal . '/merged.php'; $code = 1;
    exec('git merge-file -p ' . escapeshellarg($journal . '/local.php') . ' ' . escapeshellarg($journal . '/base.php') . ' ' . escapeshellarg($journal . '/candidate.php') . ' >' . escapeshellarg($merged) . ' 2>>' . escapeshellarg($log), $ignored, $code);
    if ($code !== 0) { throw new RuntimeException('Server customization does not merge cleanly'); }
    exec('php -l ' . escapeshellarg($merged) . ' >>' . escapeshellarg($log) . ' 2>&1', $ignored, $code);
    if ($code !== 0) { throw new RuntimeException('Merged customization does not lint'); }
    $phase = 'public_watch_fixture';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = new mysqli($settings['sql_db_host'], $settings['sql_db_user'], $settings['sql_db_pass'], $settings['sql_db_name']);
    $connection->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
    $row = $connection->query('SELECT video_id FROM videos WHERE privacy = 0 AND approved = 1 AND sell_video = 0 AND live_time = 0 AND is_short = 0 AND is_movie = 0 ORDER BY id DESC LIMIT 1')->fetch_assoc();
    $connection->rollback(); $connection->close();
    if (empty($row['video_id'])) { throw new RuntimeException('Public free watch fixture unavailable'); }
    $watch = $site . '/watch/' . rawurlencode($row['video_id']);
    $key = bin2hex(random_bytes(32));
    $flag = dirname($root) . '/.codex-video-traffic/metrics-' . hash('sha256', $root) . '.json';
    $phase = 'baseline_metrics'; mkdir($journal . '/baseline', 0700);
    rehearsalWrite($flag, json_encode(array('key_hash' => hash('sha256', $key), 'expires' => time() + 600, 'log_path' => $journal . '/baseline/metrics.jsonl')));
    $hook = '$db = new MysqliDb($mysqli);';
    if (substr_count($originalStartup, $hook) !== 1) { throw new RuntimeException('Startup measurement hook not found exactly once'); }
    $instrumented = true;
    $instrumentedBytes = str_replace($hook, $hook . "\nrequire_once " . var_export($candidate . '/assets/includes/traffic_metrics.php', true) . ";\nPT_TrafficBeginRequest(\$db, " . var_export($root, true) . ');', $originalStartup);
    rehearsalWrite($root . '/assets/includes/app_start.php', $instrumentedBytes);
    $report['baseline_http'] = rehearsalJourney($site, $watch, $journal . '/baseline', $key, false);
    $report['baseline_php'] = rehearsalMetrics($journal . '/baseline/metrics.jsonl');
    if (file_get_contents($root . '/assets/includes/app_start.php') !== $instrumentedBytes) { throw new RuntimeException('Concurrent startup edit detected'); }
    rehearsalWrite($root . '/assets/includes/app_start.php', $originalStartup); $instrumented = false;
    $phase = 'temporary_candidate_checkout';
    rehearsalGit($root, array('fetch', '--quiet', '--no-tags', 'https://github.com/Conners-Clinic/Video-Site.git', $target), $log);
    if ($status !== '') {
        rehearsalGit($root, array('-c', 'user.name=Traffic Rehearsal', '-c', 'user.email=traffic-rehearsal@localhost', 'stash', 'push', '--quiet', '-m', 'Preserve staging media customization for traffic rehearsal', '--', 'assets/includes/functions_one.php'), $log);
        $stash = rehearsalGit($root, array('rev-parse', 'refs/stash'), $log);
        file_put_contents($journal . '/stash-ref', $stash);
    }
    umask(0022); // Public tracked assets must be readable by the web server.
    rehearsalGit($root, array('switch', '--quiet', '--detach', $target), $log); $switched = true;
    umask(0077);
    $mergedBytes = file_get_contents($merged);
    if (strpos(file_get_contents($candidate . '/assets/includes/functions_one.php'), "\r\n") !== false) { $mergedBytes = str_replace("\n", "\r\n", $mergedBytes); }
    rehearsalWrite($root . '/assets/includes/functions_one.php', $mergedBytes);
    $phase = 'candidate_metrics'; mkdir($journal . '/candidate', 0700);
    rehearsalWrite($flag, json_encode(array('key_hash' => hash('sha256', $key), 'expires' => time() + 600, 'log_path' => $journal . '/candidate/metrics.jsonl')));
    $report['candidate_http'] = rehearsalJourney($site, $watch, $journal . '/candidate', $key, true);
    $report['candidate_php'] = rehearsalMetrics($journal . '/candidate/metrics.jsonl');
    $phase = 'bounded_concurrency';
    $report['bounded_concurrency'] = rehearsalConcurrency(array($site . '/', $site . '/search?keyword=Rife', $watch), $key);
    $report['candidate_revision'] = $target; $report['server_media_customization_preserved'] = true;
    $report['status'] = 'passed';
    $browserSeconds = min(600, max(0, (int) ($options['browser-window'] ?? 0)));
    if ($browserSeconds > 0) {
        echo "TRAFFIC_BROWSER_REVIEW_READY=staging candidate passed automated checks; automatic rollback remains armed\n";
        flush();
        $deadline = time() + $browserSeconds;
        while (time() < $deadline) { sleep(1); }
    }
} catch (Throwable $error) {
    $report['status'] = 'failed'; $report['phase'] = $phase; $report['error_code'] = (int) $error->getCode();
    if ($journal) { file_put_contents($journal . '/failure.txt', $phase . "\n" . $error->getMessage()); }
} finally {
    try {
        if ($instrumented && $originalStartup !== null) {
            if (file_get_contents($root . '/assets/includes/app_start.php') !== $instrumentedBytes) { throw new RuntimeException('Concurrent startup edit; rollback stopped'); }
            rehearsalWrite($root . '/assets/includes/app_start.php', $originalStartup);
        }
        if ($switched) {
            if (rehearsalGit($root, array('rev-parse', 'HEAD'), $log) !== $target) { throw new RuntimeException('Concurrent deployment detected; rollback stopped'); }
            if (file_get_contents($root . '/assets/includes/functions_one.php') !== $mergedBytes) { throw new RuntimeException('Concurrent media edit detected; rollback stopped'); }
            // Only the known, privately preserved media overlay may be dirty here.
            $dirty = rehearsalGit($root, array('status', '--porcelain', '--untracked-files=no'), $log);
            if ($dirty !== '' && $dirty !== 'M assets/includes/functions_one.php') { throw new RuntimeException('Unexpected concurrent edit; automatic rollback stopped'); }
            umask(0022);
            rehearsalGit($root, array('restore', '--source=HEAD', '--worktree', '--', 'assets/includes/functions_one.php'), $log);
            rehearsalGit($root, array('switch', '--quiet', 'main'), $log);
            umask(0077);
        }
        if ($stash !== null) { rehearsalGit($root, array('stash', 'apply', '--quiet', '--index', $stash), $log); }
        if ($originalFunction !== null && $originalStartup !== null) {
            $restored = rehearsalGit($root, array('rev-parse', 'HEAD'), $log) === $original
                && hash_file('sha256', $root . '/config.php') === $configHash
                && file_get_contents($root . '/assets/includes/functions_one.php') === $originalFunction
                && file_get_contents($root . '/assets/includes/app_start.php') === $originalStartup;
            $report['original_checkout_restored'] = $restored;
            if (!$restored) { throw new RuntimeException('Rollback verification failed'); }
        }
        if ($switched && !empty($report['original_checkout_restored'])) {
            rehearsalHttp($site . '/', $journal . '/rollback-cookies', $key);
            rehearsalHttp($watch, $journal . '/rollback-cookies', $key);
            $report['rollback_home_and_watch_passed'] = true;
        }
        if ($flag !== null && is_file($flag)) { unlink($flag); }
    } catch (Throwable $rollbackError) {
        $report['status'] = 'rollback_failed'; $report['manual_recovery_required'] = true;
        if ($journal) { file_put_contents($journal . '/rollback-failure.txt', $rollbackError->getMessage()); }
    }
    if ($journal) { file_put_contents($journal . '/report.json', json_encode($report)); }
}
echo 'TRAFFIC_STAGING_REHEARSAL_JSON=' . json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
exit(($report['status'] ?? '') === 'passed' ? 0 : 1);
