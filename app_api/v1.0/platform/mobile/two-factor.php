<?php
$response_data = array(
    'api_status' => '400', 'api_version' => $api_version,
    'errors' => array('error_id' => '3', 'error_text' => 'Wrong or expired code')
);
if (!isset($_POST['code'], $_POST['user_id']) || !is_string($_POST['code'])
    || !preg_match('/\A[0-9]{6}\z/D', $_POST['code']) || !preg_match('/\A[0-9]+\z/D', (string) $_POST['user_id'])) {
    return;
}
$login = $db->where('id', (int) $_POST['user_id'])->getOne(T_USERS);
$challenge = !empty($login) && is_string($login->email_code) ? $login->email_code : '';
$parts = array();
if (empty($login) || (int) $login->active !== 1 || (int) $login->two_factor !== 1
    || $login->two_factor_method !== 'two_factor' || $pt->config->two_factor_setting !== 'on'
    || !preg_match('/\A2fa:([a-f0-9]{32}):([0-9]{10}):([0-9])\z/D', $challenge, $parts)) {
    return;
}
$age = time() - (int) $parts[2];
$attempts = (int) $parts[3];
$valid = $age >= 0 && $age <= 300 && $attempts < 5
    && hash_equals($parts[1], md5($_POST['code']));
$next = !$valid && $age >= 0 && $age <= 300 && $attempts < 4
    ? '2fa:' . $parts[1] . ':' . $parts[2] . ':' . ($attempts + 1) : '';
// Compare-and-swap prevents code replay and concurrent attempt-counter bypass.
$consumed = $db->where('id', $login->id)->where('email_code', $challenge)
    ->update(T_USERS, array('email_code' => $next));
if (!$valid || !$consumed || $db->count < 1) {
    return;
}
if (!empty($_POST['device_id'])) {
    $db->where('id', $login->id)->update(T_USERS, array('device_id' => PT_Secure($_POST['device_id'])));
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
}
if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
$session_id = bin2hex(random_bytes(32));
foreach (array('phone', 'web') as $platform_name) {
    $insert = $db->insert(T_SESSIONS, array(
        'user_id' => $login->id, 'session_id' => $session_id,
        'time' => time(), 'platform' => $platform_name
    ));
    if (!$insert) {
        return;
    }
}
$response_data = array(
    'api_status' => '200', 'api_version' => $api_version,
    'data' => array('session_id' => $session_id, 'cookie' => $session_id,
        'message' => 'Successfully logged in, Please wait.', 'user_id' => $login->id)
);
