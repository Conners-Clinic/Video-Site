<?php
PT_RequireSameOriginPost($pt->config->site_url);
$pending = $_SESSION['pending_login'] ?? null;
if (IS_LOGGED || !is_array($pending) || empty($pending['user_id'])
    || time() - (int) $pending['issued'] < 0 || time() - (int) $pending['issued'] > 300
    || (int) $pending['attempts'] >= 5 || !isset($_POST['code']) || !is_string($_POST['code'])
    || strlen($_POST['code']) > 128) {
    unset($_SESSION['pending_login']);
    header('Location: ' . PT_Link('login'));
    exit();
}
$user = $db->where('id', (int) $pending['user_id'])->getOne(T_USERS);
if (empty($user) || (int) $user->active !== 1 || (int) $user->two_factor !== 1
    || $user->two_factor_method !== $pending['method']) {
    unset($_SESSION['pending_login']);
    header('Location: ' . PT_Link('login'));
    exit();
}
$_SESSION['pending_login']['attempts']++;
$error = '';
$verify = false;
if ($pt->config->prevent_system == 1 && !CheckCanLogin()) {
    $error = $lang->login_attempts;
}
if (empty($error)) {
    if ($user->two_factor_method === 'google' || $user->two_factor_method === 'authy') {
        $codes = $db->where('user_id', $user->id)->getOne(T_BACKUP_CODES);
        if (!empty($codes) && is_string($codes->codes)) {
            $backup_codes = json_decode($codes->codes, true);
            if (is_array($backup_codes)) {
                $backup_codes = array_map('strval', array_filter($backup_codes, static function ($code) { return is_string($code) || is_int($code); }));
            }
            if (is_array($backup_codes) && in_array($_POST['code'], $backup_codes, true)) {
                $key = array_search($_POST['code'], $backup_codes, true);
                unset($backup_codes[$key]);
                $updated = $db->where('user_id', $user->id)->where('codes', $codes->codes)
                    ->update(T_BACKUP_CODES, array('codes' => json_encode(array_values($backup_codes))));
                $verify = $updated && $db->count > 0;
            }
        }
    }
    if ($user->two_factor_method === 'two_factor' && $pt->config->two_factor_setting === 'on') {
        $parts = array();
        $challenge = $user->email_code;
        if (preg_match('/\A2fa:([a-f0-9]{32}):([0-9]{10}):([0-9])\z/D', $challenge, $parts)) {
            $age = time() - (int) $parts[2];
            $attempts = (int) $parts[3];
            $valid = preg_match('/\A[0-9]{6}\z/D', $_POST['code']) && $age >= 0 && $age <= 300 && $attempts < 5
                && hash_equals($parts[1], md5($_POST['code']));
            $next = !$valid && $age >= 0 && $age <= 300 && $attempts < 4
                ? '2fa:' . $parts[1] . ':' . $parts[2] . ':' . ($attempts + 1) : '';
            $updated = $db->where('id', $user->id)->where('email_code', $challenge)
                ->update(T_USERS, array('email_code' => $next));
            $verify = $valid && $updated && $db->count > 0;
        }
    } elseif (!$verify && $user->two_factor_method === 'google' && $pt->config->google_authenticator === 'on' && !empty($user->google_secret)) {
        require_once 'assets/libs/google_auth/vendor/autoload.php';
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $verify = $google2fa->verifyKey($user->google_secret, $_POST['code']);
    } elseif (!$verify && $user->two_factor_method === 'authy' && $pt->config->authy_settings === 'on' && !empty($user->authy_id)) {
        $verify = verifyAuthy($_POST['code'], $user->authy_id);
    }
}
if ($verify) {
    unset($_SESSION['pending_login']);
    if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
    $session_id = bin2hex(random_bytes(32));
    $insert = $db->insert(T_SESSIONS, array('user_id' => $user->id, 'session_id' => $session_id, 'time' => time()));
    if ($insert) {
        $_SESSION['user_id'] = $session_id;
        $cookie_expiry = !empty($pending['remember']) ? time() + (30 * 24 * 60 * 60) : 0;
        setcookie('user_id', $session_id, array('expires' => $cookie_expiry, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'));
        $pt->loggedin = true;
        $db->where('id', $user->id)->update(T_USERS, array('ip_address' => get_ip_address()));
        header('Location: ' . PT_SafeAuthRedirect($_GET['to'] ?? '', $pt->config->site_url));
        exit();
    }
}
if (empty($error)) { $error = $lang->wrong_confirm_code; }
if ($pt->config->prevent_system == 1) { AddBadLoginLog(); }
if (isset($_SESSION['pending_login']) && $_SESSION['pending_login']['attempts'] >= 5) { unset($_SESSION['pending_login']); }
$two_factor_method = $pending['method'];
$message = $lang->sent_two_factor_email;
if ($two_factor_method === 'authy') { $message = $lang->use_authy_app; }
elseif ($two_factor_method === 'google') { $message = $lang->use_google_authenticator_app; }
$pt->page = 'login';
$pt->title = $lang->two_factor . ' | ' . $pt->config->title;
$pt->description = $pt->config->description;
$pt->keyword = $pt->config->keyword;
$pt->content = PT_LoadPage('auth/two_factor_login/content', array('MESSAGE' => $message, 'ERROR' => $error));
