<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/richtext_security.php';
@ini_set('session.use_strict_mode', '1');
@ini_set('session.cookie_secure', '1');
@ini_set('session.cookie_samesite', 'Lax');
@ini_set('session.cookie_httponly',1);
@ini_set('session.use_only_cookies',1);
if (!version_compare(PHP_VERSION, '7.1.0', '>=')) {
    exit("Required PHP_VERSION >= 7.1.0 , Your PHP_VERSION is : " . PHP_VERSION . "\n");
}
if (!function_exists("mysqli_connect")) {
    exit("MySQLi is required to run the application, please contact your hosting to enable php mysqli.");
}
date_default_timezone_set('UTC');
session_start();
require('assets/includes/functions_general.php');
require('assets/includes/tables.php');
require('assets/includes/functions_one.php');
require('assets/includes/functions_transcribe.php');
require('assets/includes/functions_transcribe_monitor.php');
require('assets/includes/functions_seo_pipeline.php');

function durationToSeconds($input) {
    if (!is_string($input)) return 0;

    $input = trim($input);
    if ($input === '') return 0;

    $parts = explode(':', $input);
    if (count($parts) < 2 || count($parts) > 3) return 0;

    foreach ($parts as $p) {
        if ($p === '' || !ctype_digit($p)) return 0;
    }

    $parts = array_map('intval', $parts);

    // mm:ss
    if (count($parts) === 2) {
        if ($parts[1] >= 60) return 0;
        return ($parts[0] * 60) + $parts[1];
    }

    // hh:mm:ss
    if ($parts[1] >= 60 || $parts[2] >= 60) return 0;
    return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
}
$pt->admin = (IS_LOGGED && isset($pt->user->admin) && $pt->user->admin === 1);