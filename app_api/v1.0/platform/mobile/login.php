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

if (empty($_POST['username']) || empty($_POST['password'])) {
    $response_data       = array(
        'api_status'     => '400',
        'api_version'    => $api_version,
        'errors'         => array(
            'error_id'   => '1',
            'error_text' => 'Please enter your username and password'
        )
    );
}

else {
    if ($pt->config->prevent_system == 1) {
        if (!CheckCanLogin()) {
            $response_data       = array(
                'api_status'     => '400',
                'api_version'    => $api_version,
                'errors'         => array(
                    'error_id'   => '2',
                    'error_text' => 'Too many login attempts please try again later'
                )
            );
            echo json_encode($response_data, JSON_PRETTY_PRINT);
            exit();
        }
    }
    $username        = PT_Secure($_POST['username']);
    $password        = $_POST['password'];
    //$password_hashed = sha1($password);

    $db->where("(username = ? or email = ? or phone_number = ?)", array($username,$username,$username));
    //$db->where("password", $password_hashed);
    $user = $db->getOne(T_USERS);

    if (!empty($user)) {
        $logged = PT_VerifyPassword($password, $user->password);
        if (!$logged && is_string($password) && preg_match('/\A[a-f0-9]{40}\z/Di', $user->password)) {
            $logged = PT_VerifyPassword(PT_Secure($password), $user->password);
        }
        if (preg_match('/\A[a-f0-9]{40}\z/Di', $user->password)) {
            $provider_sources = array('google', 'facebook', 'twitter', 'vkontakte', 'linkedin', 'instagram', 'qq', 'wechat', 'discord', 'mailru', 'tiktok', 'wowonder');
            $known_email_password = hash_equals(strtolower($user->password), sha1((string) $user->email));
            $legacy_provider_password = in_array(strtolower((string) ($user->src ?? '')), $provider_sources, true);
            // These legacy provider passwords were generated from public email/time data.
            // A verified password reset issues a modern hash; do not migrate a known weak secret into a new session.
            if ($known_email_password || $legacy_provider_password) { $logged = false; }
        }
        if ($logged && password_needs_rehash($user->password, PASSWORD_DEFAULT)) {
            $db->where('id', $user->id)->update(T_USERS, array('password' => password_hash($password, PASSWORD_DEFAULT)));
        }

        if ($logged) {
            if ((int) $user->active !== 1) {
                $response_data       = array(
                    'api_status'     => '304',
                    'api_version'    => $api_version,
                    'success_type'   => 'confirm_account',
                    'errors'         => array(
                        'error_id'   => '2',
                        'error_text' => 'Your account is not active yet, please confirm your E-mail',
                        'email'      => $user->email
                    ) 
                );
            } 
            elseif ((int) $user->two_factor === 1) {
                if ($pt->config->two_factor_setting !== 'on' || $user->two_factor_method !== 'two_factor') {
                    $response_data = array('api_status' => '400', 'api_version' => $api_version,
                        'errors' => array('error_id' => '2', 'error_text' => 'Complete two-factor sign-in on the website.'));
                    return;
                }
                $email        = $user->email;
                $db->where("email", $email);
                $user_id = $db->getValue(T_USERS, "id");
                if (!empty($user_id)) {
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
                        $response_data     = array(
                            'api_status'   => '200',
                            'api_version'  => $api_version,
                            'success_type' => 'confirmation_email',
                            'user_id' => $user_id,
                            'message'      => 'Please enter your confirmation code.'
                        );
                    }
                }
            }
            
            else {
                if (!empty($_POST['device_id'])) {
                    $db->where('id',$user->id)->update(T_USERS,array('device_id' => PT_Secure($_POST['device_id'])));
                }
                if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
                $session_id          = bin2hex(random_bytes(32));
                $platforms           = array('phone','web');
                foreach ($platforms as $platform_name) {
                    $insert_data     = array(
                        'user_id'    => $user->id,
                        'session_id' => $session_id,
                        'time'       => time(),
                        'platform'   => $platform_name
                    );

                    $insert = $db->insert(T_SESSIONS, $insert_data);
                    if (!$insert) { $response_data = array('api_status' => '503', 'api_version' => $api_version); return; }
                }

                $response_data       = array(
                    'api_status'     => '200',
                    'api_version'    => $api_version,
                    'success_type'   => 'logged_in',
                    'data'           => array(
                        'session_id' => $session_id,
                        'message'    => 'Successfully logged in, Please wait.',
                        'user_id'    => $user->id,
                        'cookie'     => $session_id
                    ) 
                );
            }

        }
        else{
            $response_data           = array(
                'api_status'         => '400',
                'api_version'        => $api_version,
                'errors'             => array(
                    'error_id'       => '3',
                    'error_text'     => 'Invalid username or password'
                ) 
            );
            if ($pt->config->prevent_system == 1) {
                AddBadLoginLog();
            }
        }
    } 
    else {
        $response_data           = array(
            'api_status'         => '400',
            'api_version'        => $api_version,
            'errors'             => array(
                'error_id'       => '3',
                'error_text'     => 'Invalid username or password'
            ) 
        );
        if ($pt->config->prevent_system == 1) {
            AddBadLoginLog();
        }
    }
}