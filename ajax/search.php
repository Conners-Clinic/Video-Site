<?php
$privacy = " AND privacy = 0 "; 
if ($pt->admin === true) {
	$privacy = "AND (privacy = 0 OR privacy = 2 )";
}
$data = array('status' => 400);
if (!empty($_POST['search_value'])) {
	$search_value = PT_Secure($_POST['search_value']);
	$search_result = $db->rawQuery("SELECT * FROM " . T_VIDEOS . " WHERE (title LIKE ? OR tags LIKE ? OR description LIKE ?) $privacy LIMIT 10", array("%" . $search_value . "%", "%" . $search_value . "%", "%" . $search_value . "%"));
	if (!empty($search_result)) {
		$html = '';
		foreach ($search_result as $key => $search) {
			$search = PT_GetVideoByID($search, 0, 0, 0);
			$unlisted = "";
			if($pt->admin AND $search->privacy == 2){
				$unlisted = "<span class='unlisted_search'>Unlisted</span>";
			}
			$html .= "<div class='search-result'><a href='$search->url'>$search->title $unlisted</a></div>";
		}
		$data = array('status' => 200, 'html' => $html);
	}
} 
?>