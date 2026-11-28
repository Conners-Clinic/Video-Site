<?php
/** Read-only validation of the known interrupted production rebase. No source leaves the host. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
error_reporting(0); ini_set('display_errors', '0'); ini_set('log_errors', '0');
function trafficRebaseGit($root, $arguments) {
    $process = proc_open(array_merge(array('git', '--no-optional-locks', '-C', $root), $arguments),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'a')), $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Git unavailable'); }
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    if (proc_close($process) !== 0) { throw new RuntimeException('Git inspection failed'); }
    return $output;
}
function trafficRebaseEvidence($root, $private) {
    $onto = '1ac6695dc69fc7d72f52aa5929f621ec73623482';
    $original = '07ec37f96dd0c3f9dc0fca256a9d849b37be0a46';
    $operation = $root . '/.git/rebase-merge';
    if (!is_dir($operation) || trim(file_get_contents($operation . '/orig-head')) !== $original
        || trim(file_get_contents($operation . '/onto')) !== $onto
        || trim(trafficRebaseGit($root, array('rev-parse', 'HEAD'))) !== $onto) {
        throw new RuntimeException('Known rebase no longer matches');
    }
    $known = array();
    foreach (array($onto, $original) as $ref) {
        foreach (explode("\0", trafficRebaseGit($root, array('ls-tree', '-rz', $ref))) as $line) {
            if (preg_match('/^([0-9]+) blob ([a-f0-9]+)\t(.+)$/sD', $line, $match)) { $known[$match[3]][$match[1] . ':' . $match[2]] = true; }
        }
    }
    $stages = array(); $unknownIndex = 0;
    foreach (explode("\0", trafficRebaseGit($root, array('ls-files', '--stage', '-z'))) as $line) {
        if (!preg_match('/^([0-9]+) ([a-f0-9]+) ([0-3])\t(.+)$/sD', $line, $match)) { continue; }
        $stages[$match[4]][(int) $match[3]] = array('mode' => $match[1], 'sha' => $match[2]);
        if (!isset($known[$match[4]][$match[1] . ':' . $match[2]])) { $unknownIndex++; }
    }
    $matches = 0; $conflictMatches = 0; $unknownWorking = 0;
    foreach ($stages as $path => $entries) {
        $file = $root . '/' . $path;
        $mode = is_link($file) ? '120000' : ((fileperms($file) & 0111) ? '100755' : '100644');
        $bytes = is_link($file) ? readlink($file) : (is_file($file) ? file_get_contents($file) : false);
        if ($bytes === false) { $unknownWorking++; continue; }
        $sha = sha1('blob ' . strlen($bytes) . "\0" . $bytes);
        if (isset($known[$path][$mode . ':' . $sha])) { $matches++; continue; }
        if (!isset($entries[0]) && $path === 'upload/.htaccess') {
            $directory = $private . '/rebase-compare-' . bin2hex(random_bytes(5)); mkdir($directory, 0700);
            foreach (array(1,2,3) as $stage) {
                file_put_contents($directory . '/' . $stage, isset($entries[$stage]) ? trafficRebaseGit($root, array('cat-file', 'blob', $entries[$stage]['sha'])) : '');
                chmod($directory . '/' . $stage, 0600);
            }
            $process = proc_open(array('git', 'merge-file', '-p', $directory . '/2', $directory . '/1', $directory . '/3'),
                array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'a')), $pipes);
            $merged = stream_get_contents($pipes[1]); fclose($pipes[1]); $code = proc_close($process);
            $normalize = static function ($text) { return preg_replace('/^(<<<<<<<|>>>>>>>) .+$/m', '$1', str_replace("\r\n", "\n", $text)); };
            if ($code >= 0 && $code <= 127 && $normalize($merged) === $normalize($bytes)) { $conflictMatches++; }
            else { $unknownWorking++; }
            foreach (array(1,2,3) as $stage) { unlink($directory . '/' . $stage); } rmdir($directory);
        } else { $unknownWorking++; }
    }
    return array('known_rebase_matches' => true, 'tracked_files' => count($stages), 'public_working_file_matches' => $matches,
        'reproduced_conflict_files' => $conflictMatches, 'unknown_index_entries' => $unknownIndex,
        'unknown_working_files' => $unknownWorking, 'safe_to_recover_known_rebase' => $unknownIndex === 0 && $unknownWorking === 0);
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = getopt('', array('site-root:', 'private-base:'));
        $root = realpath($options['site-root'] ?? ''); $private = realpath($options['private-base'] ?? '');
        if (!$root || !$private || strpos($private . '/', $root . '/') === 0) { throw new RuntimeException('Invalid inspection roots'); }
        echo 'TRAFFIC_REBASE_EVIDENCE_JSON=' . json_encode(trafficRebaseEvidence($root, $private)) . "\n";
    } catch (Throwable $error) { echo 'TRAFFIC_REBASE_EVIDENCE_JSON={"status":"failed","checkout_changed":false}' . "\n"; exit(1); }
}
