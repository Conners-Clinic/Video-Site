<?php
PT_RequireSameOriginPost($pt->config->site_url);
if (IS_LOGGED == true) {
	header("Location: " . PT_Link(''));
	exit();
}

$color1 = '0095D8';
$color2 = '87ddff';

$errors   = '';
$username = '';
if (!empty($_POST)) {
    if (empty($_POST['username']) || empty($_POST['password'])) {
        $errors = $error_icon . $lang->please_check_details;
    } 
    else {
        if ($pt->config->prevent_system == 1) {
            if (!CheckCanLogin()) {
                $errors = $lang->login_attempts;
            }
        }
        if (empty($errors)) {
            $username        = PT_Secure($_POST['username']);
            $password        = $_POST['password'];
            //$password_hashed = sha1($password);
            $db->where("(username = ? or email = ?)", array(
                $username,
                $username
            ));

            //$db->where("password", $password_hashed);
            $login = $db->getOne(T_USERS);
            
            if (!empty($login)) {
                $logged = PT_VerifyPassword($password, $login->password);
                // Legacy SHA1 accounts historically hashed the SQL/HTML-normalized password.
                if (!$logged && preg_match('/\A[a-f0-9]{40}\z/Di', $login->password)) {
                    $logged = PT_VerifyPassword(PT_Secure($password), $login->password);
                }
                if (preg_match('/\A[a-f0-9]{40}\z/Di', $login->password)) {
                    $provider_sources = array('google', 'facebook', 'twitter', 'vkontakte', 'linkedin', 'instagram', 'qq', 'wechat', 'discord', 'mailru', 'tiktok', 'wowonder');
                    $known_email_password = hash_equals(strtolower($login->password), sha1((string) $login->email));
                    $legacy_provider_password = in_array(strtolower((string) ($login->src ?? '')), $provider_sources, true);
                    // These legacy provider passwords were generated from public email/time data.
                    // A verified password reset issues a modern hash; do not migrate a known weak secret into a new session.
                    if ($known_email_password || $legacy_provider_password) { $logged = false; }
                }
                if ($logged && password_needs_rehash($login->password, PASSWORD_DEFAULT)) {
                    $db->where('id', $login->id)->update(T_USERS, array('password' => password_hash($password, PASSWORD_DEFAULT)));
                }

                if ($logged) {
                    if ((int) $login->active !== 1) {
                        $errors = $error_icon . $lang->account_is_not_active . ' <a href="{{LINK resend/' . $login->email_code . '/' . $login->username . '}}">' . $lang->resend_email . '</a>';
                    } 

                    else {
                        if ((int) $login->two_factor === 1) {
                            if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
                            $_SESSION['pending_login'] = array(
                                'user_id' => (int) $login->id, 'method' => $login->two_factor_method,
                                'issued' => time(), 'attempts' => 0,
                                'remember' => $pt->config->remember_device == 1 && !empty($_POST['remember_device']) && $_POST['remember_device'] === 'on'
                            );
                            $email        = $login->email;
                            $db->where("email", $email);
                            $user_id = $db->getValue(T_USERS, "id");
                            setcookie("two_factor_method", $login->two_factor_method, array('expires' => time() + 300, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'));
                            setcookie("two_factor_username", $login->username, array('expires' => time() + 300, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'));
                            if (!empty($user_id) && $login->two_factor_method == 'two_factor') {
                                   $rest_user = PT_UserData($user_id);
                                   $email_code = random_int(100000, 999999);
                                   $hash_code = '2fa:' . md5((string) $email_code) . ':' . time() . ':0';
                                   $update_data = array('email_code' => $hash_code);
                                   $db->where('id', $rest_user->id);
                                   $update = $db->update(T_USERS, $update_data);
                                   $update_data['USER_DATA'] = $rest_user;
                                   $message = "Your confirmation code is: $email_code";
                                   $send_email_data = array(
                                        'from_email' => $pt->config->email,
                                        'from_name' => $pt->config->name,
                                        'to_email' => $email,
                                        'to_name' => $rest_user->name,
                                        'subject' => 'Confirmation code',
                                        'charSet' => 'UTF-8',
                                        'message_body' => $message,
                                        'is_html' => true
                                    );
                                    $send_message = PT_SendMessage($send_email_data);
                                    if ($send_message) {
                                        $success = $success_icon . $lang->email_sent;
                                    }
                            }
                            header("Location: $site_url/two_factor_login");
                            exit();
                        }
                        if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
                        $session_id          = bin2hex(random_bytes(32));
                        $platform_details = serialize(getBrowser());
                        $insert_data         = array(
                            'user_id' => $login->id,
                            'session_id' => $session_id,
                            'platform_details' => $platform_details,
                            'time' => time()
                        );
                        $insert              = $db->insert(T_SESSIONS, $insert_data);
                        if (!$insert) { http_response_code(503); return; }
            $_SESSION['user_id'] = $session_id;
                        if ($pt->config->remember_device == 1 && !empty($_POST['remember_device']) && $_POST['remember_device'] == 'on') {
                            setcookie("user_id", $session_id, array('expires' => time() + (30 * 24 * 60 * 60), 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'));
                        }
                        $pt->loggedin = true;

                        $redirect_candidate = $_GET['red'] ?? ($_GET['to'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
                        $site_url = PT_SafeAuthRedirect($redirect_candidate, $pt->config->site_url);
                        $db->where('id', $login->id)->update(T_USERS, array('ip_address' => get_ip_address()));
                        header("Location: $site_url");
                        exit();
                    }
                }
                else{
                    $errors = $error_icon . $lang->invalid_username_or_password;
                    if ($pt->config->prevent_system == 1) {
                        AddBadLoginLog();
                    }
                }
            } else {
                $errors = $error_icon . $lang->invalid_username_or_password;
                if ($pt->config->prevent_system == 1) {
                    AddBadLoginLog();
                }
            }
        } 
    }
}
if (empty($_POST) && !empty($_GET['resend'])) {
    $_GET['resend'] = strip_tags($_GET['resend']);
    $errors = $success_icon . $lang->email_sent;
}
$pt->page        = 'login';
$pt->title       = $lang->login . ' | ' . $pt->config->title;
$pt->description = $pt->config->description;
$pt->keyword     = $pt->config->keyword;
$pt->content     = PT_LoadPage('auth/login/content', array(
    'COLOR1' => $color1,
    'COLOR2' => $color2,
    'ERRORS' => $errors,
    'USERNAME' => $username
));