<?php
/** Recover a private copy of the production checkout, never the served checkout. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
error_reporting(0); ini_set('display_errors', '0'); ini_set('log_errors', '0'); umask(0077); set_time_limit(150);
require __DIR__ . '/traffic-rebase-evidence.php';
$phase = 'arguments'; $directory = null;
function recoveryProcess($args, $log) {
    $process = proc_open($args, array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $log, 'a'), 2 => array('file', $log, 'a')), $pipes);
    if (!is_resource($process) || proc_close($process) !== 0) { throw new RuntimeException('Private rehearsal command failed'); }
}
try {
    $options = getopt('', array('site-root:', 'private-base:', 'target:'));
    $root = realpath($options['site-root'] ?? ''); $private = realpath($options['private-base'] ?? ''); $target = $options['target'] ?? '';
    if (!$root || !$private || strpos($private . '/', $root . '/') === 0 || !preg_match('/^[a-f0-9]{40}$/D', $target)) { throw new RuntimeException('Invalid roots'); }
    $before = trafficRebaseEvidence($root, $private);
    if (!$before['safe_to_recover_known_rebase']) { throw new RuntimeException('Unknown application changes'); }
    $directory = $private . '/recovery-rehearsal-' . bin2hex(random_bytes(6)); mkdir($directory, 0700);
    $copy = $directory . '/copy'; $restore = $directory . '/restore'; mkdir($copy, 0700); mkdir($restore, 0700);
    $log = $directory . '/operations.log'; $archive = $directory . '/original.tar.gz';
    $paths = array_values(array_unique(array_filter(array_merge(explode("\0", trafficRebaseGit($root, array('ls-files', '-z'))), array('.git', 'config.php', 'phpMailer_config.php', 'nodejs/config.json', '.htaccess', '.user.ini')))));
    $paths = array_values(array_filter($paths, static function ($path) use ($root) { return file_exists($root . '/' . $path) || is_link($root . '/' . $path); }));
    file_put_contents($directory . '/files.nul', implode("\0", $paths) . "\0");
    $phase = 'private_snapshot';
    recoveryProcess(array('tar', '-C', $root, '-czf', $archive, '--null', '-T', $directory . '/files.nul'), $log);
    recoveryProcess(array('tar', '-C', $copy, '-xzf', $archive), $log);
    $phase = 'private_recovery';
    mkdir($directory . '/preserved', 0700); $preserved = 0;
    foreach (array('.claude/skills/continuity-onboard', '.claude/skills/handoff', '.claude/skills/pickup') as $path) {
        if (is_dir($copy . '/' . $path) && !is_link($copy . '/' . $path)) {
            if (!rename($copy . '/' . $path, $directory . '/preserved/' . basename($path))) { throw new RuntimeException('Preservation failed'); }
            $preserved++;
        }
    }
    recoveryProcess(array('git', '-c', 'core.hooksPath=/dev/null', '-C', $copy, 'rebase', '--abort'), $log);
    if (trim(trafficRebaseGit($copy, array('symbolic-ref', '--short', 'HEAD'))) !== 'super') { throw new RuntimeException('Unexpected recovered branch'); }
    if (trim(trafficRebaseGit($copy, array('status', '--porcelain', '--untracked-files=no'))) !== '') { throw new RuntimeException('Recovery left modified files'); }
    recoveryProcess(array('git', '-c', 'core.hooksPath=/dev/null', '-C', $copy, 'fetch', '--quiet', '--no-tags', 'https://github.com/Conners-Clinic/Video-Site.git', $target), $log);
    umask(0022);
    recoveryProcess(array('git', '-c', 'core.hooksPath=/dev/null', '-C', $copy, 'merge', '--ff-only', '--no-edit', $target), $log);
    umask(0077);
    if (trim(trafficRebaseGit($copy, array('rev-parse', 'HEAD'))) !== $target) { throw new RuntimeException('Revision mismatch'); }
    recoveryProcess(array(PHP_BINARY, '-l', $copy . '/assets/includes/app_start.php'), $log);
    $phase = 'restore_copy';
    recoveryProcess(array('tar', '-C', $restore, '-xzf', $archive), $log);
    $after = trafficRebaseEvidence($restore, $private);
    if ($after !== $before) { throw new RuntimeException('Original checkout restore mismatch'); }
    if (trafficRebaseEvidence($root, $private) !== $before) { throw new RuntimeException('Served checkout changed concurrently'); }
    echo 'TRAFFIC_RECOVERY_REHEARSAL_JSON=' . json_encode(array('status' => 'passed', 'served_checkout_changed' => false,
        'private_recovery_passed' => true, 'exact_candidate_checked_out' => true, 'original_archive_restore_passed' => true,
        'helper_directories_preserved' => $preserved, 'database_mutations' => false)) . "\n";
} catch (Throwable $error) {
    if ($directory) { file_put_contents($directory . '/failure.txt', $phase . "\n" . $error->getMessage()); }
    echo 'TRAFFIC_RECOVERY_REHEARSAL_JSON=' . json_encode(array('status' => 'failed', 'phase' => $phase, 'served_checkout_changed' => false)) . "\n"; exit(1);
}
