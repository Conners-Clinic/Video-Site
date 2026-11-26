<?php
$types = array('generate','login');
if (!empty($_POST['type']) && in_array($_POST['type'], $types)) {
	if ($_POST['type'] == 'generate') {
		if (!IS_LOGGED) {
			$response_data    = array(
			    'api_status'  => '400',
			    'api_version' => $api_version,
			    'errors' => array(
		            'error_id' => '1',
		            'error_text' => 'Not logged in'
		        )
			);
		}
		else{
			$code = bin2hex(random_bytes(5));
			$db->where('id',$pt->user->id)->update(T_USERS,array('tv_code' => $code));
			$response_data     = array(
			    'api_status'   => '200',
			    'api_version'  => $api_version,
			    'success_type' => 'success',
			    'code'    => $code,
			);
		}
	}
	elseif ($_POST['type'] == 'login') {
		if (!empty($_POST['code']) && is_string($_POST['code']) && preg_match('/\A[a-f0-9]{10}\z/D', $_POST['code'])) {
			$code = PT_Secure($_POST['code']);
			$user = $db->where('tv_code',$code)->getOne(T_USERS);
            // A pairing code may authorize only one login attempt.
            if (!empty($user) && (strlen($code) > 10 || !$db->where('id', $user->id)->where('tv_code', $code)->update(T_USERS, array('tv_code' => '')) || $db->count < 1)) {
                $user = null;
            }
			if (!empty($user)) {
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
		    else {
		        $response_data           = array(
		            'api_status'         => '400',
		            'api_version'        => $api_version,
		            'errors'             => array(
		                'error_id'       => '4',
		                'error_text'     => 'user not found'
		            ) 
		        );
		    }
		}
		else{
			$response_data       = array(
		        'api_status'     => '400',
		        'api_version'    => $api_version,
		        'errors'         => array(
		            'error_id'   => '3',
		            'error_text' => 'code can not be empty'
		        )
		    );
		}
	}
}
else{
	$response_data       = array(
        'api_status'     => '400',
        'api_version'    => $api_version,
        'errors'         => array(
            'error_id'   => '2',
            'error_text' => 'type can not be empty'
        )
    );
}