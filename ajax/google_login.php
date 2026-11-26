<?php
function PT_GoogleLoginClaimsValid($claims, $client_id, $now) {
    return is_object($claims) && empty($claims->error) && is_string($client_id) && $client_id !== ''
        && isset($claims->aud, $claims->iss, $claims->exp, $claims->sub, $claims->email, $claims->email_verified)
        && is_string($claims->aud) && hash_equals($client_id, $claims->aud)
        && in_array($claims->iss, array('accounts.google.com', 'https://accounts.google.com'), true)
        && is_numeric($claims->exp) && (int) $claims->exp > $now
        && is_string($claims->sub) && preg_match('/\A[0-9]{1,50}\z/D', $claims->sub)
        && is_string($claims->email) && filter_var($claims->email, FILTER_VALIDATE_EMAIL)
        && in_array($claims->email_verified, array(true, 'true', 1, '1'), true);
}
$data = array('status' => 400, 'message' => 'Unable to sign in; use your account password.');
if (!IS_LOGGED && $pt->config->plus_login == 'on' && !empty($pt->config->google_app_ID) && !empty($pt->config->google_app_key) && !empty($_POST['id_token'])) {
    $data['status']   = 400;
    $access_token = $_POST['id_token'];
    if (!is_string($access_token) || strlen($access_token) > 8192) {
        echo json_encode($data);
        exit();
    }
    $curl = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($access_token));
    curl_setopt_array($curl, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2
    ));
    $verified_response = curl_exec($curl);
    $verified_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $json_data = is_string($verified_response) ? json_decode($verified_response) : null;
    if ($verified_status === 200 && PT_GoogleLoginClaimsValid($json_data, (string) $pt->config->google_app_ID, time())) {
        $social_id = $json_data->sub;
        $social_email = $json_data->email;
        $social_name = isset($json_data->name) && is_string($json_data->name) ? $json_data->name : '';
    }
    if (!empty($social_id)) {
        $create_session = false;
        if (PT_UserEmailExists($social_email) === true) {
            $bound_user = $db->where('email', $social_email)->where('src', 'Google')->where('google', $social_id)->getOne(T_USERS);
            if (!empty($bound_user) && (int) $bound_user->active === 1 && (int) $bound_user->two_factor !== 1) {
                $insert_id = $bound_user->id;
                $create_session = true;
            }
        } else {
            $str          = bin2hex(random_bytes(16));
            $id           = substr($str, 0, 9);
            $user_uniq_id = (empty($db->where('username', $id)->getValue(T_USERS, 'id'))) ? $id : 'u_' . $id;
            $password     = bin2hex(random_bytes(32));
            $re_data      = array(
                'username' => PT_Secure($user_uniq_id, 0),
                'email' => PT_Secure($social_email, 0),
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'email_code' => PT_Secure(sha1($user_uniq_id), 0),
                'first_name' => PT_Secure($social_name),
                'src' => 'Google',
                'google' => $social_id,
                'active' => '1'
            );
            $re_data['language'] = $pt->config->language;
            if (!empty($_SESSION['lang'])) {
                if (in_array($_SESSION['lang'], $langs)) {
                    $re_data['language'] = $_SESSION['lang'];
                }
            }
            $insert_id = $db->insert(T_USERS, $re_data);
            if (!empty($insert_id)) {
                $create_session = true;
            }
        }
        if ($create_session == true) {

        	if (!empty($pt->config->auto_subscribe)) {
                $get_users = explode(',', $pt->config->auto_subscribe);
                foreach ($get_users as $key => $username) {
                    $user  = $db->where('username', $username)->getOne(T_USERS);
                    if (!empty($user)) {
                        $insert_data         = array(
                            'user_id' => $user->id,
                            'subscriber_id' => $insert_id,
                            'time' => time(),
                            'active' => 1
                        );
                        $create_subscription = $db->insert(T_SUBSCRIPTIONS, $insert_data);
                        if ($create_subscription) {
                            $current_user = $db->where('id', $insert_id)->getOne(T_USERS);
                            $data = array(
                                'status' => 200
                            );

                            $notif_data = array(
                                'notifier_id' => $insert_id,
                                'recipient_id' => $user->id,
                                'type' => 'subscribed_u',
                                'url' => ('@' . $current_user->username),
                                'time' => time()
                            );

                            pt_notify($notif_data);
                        }
                    }
                }
            }

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
            $data['status'] = 200;
            $data['location'] = $site_url;
        } else {
            $data['message'] = $lang->error_msg;
        }
    } else {
        $data['message'] = $lang->error_msg;
    }
}
header("Content-type: application/json");
echo json_encode($data);
exit();