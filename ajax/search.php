<?php
$data = array('status' => 400);
$term = PT_SearchTerm($_POST['search_value'] ?? null);
if ($term !== null) {
    $search_result = PT_SearchVideoRows($db, $term, array(
        'admin' => $pt->admin === true, 'blocked' => $pt->blocked_array,
        'suggestions' => true, 'limit' => 10
    ));
    $html = '';
    foreach ($search_result as $search) {
        $link = PT_VideoCardLink($search);
        $url = htmlspecialchars($link['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = htmlspecialchars(html_entity_decode(strip_tags($search->title), ENT_QUOTES, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $unlisted = ($pt->admin === true && $search->privacy == 2) ? " <span class='unlisted_search'>Unlisted</span>" : '';
        $html .= "<div class='search-result'><a href='" . $url . "'>" . $title . $unlisted . "</a></div>";
    }
    $data = array('status' => 200, 'html' => $html);
}
