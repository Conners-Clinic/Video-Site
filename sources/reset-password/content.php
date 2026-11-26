<?php
PT_RequireSameOriginPost($pt->config->site_url);
if (IS_LOGGED == true) {
	header("Location: " . PT_Link(''));
	exit();
}

if (empty($_GET['code'])) {
   header("Location: " . PT_Link(''));
   exit();
}
$code = is_string($_GET['code']) ? $_GET['code'] : '';
$parts = array();
$valid_reset_code = preg_match('/\Arst:([a-f0-9]{32}):([0-9]{10})\z/D', $code, $parts)
    && time() - (int) $parts[2] >= 0 && time() - (int) $parts[2] <= 3600;
$user_id = $valid_reset_code ? $db->where('email_code', $code)->getValue(T_USERS, 'id') : null;
$error_code = empty($user_id);
$color1 = '609b41';
$color2 = '8ad363';
$errors   = array();
$erros_final = '';
$success = '';
if (!empty($_POST) && $error_code == false) {
    if (empty($_POST['password']) || empty($_POST['re-password'])) {
        $errors[] = $error_icon . $lang->please_check_details;
    } else {
    	$password        = $_POST['password'];
        $c_password      = $_POST['re-password'];
        $password_hashed = password_hash($password, PASSWORD_DEFAULT);
        if ($password != $c_password) {
            $errors[] = $lang->password_not_match;
        } else if (strlen($password) < 6 || strlen($password) > 72) {
            $errors[] = $lang->password_is_short;
        }
        if (empty($errors)) {
        	$email_code = '';
        	$db->where('id', $user_id)->where('email_code', $code);
        	$update_data = array('password' => $password_hashed, 'email_code' => $email_code);
        	$update = $db->update(T_USERS, $update_data);
        	if ($update && $db->count > 0) {
                $db->where('user_id', $user_id)->delete(T_SESSIONS);
                unset($_SESSION['user_id']);
                if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
                setcookie('user_id', '', array('expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'));
                // A password reset must not bypass the account's configured second factor.
                header('Location: ' . PT_Link('login?reset=success'));
                exit();
        	}
        }
    }
}
$pt->page        = 'login';
$pt->title       = $lang->change_password . ' | ' . $pt->config->title;
$pt->description = $pt->config->description;
$pt->keyword     = $pt->config->keyword;

if (!empty($errors)) {
    foreach ($errors as $key => $error) {
        $erros_final .= $error_icon . $error . "<br>";
    }
}

$page = 'content';
if ($error_code == true) {
	$page = 'invalid';
}
$pt->content     = PT_LoadPage('auth/reset-password/' . $page, array(
    'COLOR1' => $color1,
    'COLOR2' => $color2,
    'ERRORS' => $erros_final,
    'SUCCESS' => $success,
    'CODE' => $code
));