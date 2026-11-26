<?php

function PT_DbVal($row, $key, $default = null) {
    if (is_array($row)) {
        return array_key_exists($key, $row) ? $row[$key] : $default;
    }
    if (is_object($row)) {
        return isset($row->{$key}) ? $row->{$key} : $default;
    }
    return $default;
}

function PT_TranscriptRootDir() {
    if (!empty($GLOBALS['PT_ROOT_OVERRIDE'])) {
        return $GLOBALS['PT_ROOT_OVERRIDE']; // test hook: lets the offline tests use a temp copy of content-pipeline/
    }
    $root = realpath(__DIR__ . '/../..');
    return ($root ? $root : dirname(__DIR__, 2)) . '/';
}

/**
 * Self-hosted upload eligible for Whisper (matches public channel listings, not import embeds).
 */
function PT_VideoHasExternalEmbed($video) {
    $embed_fields = array('youtube', 'vimeo', 'daily', 'facebook', 'twitch', 'instagram', 'ok');
    foreach ($embed_fields as $field) {
        if (!empty(PT_DbVal($video, $field))) {
            return true;
        }
    }
    return false;
}

function PT_VideoHasRemoteOnlyLocation($location) {
    $location = trim((string) $location);
    if ($location === '') {
        return true;
    }
    $decoded = urldecode($location);
    foreach (array($location, $decoded) as $path) {
        if (stripos($path, 'http://') === 0 || stripos($path, 'https://') === 0) {
            return true;
        }
    }
    return false;
}

function PT_VideoIsSelfHostedUpload($video) {
    if (empty($video) || empty(PT_DbVal($video, 'id'))) {
        return false;
    }
    if (PT_VideoHasExternalEmbed($video)) {
        return false;
    }
    if (PT_VideoHasRemoteOnlyLocation(PT_DbVal($video, 'video_location', ''))) {
        return false;
    }
    if ((int) PT_DbVal($video, 'converted', 0) === 2) {
        return false;
    }
    return true;
}

function PT_IsTranscribableVideo($video) {
    return PT_VideoIsSelfHostedUpload($video);
}

function PT_TranscribableVideoSqlCase($alias = 'v') {
    return "CASE WHEN {$alias}.youtube = '' AND {$alias}.vimeo = '' AND {$alias}.daily = '' AND {$alias}.facebook = ''
        AND {$alias}.twitch = '' AND {$alias}.instagram = '' AND {$alias}.ok = ''
        AND {$alias}.video_location IS NOT NULL AND TRIM({$alias}.video_location) <> ''
        AND {$alias}.video_location NOT LIKE 'http%' AND {$alias}.video_location NOT LIKE 'https%'
        AND {$alias}.converted <> 2
        AND {$alias}.is_movie = 0 AND {$alias}.is_short = 0
        THEN 1 ELSE 0 END";
}

function PT_GetVideoTranscript($video_id) {
    global $db;
    $video_id = (int) $video_id;
    if ($video_id < 1) {
        return null;
    }
    return $db->arraybuilder()->where('video_id', $video_id)->getOne(T_VIDEO_TRANSCRIPTS);
}

function PT_GetTranscriptVttUrl($video) {
    if (empty($video->video_id)) {
        return '';
    }
    $row = PT_GetVideoTranscript($video->id);
    if (empty($row) || PT_DbVal($row, 'status') !== 'completed' || empty(PT_DbVal($row, 'vtt_path'))) {
        return '';
    }
    return PT_Link('vtt/' . $video->video_id);
}

function PT_AttachTranscriptToVideo($video) {
    $video->transcript_vtt_url = '';
    $video->transcript_language = 'en';
    $video->transcript_row = null;
    $video->key_takeaways = array();
    $video->has_completed_transcript = false;
    $row = PT_GetVideoTranscript($video->id);
    if (!empty($row) && PT_DbVal($row, 'status') === 'completed') {
        $video->transcript_row = $row;
        $video->key_takeaways = PT_ParseKeyTakeaways(PT_DbVal($row, 'key_takeaways', ''));
        $video->has_completed_transcript = true;
        if (!empty(PT_DbVal($row, 'vtt_path'))) {
            $video->transcript_vtt_url = PT_GetTranscriptVttUrl($video);
            $video->transcript_language = !empty(PT_DbVal($row, 'language')) ? PT_DbVal($row, 'language') : 'en';
        }
    }
    return $video;
}

function PT_GetClinicCtaHtmlDefault() {
    return '<p><strong>Have you or a loved one been diagnosed with cancer?</strong></p>'
        . '<p>At Conners Clinic, we help people look beyond the diagnosis and explore potential underlying factors that may be contributing to their condition. Through personalized coaching, advanced testing, education, programmed rife machines, and custom wellness plans, we work with patients seeking a more comprehensive approach to their health journey.</p>'
        . '<p>Schedule a free 15-minute discovery call with Dr. Conners to learn about your options at <a href="https://www.connersclinic.com" target="_blank" rel="noopener">https://www.connersclinic.com</a></p>';
}

function PT_GetDefaultClinicCtaHtml() {
    global $pt;
    if (!empty($pt->config->clinic_cta_html)) {
        return $pt->config->clinic_cta_html;
    }
    return PT_GetClinicCtaHtmlDefault();
}

function PT_GetTranscriptDescriptionMode() {
    global $pt;
    $mode = !empty($pt->config->transcript_description_mode) ? $pt->config->transcript_description_mode : 'replace_description';
    if (!in_array($mode, array('display_only', 'replace_description', 'append_summary'), true)) {
        return 'replace_description';
    }
    return $mode;
}

function PT_TruncateForMeta($text, $max = 160) {
    $text = trim(strip_tags($text));
    $text = preg_replace('/\s+/', ' ', $text);
    if (mb_strlen($text, 'UTF-8') <= $max) {
        return $text;
    }
    return mb_substr($text, 0, $max - 3, 'UTF-8') . '...';
}

function PT_FormatTranscriptForDisplay($plain_text) {
    $plain_text = trim(strip_tags($plain_text));
    if ($plain_text === '') {
        return '';
    }
    $chunks = preg_split('/(?<=[.!?])\s+/', $plain_text, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($chunks)) {
        return '<p>' . htmlspecialchars($plain_text) . '</p>';
    }
    $paragraphs = array();
    $buffer = '';
    foreach ($chunks as $sentence) {
        $buffer .= ($buffer === '' ? '' : ' ') . $sentence;
        if (strlen($buffer) > 400) {
            $paragraphs[] = '<p>' . htmlspecialchars($buffer) . '</p>';
            $buffer = '';
        }
    }
    if ($buffer !== '') {
        $paragraphs[] = '<p>' . htmlspecialchars($buffer) . '</p>';
    }
    return implode("\n", $paragraphs);
}

function PT_BuildSeoSummaryHtml($seo_summary) {
    $seo_summary = trim(strip_tags($seo_summary));
    if ($seo_summary === '') {
        return '';
    }
    $parts = preg_split('/\n\s*\n/', $seo_summary);
    $html = '';
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $html .= '<p>' . nl2br(htmlspecialchars($part)) . '</p>';
        }
    }
    if ($html === '') {
        $html = '<p>' . nl2br(htmlspecialchars($seo_summary)) . '</p>';
    }
    return $html;
}

function PT_ParseKeyTakeaways($stored) {
    if ($stored === null || $stored === '') {
        return array();
    }
    if (is_string($stored)) {
        $stored = trim($stored);
        if ($stored === '' || $stored === '[]' || $stored === 'null') {
            return array();
        }
    }
    if (is_array($stored)) {
        $decoded = $stored;
    } else {
        $decoded = json_decode($stored, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array();
        }
    }
    if (!is_array($decoded)) {
        return array();
    }
    $items = array();
    foreach ($decoded as $item) {
        if (!is_string($item)) {
            continue;
        }
        $item = trim(strip_tags($item));
        if ($item === '') {
            continue;
        }
        if (mb_strlen($item, 'UTF-8') > 240) {
            $cut = mb_substr($item, 0, 237, 'UTF-8');
            $sp = mb_strrpos($cut, ' ', 0, 'UTF-8');
            if ($sp !== false && $sp > 120) {
                $cut = mb_substr($cut, 0, $sp, 'UTF-8');
            }
            $item = rtrim($cut, " ,;:-") . '...';
        }
        $items[] = $item;
        if (count($items) >= 5) {
            break;
        }
    }
    return $items;
}

function PT_GetVideoKeyTakeaways($video) {
    try {
        $row = !empty($video->transcript_row) ? $video->transcript_row : null;
        if (empty($row) && !empty($video->id)) {
            $row = PT_GetVideoTranscript($video->id);
        }
        if (empty($row)) {
            return array();
        }
        return PT_ParseKeyTakeaways(PT_DbVal($row, 'key_takeaways', ''));
    } catch (Exception $e) {
        return array();
    }
}

function PT_EncodeKeyTakeawaysForDb($takeaways) {
    $items = PT_ParseKeyTakeaways($takeaways);
    if (empty($items)) {
        return null;
    }
    return json_encode(array_values($items), JSON_UNESCAPED_UNICODE);
}

function PT_GetWatchCtaDefaults() {
    return array(
        'eyebrow' => 'Personalized guidance',
        'headline' => 'Want help looking deeper into your health?',
        'body' => 'Explore what may be driving your concerns with Conners Clinic\'s root-cause-focused coaching and testing support.',
        'button_text' => 'Free Discovery Call',
        'button_url' => 'https://www.connersclinic.com/schedule-now/',
        'trust_labels' => '',
    );
}

function PT_GetWatchSecondaryCtaDefaults() {
    return array(
        'eyebrow' => 'Ready to dig deeper?',
        'headline' => 'Schedule a free 15-minute discovery call',
        'body' => 'Learn how Conners Clinic helps patients explore underlying factors through personalized coaching, testing, and education.',
        'button_text' => 'Schedule free call',
        'button_url' => 'https://www.connersclinic.com/schedule-now/',
    );
}

function PT_GetWatchCtaConfig() {
    global $pt;
    $defaults = PT_GetWatchCtaDefaults();
    $trust_raw = !empty($pt->config->watch_cta_trust_labels)
        ? $pt->config->watch_cta_trust_labels
        : $defaults['trust_labels'];
    $has_eyebrow = isset($pt->config->watch_cta_eyebrow);
    $headline_raw = isset($pt->config->watch_cta_headline) ? trim($pt->config->watch_cta_headline) : null;
    if (!$has_eyebrow && $headline_raw === 'Need personalized guidance?') {
        $eyebrow = 'Personalized guidance';
        $headline = $defaults['headline'];
    } else {
        $eyebrow = $has_eyebrow ? trim($pt->config->watch_cta_eyebrow) : $defaults['eyebrow'];
        $headline = $headline_raw !== null ? $headline_raw : $defaults['headline'];
    }
    $body = isset($pt->config->watch_cta_body) ? trim($pt->config->watch_cta_body) : $defaults['body'];
    return array(
        'eyebrow' => $eyebrow,
        'headline' => $headline,
        'body' => $body,
        'button_text' => trim(!empty($pt->config->watch_cta_button_text) ? $pt->config->watch_cta_button_text : $defaults['button_text']),
        'button_url' => trim(!empty($pt->config->watch_cta_button_url) ? $pt->config->watch_cta_button_url : $defaults['button_url']),
        'trust_labels' => PT_ParseWatchCtaTrustLabels($trust_raw),
        'html_override' => trim(!empty($pt->config->watch_page_cta_html) ? $pt->config->watch_page_cta_html : ''),
    );
}

function PT_GetWatchSecondaryCtaConfig() {
    global $pt;
    $defaults = PT_GetWatchSecondaryCtaDefaults();
    $primary = PT_GetWatchCtaConfig();
    $button_url = trim(!empty($pt->config->watch_cta2_button_url) ? $pt->config->watch_cta2_button_url : '');
    if ($button_url === '') {
        $button_url = $primary['button_url'];
    }
    $has_eyebrow = isset($pt->config->watch_cta2_eyebrow);
    $headline_raw = isset($pt->config->watch_cta2_headline) ? trim($pt->config->watch_cta2_headline) : null;
    if (!$has_eyebrow && $headline_raw === 'Want help looking deeper?') {
        $eyebrow = 'Ready to dig deeper?';
        $headline = $defaults['headline'];
    } else {
        $eyebrow = $has_eyebrow ? trim($pt->config->watch_cta2_eyebrow) : $defaults['eyebrow'];
        $headline = $headline_raw !== null ? $headline_raw : $defaults['headline'];
    }
    $body = isset($pt->config->watch_cta2_body) ? trim($pt->config->watch_cta2_body) : $defaults['body'];
    return array(
        'eyebrow' => $eyebrow,
        'headline' => $headline,
        'body' => $body,
        'button_text' => trim(!empty($pt->config->watch_cta2_button_text) ? $pt->config->watch_cta2_button_text : $defaults['button_text']),
        'button_url' => $button_url,
        'html_override' => trim(!empty($pt->config->watch_page_cta2_html) ? $pt->config->watch_page_cta2_html : ''),
    );
}

function PT_ParseWatchCtaTrustLabels($raw) {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return array();
    }
    $parts = preg_split('/[\r\n|]+/', $raw);
    $labels = array();
    foreach ($parts as $part) {
        $part = trim(strip_tags($part));
        if ($part !== '') {
            $labels[] = $part;
        }
    }
    return $labels;
}

function PT_IsWatchCtaHtmlOverride($html) {
    $html = trim((string) $html);
    if ($html === '') {
        return false;
    }
    return strpos($html, '<') !== false;
}

function PT_BuildWatchCtaTrustHtml($labels) {
    if (empty($labels)) {
        return '';
    }
    $spans = '';
    foreach ($labels as $label) {
        $spans .= '<span>' . htmlspecialchars($label) . '</span>';
    }
    return '<div class="watch-cta-trust" aria-hidden="true">' . $spans . '</div>';
}

function PT_BuildWatchPrimaryCtaHtml() {
    $cfg = PT_GetWatchCtaConfig();
    if (PT_IsWatchCtaHtmlOverride($cfg['html_override'])) {
        $html = $cfg['html_override'];
        if (stripos($html, 'watch-cta-strip') !== false) {
            return $html;
        }
        return '<aside class="watch-cta-strip" aria-label="Schedule a discovery call">' . $html . '</aside>';
    }
    $eyebrow = trim($cfg['eyebrow']);
    $headline = trim($cfg['headline']);
    $body = trim($cfg['body']);
    $button_text = trim($cfg['button_text']);
    $button_url = htmlspecialchars($cfg['button_url'], ENT_QUOTES, 'UTF-8');
    $text_html = '';
    if ($eyebrow !== '') {
        $text_html .= '<p class="watch-cta-strip-eyebrow">'
            . '<svg class="watch-cta-strip-eyebrow-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l1.8 5.4L19 9l-5.2 1.8L12 16l-1.8-5.2L5 9l5.2-1.6L12 2z"/></svg>'
            . '<span>' . htmlspecialchars($eyebrow) . '</span></p>';
    }
    if ($headline !== '') {
        $text_html .= '<p class="watch-cta-strip-headline">' . htmlspecialchars($headline) . '</p>';
    }
    if ($body !== '') {
        $text_html .= '<p class="watch-cta-strip-body">' . htmlspecialchars($body) . '</p>';
    }
    $button_html = '';
    if ($button_text !== '' && $button_url !== '') {
        $button_html = '<a class="btn btn-main watch-cta-strip-btn" href="' . $button_url . '" target="_blank" rel="noopener noreferrer">'
            . '<svg class="watch-cta-strip-btn-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>'
            . '<span class="watch-cta-strip-btn-text">' . htmlspecialchars($button_text) . '</span></a>';
    }
    return '<aside class="watch-cta-strip" aria-label="Schedule a discovery call">'
        . '<div class="watch-cta-strip-inner">'
        . ($text_html !== '' ? '<div class="watch-cta-strip-text">' . $text_html . '</div>' : '')
        . $button_html
        . '</div>'
        . '</aside>';
}

function PT_BuildWatchSecondaryCtaHtml() {
    $cfg = PT_GetWatchSecondaryCtaConfig();
    if (PT_IsWatchCtaHtmlOverride($cfg['html_override'])) {
        $html = $cfg['html_override'];
        if (stripos($html, 'watch-cta-secondary') !== false) {
            return $html;
        }
        return '<section class="watch-cta-secondary" aria-label="Schedule a discovery call">' . $html . '</section>';
    }
    $eyebrow = trim($cfg['eyebrow']);
    $headline = trim($cfg['headline']);
    $body = trim($cfg['body']);
    $button_text = trim($cfg['button_text']);
    $button_url = htmlspecialchars($cfg['button_url'], ENT_QUOTES, 'UTF-8');
    $text_html = '';
    if ($eyebrow !== '') {
        $text_html .= '<p class="watch-cta-secondary-eyebrow">'
            . '<svg class="watch-cta-secondary-eyebrow-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l1.8 5.4L19 9l-5.2 1.8L12 16l-1.8-5.2L5 9l5.2-1.6L12 2z"/></svg>'
            . '<span>' . htmlspecialchars($eyebrow) . '</span></p>';
    }
    if ($headline !== '') {
        $text_html .= '<p class="watch-cta-secondary-headline">' . htmlspecialchars($headline) . '</p>';
    }
    if ($body !== '') {
        $text_html .= '<p class="watch-cta-secondary-body">' . htmlspecialchars($body) . '</p>';
    }
    $button_html = '';
    if ($button_text !== '' && $button_url !== '') {
        $button_html = '<a class="btn btn-main watch-cta-secondary-btn" href="' . $button_url . '" target="_blank" rel="noopener noreferrer">'
            . '<svg class="watch-cta-secondary-btn-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>'
            . '<span class="watch-cta-secondary-btn-text">' . htmlspecialchars($button_text) . '</span></a>';
    }
    return '<section class="watch-cta-secondary" aria-label="Schedule a discovery call">'
        . '<div class="watch-cta-secondary-inner">'
        . ($text_html !== '' ? '<div class="watch-cta-secondary-text">' . $text_html . '</div>' : '')
        . $button_html
        . '</div>'
        . '</section>';
}

function PT_BuildWatchKeyTakeawaysHtml($takeaways, $compact = false) {
    $takeaways = PT_ParseKeyTakeaways($takeaways);
    if (count($takeaways) < 1) {
        return '';
    }
    $lis = '';
    $remaining = '';
    foreach ($takeaways as $index => $item) {
        $li = '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
        if ($compact && $index >= 3) {
            $remaining .= $li;
        } else {
            $lis .= $li;
        }
    }
    $more = $remaining === '' ? '' : '<details class="watch-takeaways-more">'
        . '<summary><span class="when-closed">View all ' . count($takeaways) . ' takeaways</span>'
        . '<span class="when-open">Show fewer takeaways</span></summary>'
        . '<ul class="watch-takeaways-list">' . $remaining . '</ul></details>';
    return '<section class="watch-section watch-section-takeaways">'
        . ($compact ? '<h2 class="watch-section-heading">Key takeaways</h2>' : '<h3 class="watch-section-heading">Key Takeaways</h3>')
        . '<ul class="watch-takeaways-list">' . $lis . '</ul>' . $more
        . '</section>';
}

function PT_BuildWatchAboutVideoHtml($seo_summary, $fallback_markup = '', $compact = false) {
    $summary_html = PT_BuildSeoSummaryHtml($seo_summary);
    if ($summary_html !== '') {
        $cta_html = $compact ? '' : trim(PT_GetDefaultClinicCtaHtml());
        $cta_block = $cta_html !== '' ? '<div class="watch-about-clinic-cta">' . $cta_html . '</div>' : '';
        $long = mb_strlen(strip_tags($seo_summary), 'UTF-8') > 520;
        $expand = $long
            ? '<button type="button" class="watch-expand-btn" data-watch-expand="summary" aria-expanded="false">Read more</button>'
            : '';
        $long_class = $long ? ' watch-about-body--collapsible' : '';
        return '<section class="watch-section watch-section-about" itemprop="description">'
            . '<h3 class="watch-section-heading">About This Video</h3>'
            . '<div class="watch-about-body' . $long_class . '">' . $summary_html . '</div>'
            . $expand
            . $cta_block
            . '</section>';
    }
    if (trim(strip_tags($fallback_markup)) !== '') {
        return '<section class="watch-section watch-section-about watch-section-legacy" itemprop="description">'
            . '<h3 class="watch-section-heading">About This Video</h3>'
            . '<div class="watch-about-body"><p dir="auto">' . $fallback_markup . '</p></div>'
            . '</section>';
    }
    return '';
}

function PT_BuildWatchTranscriptSectionHtml($video, $row, $expanded = false) {
    $has_transcript = !empty($row) && PT_DbVal($row, 'status') === 'completed' && !empty(PT_DbVal($row, 'plain_text'));
    if (!$has_transcript) {
        return '';
    }
    $display_text = PT_DbVal($row, 'plain_text', '');
    if (function_exists('PT_GetDisplayTranscriptForVideo')) {
        $cleaned = PT_GetDisplayTranscriptForVideo($video->id);
        if ($cleaned !== '') {
            $display_text = $cleaned;
        }
    }
    $body = PT_FormatTranscriptForDisplay($display_text);
    if ($body === '') {
        return '';
    }
    return '<section class="watch-section watch-section-transcript">'
        . '<h3 class="watch-section-heading">Transcript</h3>'
        . ($expanded ? '' : '<button type="button" class="watch-transcript-toggle" aria-expanded="false" aria-controls="watch-transcript-panel">Show Full Transcript</button>')
        . '<div id="watch-transcript-panel" class="watch-transcript-panel"' . ($expanded ? '' : ' hidden') . '>'
        . '<div class="watch-transcript-body">' . $body . '</div>'
        . '</div>'
        . '</section>';
}

function PT_BuildWatchDescriptionTabsHtml($video) {
    global $pt;
    // The theme's fonts/info.json reports 'default'; use the actual configured folder.
    $compact = ($pt->config->theme ?? $pt->theme_using ?? '') === 'youplay' && empty($video->is_movie);
    $row = !empty($video->transcript_row) ? $video->transcript_row : PT_GetVideoTranscript($video->id);
    $seo_summary = PT_DbVal($row, 'seo_summary', '');
    $takeaways = array();
    try {
        $takeaways = PT_GetVideoKeyTakeaways($video);
    } catch (Exception $e) {
        $takeaways = array();
    }
    $has_takeaways = is_array($takeaways) && count($takeaways) > 0;
    $fallback_markup = '';
    if (trim($seo_summary) === '' && !empty($video->markup_description)) {
        $fallback_markup = $video->markup_description;
    }

    $transcript = PT_BuildWatchTranscriptSectionHtml($video, $row, $compact);
    $wrapper_class = 'watch-content-below-video';
    if ($transcript === '') {
        $wrapper_class .= ' watch-content-below-video--no-transcript';
    }
    $takeaways_html = $has_takeaways ? PT_BuildWatchKeyTakeawaysHtml($takeaways, $compact) : '';
    $about = PT_BuildWatchAboutVideoHtml($seo_summary, $fallback_markup, $compact);
    if ($compact) {
        // Both offers keep their independent admin copy, URL fallback and HTML overrides.
        // The secondary is moved (never cloned) to the visible related-video column by JS.
        $overview = $takeaways_html;
        if ($about !== '') {
            $overview .= '<details class="watch-about-disclosure"><summary>About this video</summary>' . $about . '</details>';
        }
        if ($overview === '') {
            $overview = '<p class="watch-overview-empty">Watch the video above. An overview is not available for this video yet.</p>';
        }
        $parts = array('<div class="' . $wrapper_class . ' watch-content-modern" data-watch-content>');
        if ($transcript !== '') {
            // Without JavaScript these remain ordinary links to fully readable sections.
            $parts[] = '<nav class="watch-content-tabs" aria-label="Video information">'
                . '<a id="watch-overview-tab" href="#watch-overview" data-watch-tab="overview">Overview</a>'
                . '<a id="watch-transcript-tab" href="#watch-transcript" data-watch-tab="transcript">Transcript</a></nav>';
        }
        $parts[] = '<div id="watch-overview" class="watch-tab-panel" data-watch-panel="overview">' . $overview . '</div>';
        if ($transcript !== '') {
            $parts[] = '<div id="watch-transcript" class="watch-tab-panel" data-watch-panel="transcript">' . $transcript . '</div>';
        }
        $parts[] = '<div class="watch-primary-cta-slot">' . PT_BuildWatchPrimaryCtaHtml() . '</div>';
        $parts[] = '<div class="watch-secondary-cta-origin"><div data-watch-secondary>' . PT_BuildWatchSecondaryCtaHtml() . '</div></div>';
    } else {
        $parts = array('<div class="' . $wrapper_class . '">', PT_BuildWatchPrimaryCtaHtml(), $takeaways_html, $about, $transcript, PT_BuildWatchSecondaryCtaHtml());
    }
    $parts[] = '</div>';
    return implode("\n", $parts);
}

function PT_GetDefaultOpenAiSummaryPrompt() {
    return 'You analyze health-education video transcripts for Conners Clinic. '
        . 'Respond with a single JSON object only (no markdown fences), exactly this shape: '
        . '{"summary":"...","key_takeaways":["...","..."]}. '
        . 'summary: 2 to 4 plain sentences about what the video covers and who it helps. '
        . 'key_takeaways: 3 to 5 concise bullets in plain language; each under 160 characters when possible; '
        . 'only facts from the transcript; no exaggerated medical claims; fewer bullets if the video is short or unclear.';
}

function PT_NormalizeTranscriptAiResponse($parsed) {
    $summary = '';
    $takeaways = array();
    if (!is_array($parsed)) {
        return array('summary' => '', 'key_takeaways' => array());
    }
    if (!empty($parsed['summary']) && is_string($parsed['summary'])) {
        $summary = trim($parsed['summary']);
    }
    if (!empty($parsed['key_takeaways']) && is_array($parsed['key_takeaways'])) {
        $takeaways = PT_ParseKeyTakeaways($parsed['key_takeaways']);
    }
    return array('summary' => $summary, 'key_takeaways' => $takeaways);
}

function PT_GenerateTranscriptSeoSummary($video, $plain_text) {
    $result = PT_GenerateTranscriptAiContent($video, $plain_text);
    if (empty($result['ok'])) {
        return array(
            'ok' => false,
            'summary' => '',
            'key_takeaways' => array(),
            'error' => !empty($result['error']) ? $result['error'] : 'AI content generation failed',
        );
    }
    return array(
        'ok' => true,
        'summary' => $result['summary'],
        'key_takeaways' => $result['key_takeaways'],
        'error' => '',
        'mode' => $result['mode'],
        'sections' => $result['sections'],
        'usage' => $result['usage'],
    );
}

/**
 * Summary + key takeaways for the COMPLETE transcript (see functions_ai_summary.php).
 * Models, limits and reasoning settings come from content-pipeline/config/pipeline-models.json.
 */
function PT_GenerateTranscriptAiContent($video, $plain_text) {
    $result = PT_AiSummarizeTranscript($video, $plain_text);
    if (empty($result['ok'])) {
        return array(
            'ok' => false, 'summary' => '', 'key_takeaways' => array(),
            'error' => !empty($result['error']) ? $result['error'] : 'AI summary failed',
            'usage' => !empty($result['usage']) ? $result['usage'] : array(),
        );
    }
    return array(
        'ok' => true,
        'summary' => $result['summary'],
        'key_takeaways' => $result['key_takeaways'],
        'error' => '',
        'mode' => $result['mode'],
        'sections' => $result['sections'],
        'usage' => $result['usage'],
        'warnings' => $result['warnings'],
    );
}

function PT_ComposeVideoDescriptionFromTranscript($seo_summary) {
    $parts = array();
    $summary_html = PT_BuildSeoSummaryHtml($seo_summary);
    if ($summary_html !== '') {
        $parts[] = $summary_html;
    }
    $parts[] = PT_GetDefaultClinicCtaHtml();
    return trim(implode("\n\n", $parts));
}

function PT_ApplyTranscriptToVideoDescription($video_id, $seo_summary) {
    global $db, $pt;
    $video_id = (int) $video_id;
    $mode = PT_GetTranscriptDescriptionMode();
    if ($mode === 'display_only' || trim($seo_summary) === '') {
        return false;
    }
    $video = $db->arraybuilder()->where('id', $video_id)->getOne(T_VIDEOS);
    if (empty($video)) {
        return false;
    }
    $composed = PT_ComposeVideoDescriptionFromTranscript($seo_summary);
    if ($mode === 'append_summary') {
        $existing = trim(PT_DbVal($video, 'description', ''));
        $composed = $composed . ($existing !== '' ? "\n\n" . $existing : '');
    }
    $applied = array('description_applied' => 1);
    // Keep the pre-AI description once, so an overwrite can be undone (needs migration 2032).
    if (PT_AiColumnExists(T_VIDEO_TRANSCRIPTS, 'description_backup')) {
        $existing_row = PT_GetVideoTranscript($video_id);
        if (empty(PT_DbVal($existing_row, 'description_backup'))) {
            $applied['description_backup'] = (string) PT_DbVal($video, 'description', '');
        }
    }
    $db->where('id', $video_id)->update(T_VIDEOS, array('description' => $composed));
    PT_UpsertVideoTranscript($video_id, $applied);
    return true;
}

function PT_FinalizeTranscriptWithSeo($video, $plain_text, $dest_rel, $language) {
    $video_id = (int) PT_DbVal($video, 'id');
    PT_UpsertVideoTranscript($video_id, array(
        'status' => 'completed',
        'plain_text' => $plain_text,
        'vtt_path' => $dest_rel,
        'language' => $language,
        'error_message' => '',
    ));
    PT_AiBudgetBegin('finalize_' . $video_id);
    $summary_result = PT_GenerateTranscriptSeoSummary($video, $plain_text);
    $seo_summary = '';
    $key_takeaways_db = null;
    $extra_error = '';
    if (!empty($summary_result['ok'])) {
        $seo_summary = $summary_result['summary'];
        $key_takeaways_db = PT_EncodeKeyTakeawaysForDb(!empty($summary_result['key_takeaways']) ? $summary_result['key_takeaways'] : array());
    } else {
        $extra_error = !empty($summary_result['error']) ? $summary_result['error'] : 'SEO summary failed';
    }
    $seo_upsert = array('error_message' => $extra_error);
    if ($seo_summary !== '') {
        $seo_upsert['seo_summary'] = $seo_summary;
    }
    if ($key_takeaways_db !== null) {
        $seo_upsert['key_takeaways'] = $key_takeaways_db;
    }
    PT_UpsertVideoTranscript($video_id, $seo_upsert);
    if ($seo_summary !== '' && PT_TranscriptAutoApplyDescription()) {
        PT_ApplyTranscriptToVideoDescription($video_id, $seo_summary);
    }
}

/** Descriptions are only rewritten automatically when this is explicitly switched on (default: off). */
function PT_TranscriptAutoApplyDescription() {
    global $pt;
    return !empty($pt->config->transcript_auto_apply_description) && $pt->config->transcript_auto_apply_description === 'on';
}

function PT_ExpectedTranscriptVttRelPath($video) {
    return 'upload/transcripts/' . PT_DbVal($video, 'video_id') . '.vtt';
}

function PT_TranscriptArtifactExists($rel_path) {
    $rel_path = trim((string) $rel_path);
    if ($rel_path === '') {
        return false;
    }
    $local = PT_TranscriptRootDir() . $rel_path;
    if (file_exists($local) && @filesize($local) > 10) {
        return true;
    }
    global $pt;
    if (!empty($pt->remoteStorage)) {
        $url = PT_GetMedia($rel_path);
        if (!empty($url)) {
            $headers = @get_headers($url, 1);
            if (!empty($headers[0]) && strpos($headers[0], '200') !== false) {
                return true;
            }
        }
    }
    return false;
}

function PT_LoadTranscriptVttContent($rel_path) {
    $rel_path = trim((string) $rel_path);
    if ($rel_path === '') {
        return '';
    }
    $local = PT_TranscriptRootDir() . $rel_path;
    if (file_exists($local)) {
        $content = @file_get_contents($local);
        return $content !== false ? $content : '';
    }
    global $pt;
    if (!empty($pt->remoteStorage)) {
        $url = PT_GetMedia($rel_path);
        if (!empty($url)) {
            $content = @file_get_contents($url);
            return $content !== false ? $content : '';
        }
    }
    return '';
}

function PT_ReconcileStuckTranscriptRecord($video_id, $options = array()) {
    global $db;
    $video_id = (int) $video_id;
    $row = PT_GetVideoTranscript($video_id);
    if (empty($row) || PT_DbVal($row, 'status') !== 'processing') {
        return array('action' => 'none');
    }
    $video = $db->arraybuilder()->where('id', $video_id)->getOne(T_VIDEOS);
    if (empty($video)) {
        return array('action' => 'none');
    }

    $plain_text = trim((string) PT_DbVal($row, 'plain_text', ''));
    $vtt_path = trim((string) PT_DbVal($row, 'vtt_path', ''));
    if ($plain_text !== '' && $vtt_path !== '') {
        PT_UpsertVideoTranscript($video_id, array(
            'status' => 'completed',
            'error_message' => '',
        ));
        return array('action' => 'completed_from_db');
    }

    if ($vtt_path === '') {
        $vtt_path = PT_ExpectedTranscriptVttRelPath($video);
    }
    if (!PT_TranscriptArtifactExists($vtt_path)) {
        return array('action' => 'none');
    }

    $vtt_content = PT_LoadTranscriptVttContent($vtt_path);
    if ($vtt_content === '') {
        return array('action' => 'none');
    }
    $plain_text = PT_VttToPlainText($vtt_content);
    $language = !empty(PT_DbVal($row, 'language')) ? PT_DbVal($row, 'language') : 'en';
    PT_UpsertVideoTranscript($video_id, array(
        'status' => 'completed',
        'plain_text' => $plain_text,
        'vtt_path' => $vtt_path,
        'language' => $language,
        'error_message' => '',
    ));

    $run_seo = !array_key_exists('run_seo', $options) || !empty($options['run_seo']);
    $needs_seo = trim((string) PT_DbVal($row, 'seo_summary', '')) === '';
    if ($run_seo && $needs_seo && $plain_text !== '') {
        PT_AiBudgetBegin('reconcile_' . $video_id);
        $summary_result = PT_GenerateTranscriptSeoSummary($video, $plain_text);
        if (!empty($summary_result['ok'])) {
            $seo_upsert = array(
                'seo_summary' => $summary_result['summary'],
                'error_message' => '',
            );
            $encoded = PT_EncodeKeyTakeawaysForDb(!empty($summary_result['key_takeaways']) ? $summary_result['key_takeaways'] : array());
            if ($encoded !== null) {
                $seo_upsert['key_takeaways'] = $encoded;
            }
            PT_UpsertVideoTranscript($video_id, $seo_upsert);
            if (PT_TranscriptAutoApplyDescription()) {
                PT_ApplyTranscriptToVideoDescription($video_id, $summary_result['summary']);
            }
        } else {
            PT_UpsertVideoTranscript($video_id, array('error_message' => substr((string) $summary_result['error'], 0, 2000)));
        }
    }

    return array('action' => 'completed_from_vtt');
}

function PT_ReconcileStuckTranscriptRecords($options = array()) {
    global $db;
    $user_id = !empty($options['user_id']) ? (int) $options['user_id'] : 0;
    $sql = "SELECT t.video_id FROM " . T_VIDEO_TRANSCRIPTS . " t";
    if ($user_id > 0) {
        $sql .= " INNER JOIN " . T_VIDEOS . " v ON v.id = t.video_id WHERE t.status = 'processing' AND v.user_id = " . $user_id;
    } else {
        $sql .= " WHERE t.status = 'processing'";
    }
    $rows = $db->arraybuilder()->rawQuery($sql);
    $result = array(
        'checked' => 0,
        'completed_from_db' => 0,
        'completed_from_vtt' => 0,
        'unchanged' => 0,
    );
    if (empty($rows)) {
        return $result;
    }
    foreach ($rows as $row) {
        $result['checked']++;
        $reconciled = PT_ReconcileStuckTranscriptRecord((int) PT_DbVal($row, 'video_id'), $options);
        if (PT_DbVal($reconciled, 'action') === 'completed_from_db') {
            $result['completed_from_db']++;
        } elseif (PT_DbVal($reconciled, 'action') === 'completed_from_vtt') {
            $result['completed_from_vtt']++;
        } else {
            $result['unchanged']++;
        }
    }
    return $result;
}

/**
 * Regenerate summary + takeaways from the stored transcript (no audio, no Whisper).
 * Options: force (replace an existing summary), apply_description (also rewrite videos.description; default false).
 * Refuses to re-bill a video that already has a summary unless force is set.
 */
function PT_RegenerateSeoSummaryForVideo($video_id, $options = array()) {
    global $db;
    $video_id = (int) $video_id;
    $force = !empty($options['force']);
    $row = PT_GetVideoTranscript($video_id);
    $video = $db->arraybuilder()->where('id', $video_id)->getOne(T_VIDEOS);
    if (empty($row) || empty($video) || PT_DbVal($row, 'status') !== 'completed' || empty(PT_DbVal($row, 'plain_text'))) {
        return array('ok' => false, 'error' => 'No completed transcript for this video');
    }
    $has_summary = trim((string) PT_DbVal($row, 'seo_summary', '')) !== '';
    $has_takeaways = !empty(PT_ParseKeyTakeaways(PT_DbVal($row, 'key_takeaways', '')));
    if ($has_summary && $has_takeaways && !$force) {
        return array('ok' => false, 'skipped' => true, 'error' => 'Summary already exists (use force to regenerate)');
    }
    $lock = 'summary_' . $video_id;
    if (!PT_AiAcquireLock($lock)) {
        return array('ok' => false, 'skipped' => true, 'error' => 'A summary for this video is already being generated');
    }
    PT_AiBudgetBegin('summary_' . $video_id);
    $summary_result = PT_GenerateTranscriptSeoSummary($video, PT_DbVal($row, 'plain_text'));
    PT_AiReleaseLock($lock);
    if (empty($summary_result['ok'])) {
        PT_UpsertVideoTranscript($video_id, array('error_message' => substr($summary_result['error'], 0, 2000)));
        return $summary_result;
    }
    $upsert = array(
        'seo_summary' => $summary_result['summary'],
        'error_message' => '',
    );
    $encoded = PT_EncodeKeyTakeawaysForDb(!empty($summary_result['key_takeaways']) ? $summary_result['key_takeaways'] : array());
    if ($encoded !== null) {
        $upsert['key_takeaways'] = $encoded;
    }
    PT_UpsertVideoTranscript($video_id, $upsert);
    $applied = false;
    if (!empty($options['apply_description'])) {
        $applied = PT_ApplyTranscriptToVideoDescription($video_id, $summary_result['summary']);
    }
    return array(
        'ok' => true,
        'summary' => $summary_result['summary'],
        'key_takeaways' => !empty($summary_result['key_takeaways']) ? $summary_result['key_takeaways'] : array(),
        'description_applied' => $applied,
        'mode' => $summary_result['mode'],
        'sections' => $summary_result['sections'],
        'usage' => $summary_result['usage'],
    );
}

function PT_TestOpenAiConnection() {
    global $pt;
    if (empty(trim((string) $pt->config->openai_api_key))) {
        return array('ok' => false, 'error' => 'OpenAI API key is empty');
    }
    PT_AiBudgetBegin('connection_test', array('max_calls' => 1, 'max_cost' => 0.05));
    $res = PT_AiChat(array(array('role' => 'user', 'content' => 'Reply with exactly: OK')), 'summary', array(
        'pass_name' => 'connection_test', 'max_completion_tokens' => 64, 'reasoning_effort' => 'none',
    ));
    if (!empty($res['ok'])) {
        return array('ok' => true, 'message' => 'OpenAI connection successful (model ' . $res['model'] . ')');
    }
    return array('ok' => false, 'error' => $res['error']);
}

function PT_VttToPlainText($vtt_content) {
    $lines = explode("\n", $vtt_content);
    $parts = array();
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line === 'WEBVTT') {
            continue;
        }
        if (strpos($line, '-->') !== false) {
            continue;
        }
        if (preg_match('/^\d+$/', $line)) {
            continue;
        }
        if (stripos($line, 'NOTE') === 0) {
            continue;
        }
        $parts[] = strip_tags($line);
    }
    return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
}

function PT_UpsertVideoTranscript($video_id, $fields) {
    global $db;
    $video_id = (int) $video_id;
    $now = time();
    $existing = PT_GetVideoTranscript($video_id);
    $fields['updated_at'] = $now;
    if (empty($existing)) {
        $fields['video_id'] = $video_id;
        $fields['created_at'] = $now;
        return $db->insert(T_VIDEO_TRANSCRIPTS, $fields);
    }
    return $db->where('video_id', $video_id)->update(T_VIDEO_TRANSCRIPTS, $fields);
}

function PT_IsVideoInTranscriptQueue($video_id) {
    global $db;
    return $db->arraybuilder()->where('video_id', (int) $video_id)->getValue(T_TRANSCRIPT_QUEUE, 'COUNT(*)') > 0;
}

function PT_EnqueueTranscript($video_id) {
    global $db;
    $video_id = (int) $video_id;
    if ($video_id < 1) {
        return false;
    }
    $video = $db->arraybuilder()->where('id', $video_id)->getOne(T_VIDEOS);
    if (!PT_IsTranscribableVideo($video)) {
        return false;
    }
    if (PT_IsVideoInTranscriptQueue($video_id)) {
        return true;
    }
    $row = PT_GetVideoTranscript($video_id);
    if (!empty($row) && PT_DbVal($row, 'status') === 'completed') {
        return false;
    }
    if (!empty($row) && PT_DbVal($row, 'status') === 'processing') {
        return false;
    }
    PT_UpsertVideoTranscript($video_id, array(
        'status' => 'pending',
        'error_message' => '',
    ));
    return $db->insert(T_TRANSCRIPT_QUEUE, array(
        'video_id' => $video_id,
        'processing' => 0,
        'created_at' => time(),
    ));
}

function PT_TranscriptTempDir() {
    $dir = PT_TranscriptRootDir() . 'upload/temp/transcribe';
    if (!file_exists($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function PT_TranscriptStorageDir() {
    $dir = PT_TranscriptRootDir() . 'upload/transcripts';
    if (!file_exists($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function PT_DownloadMediaToTemp($url, $dest) {
    $in = @fopen($url, 'rb');
    if ($in === false) {
        return false;
    }
    $out = @fopen($dest, 'wb');
    if ($out === false) {
        fclose($in);
        return false;
    }
    $ok = @stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    return $ok !== false;
}

function PT_ResolveVideoFileForTranscript($video) {
    global $pt;
    $root = PT_TranscriptRootDir();
    $relative = PT_DbVal($video, 'video_location', '');
    $local = $root . $relative;
    if (file_exists($local)) {
        if (@filesize($local) < 1024) {
            return array('path' => '', 'temp' => false, 'error' => 'Local video file is too small or empty: ' . $relative);
        }
        return array('path' => $local, 'temp' => false);
    }
    $url = PT_GetMedia($relative);
    if (empty($url)) {
        return array('path' => '', 'temp' => false, 'error' => 'Could not resolve video URL');
    }
    $temp = PT_TranscriptTempDir() . '/video_' . (int) PT_DbVal($video, 'id') . '_' . time() . '.mp4';
    if (!PT_DownloadMediaToTemp($url, $temp)) {
        @unlink($temp);
        return array('path' => '', 'temp' => false, 'error' => 'Could not download video from storage: ' . $relative);
    }
    $size = @filesize($temp);
    if ($size === false || $size < 1024) {
        @unlink($temp);
        return array('path' => '', 'temp' => false, 'error' => 'Downloaded video file is too small or empty (' . (int) $size . ' bytes)');
    }
    return array('path' => $temp, 'temp' => true);
}

function PT_VideoDurationSeconds($video) {
    if (!empty(PT_DbVal($video, 'duration')) && function_exists('durationToSeconds')) {
        $sec = durationToSeconds(PT_DbVal($video, 'duration'));
        if ($sec > 0) {
            return $sec;
        }
    }
    return 0;
}

function PT_FormatFfmpegError($output, $max_len = 500) {
    $output = trim((string) $output);
    if ($output === '') {
        return '(no FFmpeg output — shell_exec may be disabled or timed out)';
    }
    $lines = preg_split('/\r\n|\r|\n/', $output);
    $meaningful = array();
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (stripos($line, 'ffmpeg version') === 0
            || stripos($line, 'Copyright (c)') !== false
            || stripos($line, 'built with') === 0
            || stripos($line, 'configuration:') === 0) {
            continue;
        }
        $meaningful[] = $line;
    }
    if (!empty($meaningful)) {
        return substr(implode(' | ', array_slice($meaningful, -5)), 0, $max_len);
    }
    return substr($output, -$max_len);
}

function PT_ExtractAudioForTranscript($video_path, $temp_wav) {
    global $pt;
    if (empty($pt->config->ffmpeg_binary_file) || !is_executable($pt->config->ffmpeg_binary_file)) {
        return array('ok' => false, 'error' => 'FFmpeg binary is not configured or not executable');
    }
    if (!file_exists($video_path)) {
        return array('ok' => false, 'error' => 'Video file not found for audio extraction');
    }
    $video_size = @filesize($video_path);
    if ($video_size === false || $video_size < 1024) {
        return array('ok' => false, 'error' => 'Video file is too small or unreadable (' . (int) $video_size . ' bytes)');
    }
    $ffmpeg = escapeshellarg($pt->config->ffmpeg_binary_file);
    $in = escapeshellarg($video_path);
    $out = escapeshellarg($temp_wav);
    $cmd = "{$ffmpeg} -hide_banner -loglevel error -y -i {$in} -map 0:a:0? -vn -ac 1 -ar 16000 -c:a pcm_s16le {$out} 2>&1";
    $output = shell_exec($cmd);
    if (!file_exists($temp_wav) || filesize($temp_wav) < 1) {
        $detail = PT_FormatFfmpegError($output);
        if (stripos($detail, 'does not contain any stream') !== false
            || stripos($detail, 'Output file #0 does not contain any stream') !== false) {
            return array('ok' => false, 'error' => 'Video has no audio track');
        }
        return array('ok' => false, 'error' => 'FFmpeg audio extraction failed: ' . $detail);
    }
    return array('ok' => true);
}

function PT_RunWhisper($wav_path, $output_dir) {
    global $pt;
    $root = PT_TranscriptRootDir();
    $python = !empty($pt->config->whisper_command) ? $pt->config->whisper_command : 'python3';
    $script = !empty($pt->config->whisper_script) ? $pt->config->whisper_script : 'scripts/transcribe_whisper.py';
    if ($script[0] !== '/') {
        $script = $root . $script;
    }
    if (!file_exists($script)) {
        return array('ok' => false, 'error' => 'Whisper script not found: ' . $script);
    }
    $model = !empty($pt->config->whisper_model) ? $pt->config->whisper_model : 'base';
    $language = !empty($pt->config->transcript_language) ? $pt->config->transcript_language : 'en';
    if (!is_dir($output_dir)) {
        @mkdir($output_dir, 0777, true);
    }
    $cmd = escapeshellarg($python) . ' ' . escapeshellarg($script)
        . ' --input ' . escapeshellarg($wav_path)
        . ' --output_dir ' . escapeshellarg($output_dir)
        . ' --model ' . escapeshellarg($model)
        . ' --language ' . escapeshellarg($language)
        . ' 2>&1';
    $output = shell_exec($cmd);
    $base = pathinfo($wav_path, PATHINFO_FILENAME);
    $vtt = rtrim($output_dir, '/') . '/' . $base . '.vtt';
    if (file_exists($vtt)) {
        return array('ok' => true, 'vtt_path' => $vtt, 'output' => $output);
    }
    if (!empty($output)) {
        $lines = array_filter(array_map('trim', explode("\n", trim($output))));
        $last = end($lines);
        if (!empty($last) && file_exists($last) && substr($last, -4) === '.vtt') {
            return array('ok' => true, 'vtt_path' => $last, 'output' => $output);
        }
    }
    return array('ok' => false, 'error' => 'Whisper did not produce VTT: ' . substr((string) $output, 0, 800));
}

function PT_GetTranscribableVideosQuery($user_id, $options = array()) {
    global $db;
    $user_id = (int) $user_id;

    if (!empty($options['failed_only'])) {
        $failed_rows = $db->arraybuilder()->rawQuery(
            "SELECT video_id FROM " . T_VIDEO_TRANSCRIPTS . " WHERE status = 'failed'"
        );
        $failed_ids = array();
        if (!empty($failed_rows)) {
            foreach ($failed_rows as $r) {
                $failed_ids[] = (int) PT_DbVal($r, 'video_id');
            }
        }
        if (empty($failed_ids)) {
            return array();
        }
        $db->where('id', $failed_ids, 'IN');
    } elseif (!empty($options['skip_completed'])) {
        $completed_rows = $db->arraybuilder()->rawQuery(
            "SELECT video_id FROM " . T_VIDEO_TRANSCRIPTS . " WHERE status = 'completed'"
        );
        $completed_ids = array();
        if (!empty($completed_rows)) {
            foreach ($completed_rows as $r) {
                $completed_ids[] = (int) PT_DbVal($r, 'video_id');
            }
        }
        if (!empty($completed_ids)) {
            $db->where('id', $completed_ids, 'NOT IN');
        }
    }

    $db->where('user_id', $user_id);
    $db->where('youtube', '');
    $db->where('vimeo', '');
    $db->where('daily', '');
    $db->where('facebook', '');
    $db->where('twitch', '');
    $db->where('instagram', '');
    $db->where('ok', '');
    $db->where('is_movie', 0);
    $db->where('is_short', 0);
    $db->where('video_location', '', '!=');
    $db->where('video_location', 'http%', 'NOT LIKE');
    $db->where('video_location', 'https%', 'NOT LIKE');
    if (!empty($options['require_converted'])) {
        $db->where('converted', 2, '!=');
    }
    if (!empty($options['time_start']) && !empty($options['time_end'])) {
        $db->where('time', $options['time_start'], '>=');
        $db->where('time', $options['time_end'], '<=');
    }
    $limit = !empty($options['limit']) ? (int) $options['limit'] : 0;
    if ($limit > 0) {
        return $db->arraybuilder()->orderBy('time', 'DESC')->get(T_VIDEOS, $limit);
    }
    return $db->arraybuilder()->orderBy('time', 'DESC')->get(T_VIDEOS);
}

function PT_GetDefaultTranscriptChannelUsernames() {
    global $pt;
    $raw = !empty($pt->config->transcript_channel_usernames)
        ? $pt->config->transcript_channel_usernames
        : 'DrKevinConners,ConnersClinic';
    $names = array_filter(array_map('trim', explode(',', $raw)));
    return !empty($names) ? $names : array('DrKevinConners', 'ConnersClinic');
}

function PT_GetTranscriptChannels($options = array()) {
    global $db;
    $purpose = !empty($options['purpose']) ? $options['purpose'] : 'batch';
    $channels_by_id = array();

    foreach (PT_GetDefaultTranscriptChannelUsernames() as $username) {
        $user = $db->arraybuilder()->where('username', $username)->getOne(T_USERS);
        if (empty($user)) {
            $found = $db->arraybuilder()->rawQuery(
                'SELECT * FROM ' . T_USERS . ' WHERE LOWER(username) = ? LIMIT 1',
                array(strtolower($username))
            );
            $user = !empty($found[0]) ? $found[0] : null;
        }
        if (!empty($user)) {
            $channels_by_id[(int) PT_DbVal($user, 'id')] = (object) array(
                'id' => (int) PT_DbVal($user, 'id'),
                'username' => PT_DbVal($user, 'username'),
                'video_count' => 0,
                'transcribable_count' => 0,
            );
        }
    }

    $transcribable_case = PT_TranscribableVideoSqlCase('v');
    if ($purpose === 'seo') {
        $rows = $db->arraybuilder()->rawQuery(
            "SELECT u.id, u.username, COUNT(DISTINCT t.video_id) AS video_count,
                COUNT(DISTINCT t.video_id) AS transcribable_count
             FROM " . T_USERS . " u
             INNER JOIN " . T_VIDEOS . " v ON v.user_id = u.id
             INNER JOIN " . T_VIDEO_TRANSCRIPTS . " t ON t.video_id = v.id AND t.status = 'completed'
             WHERE t.plain_text IS NOT NULL AND t.plain_text <> ''
             GROUP BY u.id
             ORDER BY u.username ASC"
        );
    } else {
        $rows = $db->arraybuilder()->rawQuery(
            "SELECT u.id, u.username, COUNT(v.id) AS video_count,
                SUM(" . $transcribable_case . ") AS transcribable_count
             FROM " . T_USERS . " u
             INNER JOIN " . T_VIDEOS . " v ON v.user_id = u.id
             WHERE v.is_movie = 0 AND v.is_short = 0
             GROUP BY u.id
             HAVING video_count > 0
             ORDER BY u.username ASC"
        );
    }

    if (!empty($rows)) {
        foreach ($rows as $row) {
            $id = (int) PT_DbVal($row, 'id');
            if (!empty($channels_by_id[$id])) {
                $channels_by_id[$id]->video_count = (int) PT_DbVal($row, 'video_count', 0);
                $channels_by_id[$id]->transcribable_count = (int) PT_DbVal($row, 'transcribable_count', 0);
            } else {
                $channels_by_id[$id] = (object) array(
                    'id' => $id,
                    'username' => PT_DbVal($row, 'username'),
                    'video_count' => (int) PT_DbVal($row, 'video_count', 0),
                    'transcribable_count' => (int) PT_DbVal($row, 'transcribable_count', 0),
                );
            }
        }
    }

    $channels = array_values($channels_by_id);
    usort($channels, function ($a, $b) {
        return strcasecmp($a->username, $b->username);
    });
    return $channels;
}

function PT_FormatTranscriptChannelLabel($channel, $purpose = 'batch') {
    $total = (int) PT_DbVal($channel, 'video_count', 0);
    $transcribable = (int) PT_DbVal($channel, 'transcribable_count', 0);
    $label = '@' . PT_DbVal($channel, 'username');
    if ($purpose === 'seo') {
        return $label . ' (' . $total . ' with transcript' . ($total === 1 ? '' : 's') . ')';
    }
    if ($transcribable > 0 && $transcribable < $total) {
        return $label . ' (' . $transcribable . ' ready to transcribe, ' . $total . ' on channel)';
    }
    if ($transcribable > 0) {
        return $label . ' (' . $transcribable . ' self-hosted upload' . ($transcribable === 1 ? '' : 's') . ')';
    }
    return $label . ' (' . $total . ' on channel, 0 ready — check FFmpeg conversion or embed imports)';
}

function PT_TranscriptStatusForChannel($user_id) {
    global $db;
    $user_id = (int) $user_id;
    $counts = array(
        'pending' => 0,
        'processing' => 0,
        'completed' => 0,
        'failed' => 0,
        'skipped' => 0,
        'queued' => 0,
    );
    $rows = $db->arraybuilder()->rawQuery(
        "SELECT t.status, COUNT(*) AS cnt FROM " . T_VIDEO_TRANSCRIPTS . " t
         INNER JOIN " . T_VIDEOS . " v ON v.id = t.video_id
         WHERE v.user_id = " . $user_id . " GROUP BY t.status"
    );
    if (!empty($rows)) {
        foreach ($rows as $r) {
            $status_key = PT_DbVal($r, 'status');
            if (isset($counts[$status_key])) {
                $counts[$status_key] = (int) PT_DbVal($r, 'cnt', 0);
            }
        }
    }
    $counts['queued'] = (int) $db->arraybuilder()->rawQueryValue(
        "SELECT COUNT(*) FROM " . T_TRANSCRIPT_QUEUE . " q
         INNER JOIN " . T_VIDEOS . " v ON v.id = q.video_id
         WHERE v.user_id = " . $user_id . " AND q.processing = 0"
    );
    $recent = $db->arraybuilder()->rawQuery(
        "SELECT t.video_id, t.status, t.error_message, v.title FROM " . T_VIDEO_TRANSCRIPTS . " t
         INNER JOIN " . T_VIDEOS . " v ON v.id = t.video_id
         WHERE v.user_id = " . $user_id . " AND t.status IN ('failed','processing')
         ORDER BY t.updated_at DESC LIMIT 10"
    );
    return array('counts' => $counts, 'recent' => $recent ? $recent : array());
}

function PT_GetTranscriptStaleQueueMaxAge() {
    global $pt;
    $minutes = !empty($pt->config->transcript_queue_stale_minutes)
        ? (int) $pt->config->transcript_queue_stale_minutes
        : 90;
    if ($minutes < 15) {
        $minutes = 15;
    }
    return $minutes * 60;
}

function PT_ResetStaleTranscriptQueueJobs($max_age_seconds = null) {
    global $db;
    if ($max_age_seconds === null) {
        $max_age_seconds = PT_GetTranscriptStaleQueueMaxAge();
    }
    $cutoff = time() - (int) $max_age_seconds;
    $stale = $db->arraybuilder()
        ->where('processing', 1)
        ->where('created_at', $cutoff, '<')
        ->get(T_TRANSCRIPT_QUEUE);
    if (empty($stale)) {
        return 0;
    }
    $reset = 0;
    foreach ($stale as $row) {
        $video_id = (int) PT_DbVal($row, 'video_id');
        $queue_id = (int) PT_DbVal($row, 'id');
        $db->where('id', $queue_id)->update(T_TRANSCRIPT_QUEUE, array('processing' => 0));
        $transcript = PT_GetVideoTranscript($video_id);
        if (!empty($transcript) && PT_DbVal($transcript, 'status') === 'processing') {
            PT_UpsertVideoTranscript($video_id, array(
                'status' => 'pending',
                'error_message' => 'Queue job was reset after being stuck in processing',
            ));
        }
        $reset++;
    }
    return $reset;
}

function PT_SaveTranscriptCronLastResult($result) {
    global $db;
    $payload = array(
        'time' => time(),
        'message' => !empty($result['message']) ? $result['message'] : '',
        'picked' => !empty($result['picked']) ? (int) $result['picked'] : 0,
        'processed' => !empty($result['processed']) ? (int) $result['processed'] : 0,
        'stale_reset' => !empty($result['stale_reset']) ? (int) $result['stale_reset'] : 0,
        'errors' => !empty($result['errors']) ? $result['errors'] : array(),
    );
    $json = json_encode($payload);
    $exists = $db->where('name', 'transcript_cron_last_result')->getValue(T_CONFIG, 'COUNT(*)');
    if ($exists) {
        $db->where('name', 'transcript_cron_last_result')->update(T_CONFIG, array('value' => $json));
    } else {
        $db->insert(T_CONFIG, array('name' => 'transcript_cron_last_result', 'value' => $json));
    }
}

function PT_GetTranscriptCronDiagnostics() {
    global $pt, $db;
    $last_run = !empty($pt->config->transcript_cron_last_run) ? (int) $pt->config->transcript_cron_last_run : 0;
    $waiting = (int) $db->arraybuilder()->rawQueryValue(
        "SELECT COUNT(*) FROM " . T_TRANSCRIPT_QUEUE . " WHERE processing = 0 LIMIT 1"
    );
    $stuck = (int) $db->arraybuilder()->rawQueryValue(
        "SELECT COUNT(*) FROM " . T_TRANSCRIPT_QUEUE . " WHERE processing = 1 LIMIT 1"
    );
    $last_result = array();
    if (!empty($pt->config->transcript_cron_last_result)) {
        $decoded = json_decode($pt->config->transcript_cron_last_result, true);
        if (is_array($decoded)) {
            $last_result = $decoded;
        }
    }
    $cron_url = !empty($pt->config->site_url) ? rtrim($pt->config->site_url, '/') . '/transcribe-cron.php' : '';
    return array(
        'system_on' => ($pt->config->transcript_system == 'on'),
        'last_run' => $last_run,
        'last_run_ago' => $last_run > 0 ? (time() - $last_run) : null,
        'queue_waiting' => $waiting,
        'queue_stuck_processing' => $stuck,
        'jobs_per_run' => (int) ($pt->config->transcript_queue_count ?? 1),
        'cron_url' => $cron_url,
        'last_result' => $last_result,
        'likely_issue' => '',
    );
}

function PT_FinishHttpResponseForCron($message) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Encoding: none');
    header('Connection: close');
    ignore_user_abort(true);
    ob_start();
    header('Content-Type: application/json');
    $response = array('status' => 200, 'message' => $message);
    echo json_encode($response);
    $size = ob_get_length();
    header('Content-Length: ' . $size);
    ob_end_flush();
    flush();
    if (function_exists('session_write_close')) {
        session_write_close();
    }
    if (is_callable('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    if (is_callable('litespeed_finish_request')) {
        litespeed_finish_request();
    }
}

function PT_RunTranscriptCronBatch($options = array()) {
    global $db, $pt;
    $flush_early = !empty($options['flush_early_response']);
    $result = array(
        'status' => 200,
        'message' => '',
        'system_on' => ($pt->config->transcript_system == 'on'),
        'stale_reset' => 0,
        'picked' => 0,
        'processed' => 0,
        'errors' => array(),
    );

    if (!$result['system_on']) {
        $result['message'] = 'Transcript system disabled';
        PT_SaveTranscriptCronLastResult($result);
        return $result;
    }

    $result['stale_reset'] = PT_ResetStaleTranscriptQueueJobs();
    $reconcile = PT_ReconcileStuckTranscriptRecords();
    $result['reconciled'] = (int) ($reconcile['completed_from_db'] + $reconcile['completed_from_vtt']);
    $exists = $db->where('name', 'transcript_cron_last_run')->getValue(T_CONFIG, 'COUNT(*)');
    $now = time();
    if ($exists) {
        $db->where('name', 'transcript_cron_last_run')->update(T_CONFIG, array('value' => $now));
    } else {
        $db->insert(T_CONFIG, array('name' => 'transcript_cron_last_run', 'value' => $now));
    }

    $queue_count = !empty($pt->config->transcript_queue_count) ? (int) $pt->config->transcript_queue_count : 1;
    if ($queue_count < 1) {
        $queue_count = 1;
    }
    if ($queue_count > 5) {
        $queue_count = 5;
    }

    $process_queue = $db->arraybuilder()
        ->where('processing', 0)
        ->orderBy('created_at', 'ASC')
        ->get(T_TRANSCRIPT_QUEUE, $queue_count);
    $result['picked'] = !empty($process_queue) ? count($process_queue) : 0;

    if (empty($process_queue)) {
        $diag = PT_GetTranscriptCronDiagnostics();
        if ($diag['queue_stuck_processing'] > 0) {
            $result['message'] = 'No waiting jobs; ' . $diag['queue_stuck_processing'] . ' stuck with processing=1 (reset runs automatically when stale)';
        } else {
            $result['message'] = 'No jobs in queue';
        }
        PT_SaveTranscriptCronLastResult($result);
        return $result;
    }

    if ($flush_early) {
        PT_FinishHttpResponseForCron('Processing ' . count($process_queue) . ' job(s)');
    }

    if (function_exists('PT_RecordTranscriptLoadSnapshot')) {
        $load_record = PT_RecordTranscriptLoadSnapshot(true);
        if (!empty($load_record['snapshot']) && !empty($load_record['health'])) {
            PT_MaybeSendTranscriptLoadAlert($load_record['snapshot'], $load_record['health']);
        }
    }

    foreach ($process_queue as $queue_row) {
        if (!PT_AiClaimQueueRow(T_TRANSCRIPT_QUEUE, (int) PT_DbVal($queue_row, 'id'))) {
            continue; // another worker claimed this job first
        }
        try {
            PT_ProcessTranscriptJob($queue_row);
            $result['processed']++;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            PT_TranscriptJobFailed($queue_row, (int) PT_DbVal($queue_row, 'video_id'), $msg, 3);
            $result['errors'][] = $msg;
        }
        $stuck = $db->arraybuilder()->where('id', (int) PT_DbVal($queue_row, 'id'))->getOne(T_TRANSCRIPT_QUEUE);
        if (!empty($stuck) && !empty($stuck['processing'])) {
            $db->where('id', (int) PT_DbVal($queue_row, 'id'))->delete(T_TRANSCRIPT_QUEUE);
            PT_ReconcileStuckTranscriptRecord((int) PT_DbVal($queue_row, 'video_id'), array('run_seo' => true));
        }
    }

    $result['message'] = 'Processed ' . $result['processed'] . ' of ' . $result['picked'] . ' job(s)';
    if (!empty($result['stale_reset'])) {
        $result['message'] .= '; reset ' . $result['stale_reset'] . ' stale job(s)';
    }
    if (!empty($result['reconciled'])) {
        $result['message'] .= '; reconciled ' . $result['reconciled'] . ' stuck transcript(s)';
    }
    if (!empty($result['errors'])) {
        $result['message'] .= '; ' . count($result['errors']) . ' error(s)';
    }
    PT_SaveTranscriptCronLastResult($result);
    return $result;
}

function PT_CleanupTranscriptTemp($paths) {
    foreach ($paths as $path) {
        if (!empty($path) && file_exists($path)) {
            @unlink($path);
        }
    }
}

function PT_ProcessTranscriptJob($queue_row) {
    global $db, $pt;
    $video_id = (int) PT_DbVal($queue_row, 'video_id');
    $video = $db->arraybuilder()->where('id', $video_id)->getOne(T_VIDEOS);
    $temp_files = array();
    $max_attempts = 3;

    if (empty($video) || !PT_IsTranscribableVideo($video)) {
        PT_UpsertVideoTranscript($video_id, array(
            'status' => 'skipped',
            'error_message' => 'Video is not eligible for transcription',
        ));
        $db->where('id', (int) PT_DbVal($queue_row, 'id'))->delete(T_TRANSCRIPT_QUEUE);
        return;
    }

    $max_duration = !empty($pt->config->transcript_max_duration) ? (int) $pt->config->transcript_max_duration : 7200;
    $duration_sec = PT_VideoDurationSeconds($video);
    if ($max_duration > 0 && $duration_sec > $max_duration) {
        PT_UpsertVideoTranscript($video_id, array(
            'status' => 'skipped',
            'error_message' => 'Video exceeds max duration (' . $max_duration . 's)',
        ));
        $db->where('id', (int) PT_DbVal($queue_row, 'id'))->delete(T_TRANSCRIPT_QUEUE);
        return;
    }

    PT_UpsertVideoTranscript($video_id, array('status' => 'processing', 'error_message' => ''));

    $resolved = PT_ResolveVideoFileForTranscript($video);
    if (empty($resolved['path'])) {
        PT_TranscriptJobFailed($queue_row, $video_id, $resolved['error'] ?? 'Video file not found', $max_attempts);
        return;
    }
    if (!empty($resolved['temp'])) {
        $temp_files[] = $resolved['path'];
    }

    $wav = PT_TranscriptTempDir() . '/audio_' . $video_id . '_' . time() . '.wav';
    $temp_files[] = $wav;
    $extract = PT_ExtractAudioForTranscript($resolved['path'], $wav);
    if (empty($extract['ok'])) {
        PT_TranscriptJobFailed($queue_row, $video_id, $extract['error'], $max_attempts);
        PT_CleanupTranscriptTemp($temp_files);
        return;
    }

    $whisper_out = PT_TranscriptTempDir() . '/out_' . $video_id . '_' . time();
    @mkdir($whisper_out, 0777, true);
    $whisper = PT_RunWhisper($wav, $whisper_out);
    if (empty($whisper['ok'])) {
        PT_TranscriptJobFailed($queue_row, $video_id, $whisper['error'], $max_attempts);
        PT_CleanupTranscriptTemp($temp_files);
        if (is_dir($whisper_out)) {
            @rmdir($whisper_out);
        }
        return;
    }

    $vtt_content = file_get_contents($whisper['vtt_path']);
    $plain = PT_VttToPlainText($vtt_content);
    $dest_rel = 'upload/transcripts/' . PT_DbVal($video, 'video_id') . '.vtt';
    $dest_abs = PT_TranscriptRootDir() . $dest_rel;
    PT_TranscriptStorageDir();
    if (!@copy($whisper['vtt_path'], $dest_abs)) {
        PT_TranscriptJobFailed($queue_row, $video_id, 'Could not save VTT file', $max_attempts);
        PT_CleanupTranscriptTemp($temp_files);
        return;
    }
    $temp_files[] = $whisper['vtt_path'];

    if ($pt->remoteStorage) {
        PT_UploadToS3($dest_rel, array('delete' => true));
    }

    $language = !empty($pt->config->transcript_language) ? $pt->config->transcript_language : 'en';
    PT_FinalizeTranscriptWithSeo($video, $plain, $dest_rel, $language);
    $db->where('id', (int) PT_DbVal($queue_row, 'id'))->delete(T_TRANSCRIPT_QUEUE);
    PT_CleanupTranscriptTemp($temp_files);
    if (is_dir($whisper_out)) {
        $files = glob($whisper_out . '/*');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($whisper_out);
    }
}

function PT_TranscriptJobFailed($queue_row, $video_id, $error, $max_attempts) {
    global $db;
    $row = PT_GetVideoTranscript($video_id);
    $attempts = !empty(PT_DbVal($row, 'attempts')) ? (int) PT_DbVal($row, 'attempts') + 1 : 1;
    PT_UpsertVideoTranscript($video_id, array(
        'status' => 'failed',
        'error_message' => substr($error, 0, 2000),
        'attempts' => $attempts,
    ));
    $db->where('id', (int) PT_DbVal($queue_row, 'id'))->delete(T_TRANSCRIPT_QUEUE);
    if ($attempts < $max_attempts) {
        $db->insert(T_TRANSCRIPT_QUEUE, array(
            'video_id' => (int) $video_id,
            'processing' => 0,
            'created_at' => time(),
        ));
        PT_UpsertVideoTranscript($video_id, array('status' => 'pending'));
    }
}

function PT_FormatTranscribeEnqueueMessage($enqueued, $skipped, $matched, $context = array()) {
    $enqueued = (int) $enqueued;
    $skipped = (int) $skipped;
    $matched = (int) $matched;
    if ($enqueued > 0) {
        $message = $enqueued . ' video(s) queued for transcription';
        if ($skipped > 0) {
            $message .= ', ' . $skipped . ' skipped';
        }
        return $message;
    }
    if ($matched > 0 && $skipped > 0) {
        return '0 video(s) queued for transcription (' . $skipped . ' matched but skipped — already queued, processing, completed, or not eligible).';
    }
    $parts = array('0 video(s) queued for transcription.');
    $hints = array();
    $eligible_without_skip = !empty($context['eligible_without_skip']) ? (int) $context['eligible_without_skip'] : 0;
    $range = '';
    if (!empty($context['time_start']) && !empty($context['time_end'])) {
        $range = date('M j, Y', $context['time_start']) . ' – ' . date('M j, Y', $context['time_end']);
    }
    if (!empty($context['skip_completed']) && $eligible_without_skip > 0) {
        $hints[] = $eligible_without_skip . ' eligible self-hosted upload(s)'
            . ($range ? ' in ' . $range : '')
            . ' are already transcribed or in progress — "Skip already transcribed" hides them';
        $hints[] = 'uncheck that box only if you need to re-run Whisper on completed videos';
    } elseif (!empty($context['date_filtered'])) {
        $hints[] = 'No self-hosted uploads on this channel fall in the selected date range'
            . ($range ? ' (' . $range . ', week runs Saturday–Friday)' : '');
    } else {
        $hints[] = 'No self-hosted uploads on this channel match the batch filters';
    }
    if (!empty($context['skip_completed']) && $eligible_without_skip === 0) {
        $hints[] = 'try "All time" or a wider date range if uploads are older';
    }
    if (!empty($context['failed_only'])) {
        $hints[] = 'there are no failed transcripts for this channel';
    }
    $hints[] = 'YouTube/Vimeo imports, shorts, movies, and videos still converting (FFmpeg) are not eligible';
    $parts[] = implode('; ', $hints) . '.';
    return implode(' ', $parts);
}

function PT_TranscriptTimeRange($selected_time) {
    $time_start = 0;
    $time_end = 0;
    if ($selected_time == 'today') {
        $time_start = strtotime(date('M') . ' ' . date('d') . ', ' . date('Y') . ' 12:00am');
        $time_end = strtotime(date('M') . ' ' . date('d') . ', ' . date('Y') . ' 11:59pm');
    } elseif ($selected_time == 'this_week') {
        $time = strtotime(date('l') . ', ' . date('M') . ' ' . date('d') . ', ' . date('Y'));
        if (date('l') == 'Saturday') {
            $time_start = strtotime(date('M') . ' ' . date('d') . ', ' . date('Y') . ' 12:00am');
        } else {
            $time_start = strtotime('last saturday, 12:00am', $time);
        }
        if (date('l') == 'Friday') {
            $time_end = strtotime(date('M') . ' ' . date('d') . ', ' . date('Y') . ' 11:59pm');
        } else {
            $time_end = strtotime('next Friday, 11:59pm', $time);
        }
    } elseif ($selected_time == 'this_month') {
        $time_start = strtotime('1 ' . date('M') . ' ' . date('Y') . ' 12:00am');
        $time_end = strtotime(cal_days_in_month(CAL_GREGORIAN, date('m'), date('Y')) . ' ' . date('M') . ' ' . date('Y') . ' 11:59pm');
    } elseif ($selected_time == 'this_year') {
        $time_start = strtotime('1 January ' . date('Y') . ' 12:00am');
        $time_end = strtotime('31 December ' . date('Y') . ' 11:59pm');
    }
    return array('time_start' => $time_start, 'time_end' => $time_end);
}
