<?php
// +------------------------------------------------------------------------+
// | @author Deen Doughouz (DoughouzForest)
// | @author_url 1: http://www.playtubescript.com
// | @author_url 2: http://codecanyon.net/user/doughouzforest
// | @author_email: wowondersocial@gmail.com   
// +------------------------------------------------------------------------+
// | PlayTube - The Ultimate Video Sharing Platform
// | Copyright (c) 2017 PlayTube. All rights reserved.
// +------------------------------------------------------------------------+
require_once('assets/init.php');
// Existing provider links must initiate a supported OAuth state flow before callbacks are accepted.
if ($pt->config->wowonder_login !== 'on' || empty($_GET['state']) || !is_string($_GET['state'])
    || !isset($_SESSION['wowonder_oauth_state']) || !is_string($_SESSION['wowonder_oauth_state'])
    || !hash_equals($_SESSION['wowonder_oauth_state'], $_GET['state'])) {
    http_response_code(403);
    exit('Use your account password to sign in. OAuth provider setup requires a verified state flow.');
}
unset($_SESSION['wowonder_oauth_state']);

if (isset($_GET['code']) && !empty($_GET['code'])) {
    $app_id        = $pt->config->wowonder_app_ID;
    $app_secret    = $pt->config->wowonder_app_key;
    $wowonder_url  = $pt->config->wowonder_domain_uri;
    if (!is_string($_GET['code']) || stripos((string) $wowonder_url, 'https://') !== 0) {
        http_response_code(403);
        exit('Use your account password to sign in.');
    }
    $code = rawurlencode($_GET['code']);
    $app_id = rawurlencode((string) $app_id);
    $app_secret = rawurlencode((string) $app_secret);
    $provider_context = stream_context_create(array('http' => array('timeout' => 10, 'follow_location' => 0), 'ssl' => array('verify_peer' => true, 'verify_peer_name' => true)));
    $url           = $wowonder_url . "/authorize?app_id={$app_id}&app_secret={$app_secret}&code={$code}";
    $get           = file_get_contents($url, false, $provider_context);
    $wo_json_reply = json_decode($get, true);
    $access_token  = '';
    if (is_array($wo_json_reply) && isset($wo_json_reply['access_token'])) {
        $access_token    = rawurlencode((string) $wo_json_reply['access_token']);
        $type            = "get_user_data";
        $url             = $wowonder_url . "/api_request?access_token={$access_token}&type={$type}";
        $user_data_json  = file_get_contents($url, false, $provider_context);
        $user_data_array = json_decode($user_data_json, true);
        if (is_array($user_data_array) && !empty($user_data_array) && isset($user_data_array['user_data'])) {
            $user_data  = $user_data_array['user_data'];
            $user_email = $user_data['email'];
            if (!is_string($user_email) || !filter_var($user_email, FILTER_VALIDATE_EMAIL)
                || !in_array($user_data['email_verified'] ?? null, array(true, 1, '1', 'true'), true) || empty($user_data['user_id'])) {
                http_response_code(403);
                exit('Use your account password to sign in.');
            }
            if (PT_UserEmailExists($user_email) === true) {
                http_response_code(403);
                exit('Use your account password to sign in; email-only account linking is disabled.');
            } else {
                $str          = bin2hex(random_bytes(16));
                $id           = substr($str, 0, 9);
                $user_uniq_id = (PT_UsernameExists($id) === false) ? $id : 'u_' . $id;
                $first_name   = (isset($user_data['first_name'])) ? PT_Secure($user_data['first_name'], 0) : '';
                $last_name    = (isset($user_data['last_name'])) ? PT_Secure($user_data['last_name'], 0) : '';
                $gender       = (isset($user_data['gender'])) ? PT_Secure($user_data['gender'], 0) : 'male';
                $username     = (PT_Secure($user_data['username']));
                $provider     = ($wowonder_url . "/{$username}");
                $re_data      = array(
                    'username' => PT_Secure($user_uniq_id, 0),
                    'email' => PT_Secure($user_email, 0),
                    'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                    'email_code' => PT_Secure(md5($user_uniq_id), 0),
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'gender' => PT_Secure($gender),
                    'active' => '1'
                );
                if (!empty($user_data['avatar'])) {
                    $re_data['avatar'] = PT_Secure(PT_ImportImageFromLogin($user_data['avatar']));
                }
                $insert_id = $db->insert(T_USERS, $re_data);
                if ($insert_id) {
                    if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
                    $session_id          = bin2hex(random_bytes(32));
                    $insert_data         = array(
                        'user_id' => $insert_id,
                        'session_id' => $session_id,
                        'time' => time()
                    );
                    $insert              = $db->insert(T_SESSIONS, $insert_data);
                    if (!$insert) { http_response_code(503); return; }
            $_SESSION['user_id'] = $session_id;
                    setcookie("user_id", $session_id, array('expires' => time() + (30 * 24 * 60 * 60), 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'));
                    header("Location: " . PT_Link(''));
                    exit();
                }
            }
        }
    } else {
        echo 'Error found, please try again later.' . "<a href='" . $pt->config->site_url . "'>Return back</a>";
    }
} else {
    echo "<a href='" . $pt->config->site_url . "'>Return back</a>";
}
?>