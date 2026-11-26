<?php
// php tests/sidebar-menu.php [--guest]: render the real menu partial with each upload policy.
define('IS_LOGGED', !in_array('--guest', $argv, true));
$is_admin = false;
function PT_IsAdmin() { global $is_admin; return $is_admin; }
$pt = (object) array('config' => (object) array('upload_system' => 'on', 'who_upload' => 'all'), 'user' => (object) array('suspend_upload' => false));
$checks = 0;
function expect_upload_link($expected) {
    global $pt, $checks;
    ob_start();
    require dirname(__DIR__) . '/themes/youplay/layout/header/upload-link.html';
    $html = ob_get_clean();
    if ((strpos($html, '{{LINK upload-video}}') !== false) !== $expected) {
        throw new RuntimeException('Upload link did not respect the current permission policy');
    }
    $checks++;
}
expect_upload_link(IS_LOGGED);
$pt->config->who_upload = 'admin';
expect_upload_link(false);
$is_admin = true;
expect_upload_link(IS_LOGGED);
$pt->user->suspend_upload = true;
expect_upload_link(false);
$pt->user->suspend_upload = false;
$pt->config->upload_system = 'off';
expect_upload_link(false);
echo "$checks upload-menu assertions passed (" . (IS_LOGGED ? 'signed in' : 'guest') . ")\n";
