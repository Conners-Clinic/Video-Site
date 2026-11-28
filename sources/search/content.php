<?php
// Categories are database identifiers, never SQL fragments.
if (isset($_POST['category']) && $_POST['category'] !== '') {
    $security_ids = PT_SecurityIdList($_POST['category'], true);
    if ($security_ids === false) { PT_SecurityBadInput(); }
    $_POST['category'] = $security_ids;
}
$term = PT_SearchTerm($_GET['keyword'] ?? null);
if ($term === null) {
    http_response_code(400);
    $pt->page = 'search';
    $pt->title = $lang->search . ' | ' . $pt->config->title;
    $pt->description = $pt->config->description;
    $pt->keyword = $pt->config->keyword;
    $pt->content = '<div class="content"><p>Enter 2–200 characters and no more than 12 words to search.</p></div>';
    return;
}
$_GET['keyword'] = $term;
$keyword = htmlspecialchars($term, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$list = '<div class="text-center no-content-found empty_state"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-video-off"><path d="M16 16v1a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2m5.66 0H14a2 2 0 0 1 2 2v3.34l1 1L23 7v10"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>' . $lang->no_videos_found_for_now . '</div>';
$list2 = '<div class="text-center no-content-found empty_state"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-video-off"><path d="M16 16v1a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2m5.66 0H14a2 2 0 0 1 2 2v3.34l1 1L23 7v10"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg></div>';
$final = '';
$final2 = '';
$category = '';
$date = '';
$category_id = '';
// Keep numbered pages while bounding each response and pathological offsets.
$pt->page_number = min(10000, max(1, (int) ($_GET['page_id'] ?? 1)));
$pt->limit_per_page = min(50, max(1, (int) ($pt->config->videos_load_limit ?: 20)));
$search_options = array(
    'admin' => $pt->admin === true, 'blocked' => $pt->blocked_array,
    'categories' => $_POST['category'] ?? array(),
    'date' => is_string($_POST['date'] ?? null) ? $_POST['date'] : '',
    'page' => $pt->page_number, 'limit' => $pt->limit_per_page
);
$category_id = implode(',', (array) $search_options['categories']);
$pt->total_pages = (int) ceil(PT_SearchVideoCount($db, $term, $search_options) / $pt->limit_per_page);
$get_videos = PT_SearchVideoRows($db, $term, $search_options);
if (!empty($get_videos)) {
    $len = count($get_videos);
    foreach (PT_VideoCards($get_videos) as $key => $video) {
        $pt->last_video = false;
        if ($key == $len - 1) {
            $pt->last_video = true;
        }
        $pt->q = $video;
        $final .= PT_LoadPage('search/list', array(
            'ID' => $video->id,
            'USER_DATA' => $video->owner,
            'THUMBNAIL' => $video->thumbnail,
            'URL' => $video->url,
            'ajax_url' => $video->ajax_url,
            'TITLE' => $video->title,
            'DESC' => $video->list_description,
            'VIEWS' => $video->views,
            'VIEWS_NUM' => number_format($video->views),
            'TIME' => $video->time_ago,
            'DURATION' => $video->duration,
            'VIDEO_ID_' => PT_Slug($video->title, $video->video_id),
            'GIF' => $video->gif
        ));
    }
}
if (empty($final)) {
	$final = $list;
}

list($username_match, $username_params) = PT_SearchColumnMatch('username', $term);
list($name_match, $name_params) = PT_SearchColumnMatch("CONCAT(first_name, ' ', last_name)", $term);
$get_users = $db->objectBuilder()->rawQuery("SELECT id, username, first_name, last_name, avatar, verified FROM " . T_USERS . " WHERE (" . $username_match . ' OR ' . $name_match . ') ORDER BY id ASC LIMIT 50', array_merge($username_params, $name_params));
if (!empty($get_users)) {
    $len = count($get_users);
    foreach ($get_users as $key => $user) {
        $user = PT_FormatCardOwner($user);
        $pt->last_user = false;
        if ($key == $len - 1) {
            $pt->last_user = true;
        }
        $final2 .= PT_LoadPage('search/user-list', array(
            'ID' => $user->id,
            'USER_DATA' => $user,
        ));
    }
}
// print_r($_POST);
// exit();
if (empty($final2)) {
    $final2 = $list2;
}


$pt->videos      = $get_videos;
$pt->users      = $get_users;
$pt->page        = 'search';
$pt->title       = $lang->search . ' | ' . $pt->config->title;
$pt->description = $pt->config->description;
$pt->keyword     = $pt->config->keyword;
$pt->content     = PT_LoadPage('search/content', array(
    'VIDEOS' => $final,
    'USERS' => $final2,
    'KEYWORD' => $keyword,
    'CAT' => $category_id
));