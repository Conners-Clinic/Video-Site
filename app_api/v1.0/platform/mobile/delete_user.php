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

if (!IS_LOGGED) {
	$response_data    = array(
	    'api_status'  => '400',
	    'api_version' => $api_version,
	    'errors' => array(
            'error_id' => '1',
            'error_text' => 'Not logged in'
        )
	);
} else if (empty($_POST['id']) || !is_numeric($_POST['id']) || empty($_POST['current_password'])){

	$response_data    = array(
	    'api_status'  => '400',
	    'api_version' => $api_version,
	    'errors' => array(
            'error_id' => '2',
            'error_text' => 'Bad Request, Invalid or missing parameter'
        )
	);

} else {

	$actor = $pt->user;
	$id = (int) $_POST['id'];
	$target_user = PT_UserData($id);

	$request = (!empty($actor->id) && !empty($target_user->id) && (PT_IsAdmin() || ((int) $actor->id === $id)));

	if ($request === true) {
		if (!PT_VerifyPassword($_POST['current_password'], $actor->password)) {
			$response_data       = array(
		        'api_status'     => '404',
		        'api_version'    => $api_version,
		        'errors'         => array(
		            'error_id'   => '4',
		            'error_text' => 'Current password is incorrect'
		        )
		    );
        } else {
        	$delete = PT_DeleteUser($target_user->id);
            if ($delete) {
                $response_data     = array(
				    'api_status'   => '200',
				    'api_version'  => $api_version,
				    'success_type' => 'deleted',
				    'message'      => 'Your account was successfully deleted'
				);
            }
        }
	} else {
		$response_data       = array(
	        'api_status'     => '404',
	        'api_version'    => $api_version,
	        'errors'         => array(
	            'error_id'   => '3',
	            'error_text' => 'User does not exist'
	        )
	    );
	}
}
