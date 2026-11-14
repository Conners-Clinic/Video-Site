<?php
// Administrative backend permissions are tied to reviewed actions, never a request page name.
function PT_AdminSecurityMovieTargets($values) {
    global $db;
    $ids = PT_SecurityIdList($values);
    if ($ids === false || empty($ids) || in_array(0, $ids, true)) { return false; }
    $videos = $db->where('id', $ids, 'IN')->get(T_VIDEOS, null, array('id', 'is_movie'));
    if (!is_array($videos) || count($videos) !== count($ids)) { return false; }
    foreach ($videos as $video) {
        $id = is_array($video) ? ($video['id'] ?? 0) : ($video->id ?? 0);
        $movie = is_array($video) ? ($video['is_movie'] ?? 0) : ($video->is_movie ?? 0);
        if (!in_array((int) $id, $ids, true) || (string) $movie !== '1') { return false; }
    }
    return true;
}
function PT_AdminActionAllowed($action) {
    global $pt;
    if (!IS_LOGGED || empty($pt->user)) { return false; }
    $role = isset($pt->user->admin) && (is_string($pt->user->admin) || is_int($pt->user->admin))
        ? (string) $pt->user->admin : '';
    if ($role === '1') { return true; }
    if (!in_array($role, array('2', '3'), true) || !is_string($action)) { return false; }
    // These constant page names correspond to the existing content-management screens.
    $pages = array(
        'change_cats' => 'manage-videos',
        'change_status' => 'manage-videos',
        'delete-video' => 'manage-videos',
        'videos-status' => 'manage-videos',
        'delete-multi-videos' => 'manage-videos',
        'remove_multi_movie' => 'manage-movies',
        'delete-comment' => 'manage-comments',
        'remove_multi_comment' => 'manage-comments',
        'delete-activity' => 'manage-activities',
        'remove_multi_activity' => 'manage-activities',
        'new-article' => 'create-article',
        'update-article' => 'edit-article',
        'delete-article' => 'manage-articles',
        'delete-multi-article' => 'manage-articles',
        'reports' => 'manage-video-reports',
        'delete-multi-report' => 'manage-video-reports',
        'copy_reports' => 'copy_report',
        'remove_multi_copy' => 'copy_report'
    );
    if (!isset($pages[$action])) { return false; }
    // The movie screen shares delete-video with the general video screen.
    if ($action === 'delete-video' && !CheckHavePermission('manage-videos')) {
        return CheckHavePermission('manage-movies')
            && PT_AdminSecurityMovieTargets(array($_POST['id'] ?? null));
    }
    if (!CheckHavePermission($pages[$action])) { return false; }
    if ($action === 'remove_multi_movie') {
        return PT_AdminSecurityMovieTargets($_POST['ids'] ?? null);
    }
    return true;
}
function PT_RequireAdminAction($action) {
    if (!PT_AdminActionAllowed($action)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('status' => 403, 'message' => 'Permission denied for this administrative action'));
        exit();
    }
}
