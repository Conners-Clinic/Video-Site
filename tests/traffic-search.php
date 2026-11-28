<?php
error_reporting(E_ALL);
require dirname(__DIR__) . '/assets/includes/tables.php';
require dirname(__DIR__) . '/assets/includes/traffic_search.php';
require dirname(__DIR__) . '/assets/includes/traffic_cards.php';
$checks = 0;
function searchCheck($condition, $message) { global $checks; $checks++; if (!$condition) { throw new RuntimeException($message); } }
foreach (array('Rife', 'PectaSol', 'IV', 'B12', '5-FU', 'éé', '中文', '100%', 'a_b', "O'Brien") as $term) {
    searchCheck(PT_SearchTerm($term) === $term, 'Clinic term/punctuation retained: ' . $term);
}
searchCheck(PT_SearchTerm("  Rife\t therapy\n") === 'Rife therapy', 'Whitespace normalized');
searchCheck(PT_SearchTerm(str_repeat('é', 200)) !== null, 'Unicode character boundary');
foreach (array(null, array('IV'), '', 'x', str_repeat('é', 201), implode(' ', array_fill(0, 13, 'IV')), "\xffIV") as $value) {
    searchCheck(PT_SearchTerm($value) === null, 'Invalid or excessive input rejected');
}
searchCheck(PT_SearchLike('100%_!') === '%100!%!_!!%', 'Wildcard characters are literal');
class SearchCaptureDb {
    public $calls = array();
    function objectBuilder() { return $this; }
    function rawQuery($sql, $params) { $this->calls[] = array($sql, $params); return array(); }
}
$db = new SearchCaptureDb();
PT_SearchVideoRows($db, null); PT_SearchVideoCount($db, 'x');
searchCheck(count($db->calls) === 0, 'Invalid input causes no search database reads');
PT_SearchVideoRows($db, "O'Brien", array('suggestions' => true, 'limit' => 10));
list($sql, $params) = $db->calls[0];
searchCheck(strpos($sql, "O'Brien") === false && in_array("%O'Brien%", $params, true), 'Input bound separately from SQL');
searchCheck(strpos($sql, 'SELECT *') === false && strpos($sql, 'LIMIT 10') !== false, 'Suggestion projection and cap');
searchCheck(strpos($sql, 'approved = 1') !== false && strpos($sql, 'privacy = 0') !== false, 'Guest approval/privacy rule');
list($where, $params) = PT_SearchWhere('IV', array('admin' => true, 'blocked' => array(2), 'categories' => array('category_1')));
searchCheck(strpos($where, 'privacy IN (0, 2)') !== false && strpos($where, 'approved = 1') !== false, 'Admins see approved public/unlisted items');
searchCheck(strpos($where, 'user_id NOT IN (?)') !== false && in_array('2', $params, true), 'Blocked owner exclusion retained');
PT_SearchVideoRows($db, 'Rife', array('page' => 2, 'limit' => 20));
searchCheck(strpos($db->calls[1][0], 'LIMIT 20 OFFSET 20') !== false, 'Numbered page two retained');
PT_SearchVideoRows($db, 'Rife', array('page' => PHP_INT_MAX, 'limit' => PHP_INT_MAX));
searchCheck(strpos($db->calls[2][0], 'LIMIT 50 OFFSET 499950') !== false, 'Pagination and per-response work bounded');
PT_SearchVideoRows($db, 'Rife', array('after_id' => 50, 'limit' => 40));
searchCheck(strpos($db->calls[3][0], 'tags LIKE ?') !== false && strpos($db->calls[3][0], 'approved = 1') !== false, 'Continuation shares tag/approval matching');
echo "$checks traffic-search assertions passed\n";
