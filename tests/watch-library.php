<?php
// Offline regression checks for content retention and the two independently configured CTAs.
require __DIR__ . '/bootstrap.php';
reset_world();
$checks = 0;
function check($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function watch_fixture($takeaways = null, $status = 'completed') {
    return (object) array('id' => 123, 'markup_description' => '<strong>Original description</strong>', 'transcript_row' => (object) array(
        'status' => $status, 'plain_text' => 'The full transcript ends here. <script>alert(1)</script>',
        'seo_summary' => 'A short overview.', 'key_takeaways' => json_encode($takeaways ?? array('First point', 'Second point', 'Third point', 'Fourth point')),
    ));
}
$pt->theme_using = json_decode(file_get_contents(dirname(__DIR__) . '/themes/youplay/fonts/info.json'), true)['name'];
$pt->config->theme = 'youplay';
$pt->config->watch_cta_headline = 'Primary offer';
$pt->config->watch_cta_button_text = 'Explore primary';
$pt->config->watch_cta_button_url = 'https://example.test/primary?source=video&offer=one';
$pt->config->watch_cta2_headline = 'Secondary offer';
$pt->config->watch_cta2_button_text = 'Explore secondary';
$pt->config->watch_cta2_button_url = 'https://example.test/secondary';
$html = PT_BuildWatchDescriptionTabsHtml(watch_fixture());
check(strpos($html, 'watch-content-modern') !== false, 'Configured youplay theme uses tabs even when its font metadata says default');
$positions = array_map(function ($marker) use ($html) { return strpos($html, $marker); }, array('watch-content-tabs', 'id="watch-overview"', 'id="watch-transcript"', 'watch-primary-cta-slot'));
check(!in_array(false, $positions, true) && $positions[0] < $positions[1] && $positions[1] < $positions[2] && $positions[2] < $positions[3], 'Tabs and both information panels precede the primary CTA');
check(strpos($html, 'Primary offer') !== false && strpos($html, 'Secondary offer') !== false, 'Both independent headlines render');
check(strpos($html, 'Explore primary') !== false && strpos($html, 'Explore secondary') !== false, 'Both independent labels render');
check(strpos($html, 'https://example.test/secondary') !== false && strpos($html, 'source=video&amp;offer=one') !== false, 'Distinct CTA URLs and query strings survive');
check(substr_count($html, 'class="watch-cta-strip"') === 1 && substr_count($html, 'class="watch-cta-secondary"') === 1, 'Each configured offer renders once');
check(strpos($html, 'watch-about-clinic-cta') === false, 'Modern summary does not add a third generic pitch');
check(strpos($html, 'id="watch-transcript-panel" class="watch-transcript-panel" hidden') === false, 'Modern transcript remains readable without enhancement JS');
check(strpos($html, '<script>') === false, 'Transcript text is escaped');
$many = array_map(function ($n) { return "Takeaway $n"; }, range(1, 5));
$many[4] = 'Last point & <b>literal markup</b>';
$html = PT_BuildWatchKeyTakeawaysHtml($many, true);
check(substr_count($html, '<li>') === 5, 'All five parsed takeaways remain available');
check(substr_count(explode('<details', $html)[0], '<li>') === 3, 'Only three takeaways appear before the disclosure');
check(strpos($html, 'Last point &amp;') !== false && strpos($html, '<b>') === false, 'Last takeaway is retained and escaped');
check(strpos(PT_BuildWatchKeyTakeawaysHtml(array('One', 'Two'), true), '<details') === false, 'Short lists need no disclosure');
$pt->config->watch_cta2_button_url = '';
check(PT_GetWatchSecondaryCtaConfig()['button_url'] === $pt->config->watch_cta_button_url, 'Empty secondary URL keeps existing primary fallback');
$pt->config->watch_page_cta_html = '<aside id="custom-primary"><a href="/first">Custom first</a></aside>';
$pt->config->watch_page_cta2_html = '<form id="custom-secondary"><input name="email"><button>Custom second</button></form>';
$html = PT_BuildWatchDescriptionTabsHtml(watch_fixture());
check(substr_count($html, $pt->config->watch_page_cta_html) === 1, 'Primary HTML override survives exactly once');
check(substr_count($html, $pt->config->watch_page_cta2_html) === 1, 'Secondary form override survives exactly once');
check(strpos($html, 'Primary offer') === false && strpos($html, 'Secondary offer') === false, 'Overrides replace configured card markup');
$video = watch_fixture(array(), 'processing');
$video->transcript_row->seo_summary = '';
$html = PT_BuildWatchDescriptionTabsHtml($video);
check(strpos($html, 'data-watch-tab') === false, 'Incomplete transcripts do not expose empty tabs');
check(strpos($html, '<strong>Original description</strong>') !== false, 'Legacy description fallback is preserved');
$video->markup_description = '';
$html = PT_BuildWatchDescriptionTabsHtml($video);
check(strpos($html, 'watch-overview-empty') !== false && strpos($html, 'custom-secondary') !== false, 'Empty video information still keeps both offers');
$pt->theme_using = 'default';
$pt->config->theme = 'default';
$html = PT_BuildWatchDescriptionTabsHtml(watch_fixture());
check(strpos($html, 'data-watch-tab') === false && strpos($html, 'watch-about-clinic-cta') !== false, 'Other themes retain their original presentation');
check(strpos($html, 'watch-transcript-panel" hidden') !== false, 'Other themes retain their transcript toggle');
check(strpos(PT_BuildWatchKeyTakeawaysHtml($many), '<details') === false, 'Other themes still show every takeaway immediately');
$pt->theme_using = 'youplay';
$pt->config->theme = 'youplay';
$movie = watch_fixture(); $movie->is_movie = 1;
check(strpos(PT_BuildWatchDescriptionTabsHtml($movie), 'watch-content-modern') === false, 'Movie pages keep their existing layout');
echo "$checks watch-library assertions passed\n";
