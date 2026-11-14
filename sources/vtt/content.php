<?php
require_once dirname(__DIR__, 2) . '/assets/includes/video_security.php';
header('Content-Type: text/vtt; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$public_id = $_GET['id'] ?? '';
if (!is_string($public_id) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $public_id)) {
    http_response_code(404); echo "WEBVTT\n\n"; exit();
}
$video = $db->where('video_id', $public_id)->getOne(T_VIDEOS);
if (empty($video) || !PT_SecurityCanViewVideo($video)) {
    http_response_code(404); echo "WEBVTT\n\n"; exit();
}
$transcript = PT_GetVideoTranscript((int) PT_VideoSecurityValue($video, 'id'));
$expected = 'upload/transcripts/' . $public_id . '.vtt';
if (empty($transcript) || PT_VideoSecurityValue($transcript, 'status') !== 'completed'
    || PT_VideoSecurityValue($transcript, 'vtt_path') !== $expected) {
    http_response_code(404); echo "WEBVTT\n\n"; exit();
}
$local = PT_SecurityTranscriptPath($expected, PT_TranscriptRootDir());
if ($local !== false) {
    readfile($local);
    exit();
}
if (!empty($pt->remoteStorage)) {
    $url = PT_GetMedia($expected);
    $vtt = is_string($url) ? PT_SecurityRemoteRequest($url) : false;
    if (is_string($vtt) && strlen($vtt) <= 32 * 1024 * 1024 && strncmp(ltrim($vtt, "\xEF\xBB\xBF"), 'WEBVTT', 6) === 0) {
        // Proxy through the same access checks; do not expose a raw storage redirect.
        echo $vtt;
        exit();
    }
}
http_response_code(404);
echo "WEBVTT\n\n";
exit();
