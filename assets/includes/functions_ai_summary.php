<?php

/**
 * Complete-transcript summaries and key takeaways.
 *
 * - Normal videos: the WHOLE transcript goes to the model in one call.
 * - Very long transcripts: sections (token-aware, overlapping) are digested one by one,
 *   the digests are merged (hierarchically if needed) and one final call writes the
 *   summary + takeaways. Any section that fails fails the whole run visibly; nothing is
 *   silently skipped.
 */

function PT_AiSummaryConfig() {
    $c = PT_AiConfigSection('summary');
    return array_merge(array(
        'single_pass_max_input_tokens' => 150000,
        'chunk_tokens' => 40000,
        'chunk_overlap_tokens' => 1000,
        'reduce_max_input_tokens' => 60000,
        'max_reduce_depth' => 4,
    ), $c);
}

function PT_AiSummarySystemPrompt() {
    global $pt;
    $default = PT_GetDefaultOpenAiSummaryPrompt();
    $system = !empty($pt->config->openai_summary_prompt) ? $pt->config->openai_summary_prompt : $default;
    if (stripos($system, 'key_takeaways') === false) {
        $system .= ' ' . $default;
    }
    return $system;
}

function PT_AiSummaryCoverageRules() {
    return ' The input is the ENTIRE video (or a digest of every section of it). '
        . 'Cover the whole video, not just the opening: give the beginning, middle and end fair weight, and make the key takeaways span the full talk in order. '
        . 'Use only what the speaker actually says; never add claims, studies, numbers or advice that are not in the text. '
        . 'Keep doses, lab values, percentages, drug/supplement/gene names exactly as stated. '
        . 'Write for people learning from Dr. Conners\' cancer education videos: plain language, no hype, no promise of cures or outcomes.';
}

function PT_AiSummaryUserMessage($video, $body, $label) {
    $title = PT_DbVal($video, 'title') ? strip_tags(PT_DbVal($video, 'title')) : '';
    $tags = PT_DbVal($video, 'tags') ? strip_tags(PT_DbVal($video, 'tags')) : '';
    return "Video title: {$title}\nTags: {$tags}\n\n{$label}:\n{$body}";
}

/** Normalise one section digest; returns array or null if it carries nothing. */
function PT_AiNormalizeSectionDigest($parsed) {
    if (!is_array($parsed)) {
        return null;
    }
    $strs = function ($v) {
        $out = array();
        if (is_array($v)) {
            foreach ($v as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $out[] = trim($item);
                }
            }
        }
        return $out;
    };
    $digest = array(
        'section_summary' => (!empty($parsed['section_summary']) && is_string($parsed['section_summary'])) ? trim($parsed['section_summary']) : '',
        'key_points' => $strs(isset($parsed['key_points']) ? $parsed['key_points'] : array()),
        'specifics' => $strs(isset($parsed['specifics']) ? $parsed['specifics'] : array()),
    );
    if ($digest['section_summary'] === '' && empty($digest['key_points'])) {
        return null;
    }
    return $digest;
}

function PT_AiDigestToText($digest, $label) {
    $lines = array($label);
    if ($digest['section_summary'] !== '') {
        $lines[] = $digest['section_summary'];
    }
    foreach ($digest['key_points'] as $p) {
        $lines[] = '- ' . $p;
    }
    if (!empty($digest['specifics'])) {
        $lines[] = 'Exact specifics (keep verbatim): ' . implode('; ', $digest['specifics']);
    }
    return implode("\n", $lines);
}

function PT_AiDigestSection($video, $text, $label, $video_id, $pass_name) {
    $system = 'You extract information from ONE section of a long health-education video transcript. '
        . 'Respond with a single JSON object only: {"section_summary":"3-6 sentences","key_points":["..."],"specifics":["..."]}. '
        . 'key_points: every distinct teaching point, claim or example in this section (up to 12), in order. '
        . 'specifics: exact doses, lab values, percentages, names of drugs, supplements, genes, tests, studies, as stated (may be empty). '
        . 'Text at the very start or end may repeat neighbouring sections (overlap); do not treat repeats as new points. '
        . 'Use only what is in this section; invent nothing.';
    $user = PT_AiSummaryUserMessage($video, $text, $label);
    for ($try = 1; $try <= 2; $try++) {
        $res = PT_AiChat(array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ), 'summary_sections', array('json' => true, 'video_id' => $video_id, 'pass_name' => $pass_name));
        if (empty($res['ok'])) {
            if (!empty($res['truncated']) || $try === 2 || in_array($res['error_code'], array('budget', 'auth', 'quota', 'no_key'), true)) {
                return array('ok' => false, 'error' => $label . ': ' . $res['error']);
            }
            continue;
        }
        $digest = PT_AiNormalizeSectionDigest(PT_ParseJsonFromAiResponse($res['content']));
        if ($digest !== null) {
            return array('ok' => true, 'digest' => $digest);
        }
        if ($try === 2) {
            return array('ok' => false, 'error' => $label . ': model returned an unusable section digest');
        }
    }
    return array('ok' => false, 'error' => $label . ': failed');
}

function PT_AiFinalSummaryCall($video, $body, $label, $video_id, $pass_name) {
    $system = PT_AiSummarySystemPrompt() . PT_AiSummaryCoverageRules();
    $res = PT_AiChat(array(
        array('role' => 'system', 'content' => $system),
        array('role' => 'user', 'content' => PT_AiSummaryUserMessage($video, $body, $label)),
    ), 'summary', array('json' => true, 'video_id' => $video_id, 'pass_name' => $pass_name));
    if (empty($res['ok'])) {
        return array('ok' => false, 'error' => $res['error']);
    }
    $parsed = PT_ParseJsonFromAiResponse($res['content']);
    if (!is_array($parsed)) {
        return array('ok' => false, 'error' => 'Model returned a summary that is not valid JSON');
    }
    $norm = PT_NormalizeTranscriptAiResponse($parsed);
    if ($norm['summary'] === '') {
        return array('ok' => false, 'error' => 'OpenAI returned an empty summary');
    }
    return array('ok' => true, 'summary' => $norm['summary'], 'key_takeaways' => $norm['key_takeaways']);
}

/**
 * @return array ok, summary, key_takeaways, error, mode ('single'|'multi'), sections, usage(calls,tokens,cost_usd), warnings
 */
function PT_AiSummarizeTranscript($video, $plain_text, $options = array()) {
    $video_id = (int) PT_DbVal($video, 'id', 0);
    $text = trim((string) $plain_text);
    $out = array('ok' => false, 'summary' => '', 'key_takeaways' => array(), 'error' => '', 'mode' => '', 'sections' => 0, 'usage' => array(), 'warnings' => array());
    if ($text === '') {
        $out['error'] = 'Transcript is empty';
        return $out;
    }
    $cfg = PT_AiSummaryConfig();
    $tokens = PT_AiEstimateTokens($text);
    $hard_cap = (int) PT_AiLimit('summary_max_transcript_tokens');
    if ($hard_cap > 0 && $tokens > $hard_cap) {
        $out['error'] = 'Transcript is too long to summarise safely (about ' . $tokens . ' tokens; limit ' . $hard_cap . '). Raise "ai_summary_max_transcript_tokens" to allow it.';
        return $out;
    }
    $before = PT_AiBudgetSnapshot();

    if ($tokens <= (int) $cfg['single_pass_max_input_tokens']) {
        $out['mode'] = 'single';
        $out['sections'] = 1;
        $final = PT_AiFinalSummaryCall($video, $text, 'Full transcript', $video_id, 'summary');
    } else {
        $out['mode'] = 'multi';
        $chunks = PT_AiSplitText($text, (int) $cfg['chunk_tokens'], (int) $cfg['chunk_overlap_tokens']);
        $out['sections'] = count($chunks);
        if (!PT_AiChunksCoverText($text, $chunks, (int) $cfg['chunk_tokens'])) {
            $out['error'] = 'Internal error: section split did not cover the whole transcript';
            return $out;
        }
        $digests = array();
        $n = count($chunks);
        foreach ($chunks as $i => $chunk) {
            $label = 'Section ' . ($i + 1) . ' of ' . $n;
            $d = PT_AiDigestSection($video, $chunk['text'], $label, $video_id, 'summary_section_' . ($i + 1));
            if (empty($d['ok'])) {
                $out['error'] = 'Long-transcript summary stopped: ' . $d['error'];
                $out['usage'] = PT_AiBudgetDelta($before, PT_AiBudgetSnapshot());
                return $out;
            }
            $digests[] = PT_AiDigestToText($d['digest'], '[' . $label . ']');
        }
        $depth = 0;
        while (PT_AiEstimateTokens(implode("\n\n", $digests)) > (int) $cfg['reduce_max_input_tokens']) {
            $depth++;
            if ($depth > (int) $cfg['max_reduce_depth'] || count($digests) < 2) {
                $out['error'] = 'Long-transcript summary stopped: section digests are too large to merge';
                $out['usage'] = PT_AiBudgetDelta($before, PT_AiBudgetSnapshot());
                return $out;
            }
            $groups = array();
            $current = array();
            $current_tokens = 0;
            foreach ($digests as $dg) {
                $t = PT_AiEstimateTokens($dg);
                if (!empty($current) && $current_tokens + $t > (int) $cfg['reduce_max_input_tokens'] / 2) {
                    $groups[] = $current;
                    $current = array();
                    $current_tokens = 0;
                }
                $current[] = $dg;
                $current_tokens += $t;
            }
            if (!empty($current)) {
                $groups[] = $current;
            }
            if (count($groups) >= count($digests)) {
                $out['error'] = 'Long-transcript summary stopped: cannot merge section digests further';
                $out['usage'] = PT_AiBudgetDelta($before, PT_AiBudgetSnapshot());
                return $out;
            }
            $merged = array();
            foreach ($groups as $gi => $g) {
                $label = 'Merged group ' . ($gi + 1) . ' of ' . count($groups) . ' (level ' . $depth . ')';
                $d = PT_AiDigestSection($video, implode("\n\n", $g), $label, $video_id, 'summary_merge_' . $depth . '_' . ($gi + 1));
                if (empty($d['ok'])) {
                    $out['error'] = 'Long-transcript summary stopped: ' . $d['error'];
                    $out['usage'] = PT_AiBudgetDelta($before, PT_AiBudgetSnapshot());
                    return $out;
                }
                $merged[] = PT_AiDigestToText($d['digest'], '[' . $label . ']');
            }
            $digests = $merged;
        }
        $final = PT_AiFinalSummaryCall($video, implode("\n\n", $digests), 'Digests of every section of the video, in order', $video_id, 'summary_final');
    }

    $out['usage'] = PT_AiBudgetDelta($before, PT_AiBudgetSnapshot());
    if (empty($final['ok'])) {
        $out['error'] = $final['error'];
        return $out;
    }
    $out['ok'] = true;
    $out['summary'] = $final['summary'];
    $out['key_takeaways'] = $final['key_takeaways'];
    if (empty($out['key_takeaways'])) {
        $out['warnings'][] = 'No key takeaways were returned';
    }
    return $out;
}
