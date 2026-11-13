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

# Include Initial Modules
require('assets/api-v1.0-config.php');
require('assets/api-v1.0-init.php');


if ($application == 'phone') {

	$appmod = "app_api/v$api_version/platform/mobile/$type.php";

	if (is_string($type) && preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $type) &&
        is_file($appmod) && dirname(realpath($appmod)) === realpath("app_api/v$api_version/platform/mobile")) {
		require_once $appmod;
	}

	else{
		$response_data       = array(
	        'api_status'     => '400',
	        'api_version'    => $api_version,
	        'errors'         => array(
	            'error_id'   => '1',
	            'error_text' => 'Error: 400 Bad request, no type specified!'
	        )
	    );
	}	
} 

else if ($application == 'desktop'){
	/* .... */
}