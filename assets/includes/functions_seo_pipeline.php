<?php

/**
 * SEO content pipeline — runs after transcription; consumes raw transcript, produces blog articles.
 * Prompts and standards live under content-pipeline/ (editable without code changes).
 */

function PT_SeoPipelineRootDir() {
    return PT_TranscriptRootDir() . 'content-pipeline/';
}

function PT_SeoPipelineStatuses() {
    return array(
        'transcript_ready',
        'pass_0_running',
        'pass_0_complete',
        'entities_extracting',
        'entities_extracted',
        'entities_comparing',
        'entities_compared',
        'entity_library_updated',
        'entity_review_queue_created',
        'pass_1_running',
        'pass_1_complete',
        'pass_2_running',
        'pass_2_complete',
        'pass_3_running',
        'pass_3_complete',
        'pass_4_running',
        'complete',
        'ready_for_review',
        'failed',
    );
}

function PT_SeoPipelineJsonFile($relative) {
    $path = PT_SeoPipelineRootDir() . $relative;
    if (!file_exists($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function PT_SeoPipelineWriteJsonFile($relative, $data) {
    $path = PT_SeoPipelineRootDir() . $relative;
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return file_put_contents($path, $json) !== false;
}

/** Stage => default model (from content-pipeline/config/pipeline-models.json; see functions_ai.php). */
function PT_LoadSeoPipelineModels() {
    return PT_AiConfigSection('defaults');
}

function PT_GetSeoPipelineModel($pass_key) {
    return PT_AiStageModel($pass_key);
}

function PT_LoadSeoVersions() {
    $v = PT_SeoPipelineJsonFile('config/versions.json');
    if (empty($v)) {
        $v = array('prompts' => array(), 'standards' => array());
    }
    return $v;
}

function PT_BumpSeoFileVersion($type, $key) {
    $v = PT_LoadSeoVersions();
    if (!isset($v[$type][$key])) {
        $v[$type][$key] = 1;
    } else {
        $v[$type][$key] = (int) $v[$type][$key] + 1;
    }
    PT_SeoPipelineWriteJsonFile('config/versions.json', $v);
    return $v[$type][$key];
}

function PT_GetPromptVersion($prompt_slug) {
    $v = PT_LoadSeoVersions();
    return !empty($v['prompts'][$prompt_slug]) ? (int) $v['prompts'][$prompt_slug] : 1;
}

function PT_GetStandardsVersion($standards_key) {
    $v = PT_LoadSeoVersions();
    return !empty($v['standards'][$standards_key]) ? (int) $v['standards'][$standards_key] : 1;
}

function PT_GetEditorialStandardsVersion() {
    return max(
        PT_GetStandardsVersion('brand-standards'),
        PT_GetStandardsVersion('medical-terminology-standards'),
        PT_GetStandardsVersion('seo-standards')
    );
}

function PT_LoadBrandStandards() {
    return PT_SeoPipelineJsonFile('standards/brand-standards.json') ?: array();
}

function PT_LoadMedicalTerminologyStandards() {
    return PT_SeoPipelineJsonFile('standards/medical-terminology-standards.json') ?: array();
}

function PT_LoadSeoStandards() {
    return PT_SeoPipelineJsonFile('standards/seo-standards.json') ?: array();
}

function PT_LoadEditorialStandards() {
    return array(
        'brand' => PT_LoadBrandStandards(),
        'medical_terminology' => PT_LoadMedicalTerminologyStandards(),
        'seo' => PT_LoadSeoStandards(),
    );
}

function PT_LoadEntityLibrary() {
    $lib = PT_SeoPipelineJsonFile('standards/entity-library.json');
    if (empty($lib)) {
        return array('entities' => array());
    }
    if (!empty($lib['entities']) && is_array($lib['entities'])) {
        return $lib;
    }
    return PT_NormalizeEntityLibraryToSlugKeyed($lib);
}

function PT_NormalizeEntityLibraryToSlugKeyed($lib) {
    $entities = array();
    if (!empty($lib['entities']) && is_array($lib['entities'])) {
        return array('entities' => $lib['entities']);
    }
    foreach ($lib as $category => $items) {
        if ($category === 'file_name' || !is_array($items)) {
            continue;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = !empty($item['name']) ? $item['name'] : '';
            $slug = !empty($item['slug']) ? $item['slug'] : PT_SeoSlugify($name);
            if ($slug === '') {
                continue;
            }
            $item['category'] = !empty($item['category']) ? $item['category'] : $category;
            $item['slug'] = $slug;
            $item['aliases'] = !empty($item['aliases']) ? $item['aliases'] : array();
            $item['status'] = !empty($item['status']) ? $item['status'] : 'active';
            $entities[$slug] = $item;
        }
    }
    return array('entities' => $entities);
}

function PT_SaveEntityLibrary($library) {
    if (empty($library['entities'])) {
        $library = PT_NormalizeEntityLibraryToSlugKeyed($library);
    }
    PT_BumpSeoFileVersion('standards', 'entity-library');
    return PT_SeoPipelineWriteJsonFile('standards/entity-library.json', $library);
}

function PT_SeoSlugify($text) {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function PT_ListSeoPromptFiles() {
    return array(
        'pass-0-clean-transcript' => 'Pass 0: Clean transcript',
        'entity-extraction' => 'Entity extraction',
        'entity-comparison' => 'Entity comparison',
        'entity-auto-merge' => 'Entity auto-merge (reference)',
        'entity-review-queue' => 'Entity review queue',
        'pass-1-seo-blueprint' => 'Pass 1: SEO blueprint',
        'pass-2-blog-article' => 'Pass 2: Blog article',
        'pass-3-seo-audit' => 'Pass 3: SEO audit',
        'pass-4-final-revision' => 'Pass 4: Final revision',
    );
}

function PT_LoadPromptTemplate($slug) {
    $path = PT_SeoPipelineRootDir() . 'prompts/' . $slug . '.md';
    if (!file_exists($path)) {
        return '';
    }
    return file_get_contents($path);
}

function PT_SavePromptTemplate($slug, $content) {
    $allowed = array_keys(PT_ListSeoPromptFiles());
    if (!in_array($slug, $allowed, true)) {
        return false;
    }
    $path = PT_SeoPipelineRootDir() . 'prompts/' . $slug . '.md';
    PT_BumpSeoFileVersion('prompts', $slug);
    return file_put_contents($path, $content) !== false;
}

function PT_ReplacePromptPlaceholders($template, $vars) {
    $map = array(
        '[EDITORIAL_STANDARDS_JSON]' => 'EDITORIAL_STANDARDS_JSON',
        '[ENTITY_LIBRARY_JSON]' => 'ENTITY_LIBRARY_JSON',
        '[PASTE TRANSCRIPT]' => 'PASTE_TRANSCRIPT',
        '[PASTE CLEANED TRANSCRIPT]' => 'PASTE_CLEANED_TRANSCRIPT',
        '[PASTE ENTITY EXTRACTION OUTPUT]' => 'ENTITY_EXTRACTION_OUTPUT',
        '[ENTITY_COMPARISON_RESULTS_JSON]' => 'ENTITY_COMPARISON_RESULTS_JSON',
        '[PASS_0_OUTPUT]' => 'PASS_0_OUTPUT',
        '[PASS_1_OUTPUT]' => 'PASS_1_OUTPUT',
        '[PASS_2_OUTPUT]' => 'PASS_2_OUTPUT',
        '[PASS_3_OUTPUT]' => 'PASS_3_OUTPUT',
        '[PASS_2_ARTICLE]' => 'PASS_2_OUTPUT',
    );
    foreach ($map as $placeholder => $key) {
        if (strpos($template, $placeholder) === false) {
            continue;
        }
        $value = '';
        if (!empty($vars[$key])) {
            $value = is_string($vars[$key]) ? $vars[$key] : json_encode($vars[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $template = str_replace($placeholder, $value, $template);
    }
    return $template;
}

function PT_GetVideoSeoArticle($video_id) {
    global $db;
    $video_id = (int) $video_id;
    if ($video_id < 1) {
        return null;
    }
    return $db->where('video_id', $video_id)->getOne(T_VIDEO_SEO_ARTICLES);
}

function PT_UpsertVideoSeoArticle($video_id, $fields) {
    global $db;
    $video_id = (int) $video_id;
    $now = time();
    $json_fields = array(
        'transcript_quality',
        'entity_extraction_output',
        'entity_comparison_output',
        'entity_review_queue_output',
        'seo_audit',
        'prompt_versions',
        'standards_versions',
    );
    foreach ($json_fields as $jf) {
        if (isset($fields[$jf]) && is_array($fields[$jf])) {
            $fields[$jf] = json_encode($fields[$jf], JSON_UNESCAPED_UNICODE);
        }
    }
    $fields['updated_at'] = $now;
    $existing = PT_GetVideoSeoArticle($video_id);
    if (empty($existing)) {
        $fields['video_id'] = $video_id;
        $fields['created_at'] = $now;
        return $db->insert(T_VIDEO_SEO_ARTICLES, $fields);
    }
    return $db->where('video_id', $video_id)->update(T_VIDEO_SEO_ARTICLES, $fields);
}

function PT_SeoArticleToArray($row) {
    if (empty($row)) {
        return null;
    }
    $json_fields = array(
        'transcript_quality',
        'entity_extraction_output',
        'entity_comparison_output',
        'entity_review_queue_output',
        'seo_audit',
        'prompt_versions',
        'standards_versions',
    );
    $out = (array) $row;
    foreach ($json_fields as $jf) {
        if (!empty($out[$jf]) && is_string($out[$jf])) {
            $decoded = json_decode($out[$jf], true);
            if (is_array($decoded)) {
                $out[$jf] = $decoded;
            }
        }
    }
    return $out;
}

function PT_InitializeSeoArticleFromTranscript($video_id) {
    $video_id = (int) $video_id;
    $transcript = PT_GetVideoTranscript($video_id);
    // PT_GetVideoTranscript() returns an array (arraybuilder), so read it with PT_DbVal, not ->
    if (empty($transcript) || PT_DbVal($transcript, 'status') !== 'completed' || empty(PT_DbVal($transcript, 'plain_text'))) {
        return array('ok' => false, 'error' => 'Video does not have a completed transcript');
    }
    $existing = PT_GetVideoSeoArticle($video_id);
    $raw = PT_DbVal($transcript, 'plain_text');
    $versions = PT_LoadSeoVersions();
    $fields = array(
        'raw_transcript' => $raw,
        'status' => 'transcript_ready',
        'error_message' => '',
        'failed_pass' => null,
        'failed_raw_response' => null,
        'pass_0_prompt_version' => PT_GetPromptVersion('pass-0-clean-transcript'),
        'editorial_standards_version' => PT_GetEditorialStandardsVersion(),
        'prompt_versions' => !empty($versions['prompts']) ? $versions['prompts'] : array(),
        'standards_versions' => !empty($versions['standards']) ? $versions['standards'] : array(),
    );
    if (empty($existing)) {
        $fields['cleaned_transcript'] = null;
    } elseif (empty($existing->raw_transcript)) {
        $fields['raw_transcript'] = $raw;
    }
    PT_UpsertVideoSeoArticle($video_id, $fields);
    return array('ok' => true, 'article' => PT_GetVideoSeoArticle($video_id));
}

function PT_SeoPipelineClearFromPass($video_id, $rerun_from) {
    $clears = array(
        'pass_0' => array(
            'cleaned_transcript', 'transcript_quality',
            'entity_extraction_output', 'entity_comparison_output', 'entity_review_queue_output',
            'seo_blueprint', 'draft_article_markdown', 'seo_audit', 'final_article_markdown',
            'seo_title', 'meta_description', 'primary_keyword', 'url_slug',
        ),
        'pass_1' => array(
            'seo_blueprint', 'draft_article_markdown', 'seo_audit', 'final_article_markdown',
            'seo_title', 'meta_description', 'primary_keyword', 'url_slug',
        ),
        'pass_2' => array(
            'draft_article_markdown', 'seo_audit', 'final_article_markdown',
            'seo_title', 'meta_description', 'primary_keyword', 'url_slug',
        ),
        'pass_4' => array(
            'final_article_markdown', 'seo_title', 'meta_description', 'primary_keyword', 'url_slug',
        ),
    );
    if (empty($clears[$rerun_from])) {
        return;
    }
    $update = array();
    foreach ($clears[$rerun_from] as $field) {
        $update[$field] = null;
    }
    PT_UpsertVideoSeoArticle($video_id, $update);
}

function PT_SeoStatusForRerun($rerun_from) {
    $map = array(
        'pass_0' => 'transcript_ready',
        'pass_1' => 'entity_review_queue_created',
        'pass_2' => 'pass_1_complete',
        'pass_4' => 'pass_3_complete',
    );
    return !empty($map[$rerun_from]) ? $map[$rerun_from] : 'transcript_ready';
}

function PT_IsVideoInSeoPipelineQueue($video_id) {
    global $db;
    return $db->where('video_id', (int) $video_id)->where('processing', 0)->getValue(T_SEO_PIPELINE_QUEUE, 'COUNT(*)') > 0;
}

function PT_EnqueueSeoPipeline($video_id, $rerun_from = null, $force = false) {
    global $db;
    $video_id = (int) $video_id;
    if ($video_id < 1) {
        return false;
    }
    if (PT_IsVideoInSeoPipelineQueue($video_id)) {
        return true;
    }
    $processing = $db->where('video_id', $video_id)->where('processing', 1)->getValue(T_SEO_PIPELINE_QUEUE, 'COUNT(*)');
    if ($processing > 0) {
        return true;
    }
    $existing = PT_GetVideoSeoArticle($video_id);
    if ($rerun_from) {
        PT_SeoPipelineClearFromPass($video_id, $rerun_from);
        PT_UpsertVideoSeoArticle($video_id, array(
            'status' => PT_SeoStatusForRerun($rerun_from),
            'error_message' => '',
            'failed_pass' => null,
        ));
    } elseif (!empty($existing) && $existing->status === 'complete' && !$force) {
        // Never re-run (and re-bill) a finished article by accident.
        return false;
    } elseif (!empty($existing) && $existing->status === 'failed' && !$force && PT_SeoPipelineResumeStatusForFailedPass($existing->failed_pass)) {
        // Resume at the failed pass; earlier passes keep their stored output.
        PT_UpsertVideoSeoArticle($video_id, array(
            'status' => PT_SeoPipelineResumeStatusForFailedPass($existing->failed_pass),
            'error_message' => '',
            'failed_pass' => null,
            'failed_raw_response' => null,
        ));
    } elseif (!empty($existing) && !$force && !in_array($existing->status, array('failed', 'transcript_ready'), true)) {
        // Partially processed (e.g. stopped between passes): continue from where it is.
        $revert = PT_SeoPipelineRunningRevertMap();
        if (isset($revert[$existing->status])) {
            PT_UpsertVideoSeoArticle($video_id, array('status' => $revert[$existing->status]));
        }
    } else {
        $init = PT_InitializeSeoArticleFromTranscript($video_id);
        if (empty($init['ok'])) {
            return false;
        }
    }
    $inserted = $db->insert(T_SEO_PIPELINE_QUEUE, array(
        'video_id' => $video_id,
        'processing' => 0,
        'rerun_from' => $rerun_from,
        'priority' => 0,
        'created_at' => time(),
    ));
    if ($inserted) {
        // Two simultaneous clicks can both pass the checks above; the older row wins, the newer one removes itself.
        $oldest = $db->where('video_id', $video_id)->orderBy('id', 'ASC')->getOne(T_SEO_PIPELINE_QUEUE);
        if (!empty($oldest) && (int) PT_DbVal($oldest, 'id') !== (int) $inserted) {
            $db->where('id', (int) $inserted)->delete(T_SEO_PIPELINE_QUEUE);
        }
    }
    return $inserted;
}

function PT_LogSeoPipelineCall($data) {
    return PT_AiLogCall($data);
}

function PT_StripMarkdownJsonFence($text) {
    $text = trim($text);
    if (preg_match('/^```(?:json)?\s*([\s\S]*?)```\s*$/i', $text, $m)) {
        return trim($m[1]);
    }
    return $text;
}

function PT_ParseJsonFromAiResponse($text) {
    $text = PT_StripMarkdownJsonFence($text);
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return null;
}

function PT_SeoRepairJson($invalid_text, $pass_name, $video_id = 0) {
    $prompt = 'The following output was supposed to be valid JSON for pass "' . $pass_name . '" but failed to parse. Return ONLY valid JSON with the same structure and data, no markdown fences, no commentary. Do not add, remove or change any information.';
    $result = PT_AiChat(array(
        array('role' => 'system', 'content' => 'You fix malformed JSON. Output JSON only.'),
        array('role' => 'user', 'content' => $prompt . "\n\n" . $invalid_text),
    ), 'json_repair', array('json' => true, 'video_id' => $video_id, 'pass_name' => $pass_name . '_json_repair'));
    if (empty($result['ok'])) {
        return null;
    }
    return PT_ParseJsonFromAiResponse($result['content']);
}

function PT_SeoValidateJsonPass($raw_content, $pass_name, $video_id) {
    $parsed = PT_ParseJsonFromAiResponse($raw_content);
    if (is_array($parsed)) {
        return array('ok' => true, 'data' => $parsed, 'raw' => $raw_content);
    }
    $repaired = PT_SeoRepairJson($raw_content, $pass_name, $video_id);
    if (is_array($repaired)) {
        return array('ok' => true, 'data' => $repaired, 'raw' => $raw_content, 'repaired' => true);
    }
    return array('ok' => false, 'data' => null, 'raw' => $raw_content, 'error' => 'Invalid JSON after repair attempt');
}

function PT_SeoWordCount($text) {
    $text = trim((string) $text);
    return $text === '' ? 0 : count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY));
}

/**
 * Quality record for a cleaned transcript. $pass0 is the chunk report from PT_SeoPipelineRunPass0.
 */
function PT_ComputeTranscriptQuality($raw, $cleaned, $pass0 = array()) {
    $raw = trim($raw);
    $cleaned = trim($cleaned);
    $raw_words = PT_SeoWordCount($raw);
    $clean_words = PT_SeoWordCount($cleaned);
    $brand_corrections = 0;
    $brand = PT_LoadBrandStandards();
    if (!empty($brand['brand_names'])) {
        foreach ($brand['brand_names'] as $wrong => $right) {
            if (stripos($raw, $wrong) !== false && stripos($cleaned, $right) !== false) {
                $brand_corrections++;
            }
        }
    }
    $chunks = !empty($pass0['chunks']) ? (int) $pass0['chunks'] : 1;
    $fallbacks = !empty($pass0['fallback_chunks']) ? count($pass0['fallback_chunks']) : 0;
    $ratio = $raw_words > 0 ? round($clean_words / $raw_words, 3) : 0;
    $score = 100 - (int) round(60 * ($fallbacks / max(1, $chunks)));
    $pass0_cfg = PT_AiConfigSection('pass_0');
    if ($raw_words > 0 && (($ratio < (float) (isset($pass0_cfg['min_word_ratio']) ? $pass0_cfg['min_word_ratio'] : 0.6)) || $ratio > 1.3)) {
        $score -= 20;
    }
    return array(
        'score' => max(0, $score),
        'raw_words' => $raw_words,
        'cleaned_words' => $clean_words,
        'word_ratio' => $ratio,
        'issues_found' => abs($raw_words - $clean_words),
        'brand_corrections' => $brand_corrections,
        'entity_corrections' => 0,
        'speaker_corrections' => 0,
        'chunks' => $chunks,
        'fallback_chunks' => !empty($pass0['fallback_chunks']) ? $pass0['fallback_chunks'] : array(),
        'numeric_flags' => !empty($pass0['numeric_flags']) ? $pass0['numeric_flags'] : array(),
        'warnings' => !empty($pass0['warnings']) ? $pass0['warnings'] : array(),
    );
}

/**
 * Pass 0 over the COMPLETE transcript. Splits on sentence boundaries into token-sized chunks (no overlap, so
 * nothing is duplicated), cleans each chunk, and verifies every chunk before accepting it:
 *  - the response was not truncated (PT_AiChat fails on finish_reason=length),
 *  - word count stays within the configured ratio of the raw chunk (nothing silently dropped),
 *  - every number (dose, lab value, percentage, year) in the raw chunk is present, and none invented.
 * A chunk that fails is retried once with the specific problem named; if it still fails the RAW chunk is kept
 * (so no content is lost and no unverified edit lands) and the chunk is flagged in transcript_quality.
 */
function PT_SeoPipelineRunPass0($video_id, $article) {
    $raw = (string) $article->raw_transcript;
    $cfg = array_merge(array('chunk_tokens' => 5000, 'min_word_ratio' => 0.6, 'max_word_ratio' => 1.15), PT_AiConfigSection('pass_0'));
    $template = PT_LoadPromptTemplate('pass-0-clean-transcript');
    if ($template === '') {
        return array('ok' => false, 'error' => 'Prompt template not found: pass-0-clean-transcript');
    }
    $chunks = PT_AiSplitText($raw, (int) $cfg['chunk_tokens'], 0);
    if (empty($chunks)) {
        return array('ok' => false, 'error' => 'Raw transcript is empty');
    }
    if (!PT_AiChunksCoverText($raw, $chunks, (int) $cfg['chunk_tokens'])) {
        return array('ok' => false, 'error' => 'Internal error: Pass 0 split did not cover the whole transcript');
    }
    $n = count($chunks);
    $report = array('chunks' => $n, 'fallback_chunks' => array(), 'numeric_flags' => array(), 'warnings' => array());
    $parts = array();
    $vars = PT_SeoPipelineBuildVars($article);
    foreach ($chunks as $i => $chunk) {
        $vars['PASTE_TRANSCRIPT'] = $chunk['text'];
        $base_prompt = PT_ReplacePromptPlaceholders($template, $vars);
        $note = $n > 1 ? 'NOTE: This is part ' . ($i + 1) . ' of ' . $n . ' of one continuous transcript. Clean ONLY this part. Do not add an introduction, conclusion or summary, and do not repeat content from other parts.' . "\n\n" : '';
        $raw_words = PT_SeoWordCount($chunk['text']);
        $accepted = null;
        $problem = '';
        for ($try = 1; $try <= 2; $try++) {
            $extra = ($try === 2 && $problem !== '')
                ? "\n\nIMPORTANT: your previous attempt was rejected: " . $problem . ' Keep every sentence and every number, dose, lab value and percentage exactly as spoken. Only remove filler words and fix obvious spelling.'
                : '';
            $res = PT_AiChat(array(array('role' => 'user', 'content' => $note . $base_prompt . $extra)), 'pass_0', array(
                'video_id' => $video_id, 'pass_name' => 'pass_0_chunk_' . ($i + 1),
            ));
            if (empty($res['ok'])) {
                return array('ok' => false, 'error' => 'Pass 0 part ' . ($i + 1) . ' of ' . $n . ': ' . $res['error'], 'content' => $res['content'], 'raw' => $res['raw'], 'truncated' => !empty($res['truncated']));
            }
            $clean = $res['content'];
            $ratio = $raw_words > 0 ? PT_SeoWordCount($clean) / $raw_words : 1;
            $fid = PT_AiNumericFidelity($chunk['text'], $clean);
            $problem = '';
            if ($ratio < (float) $cfg['min_word_ratio']) {
                $problem = 'too much content was dropped (' . round($ratio * 100) . '% of the words kept).';
            } elseif ($ratio > (float) $cfg['max_word_ratio']) {
                $problem = 'content was added (' . round($ratio * 100) . '% of the original length).';
            } elseif (!$fid['ok']) {
                $problem = 'numbers changed (missing: ' . implode(', ', array_slice($fid['missing'], 0, 8)) . '; added: ' . implode(', ', array_slice($fid['added'], 0, 8)) . ').';
            }
            if ($problem === '') {
                $accepted = $clean;
                break;
            }
            $last_fid = $fid;
        }
        if ($accepted === null) {
            $accepted = $chunk['text']; // keep the verbatim chunk rather than an unverified edit
            $report['fallback_chunks'][] = $i + 1;
            $report['warnings'][] = 'Part ' . ($i + 1) . ' of ' . $n . ' kept as raw text: ' . $problem;
            if (!empty($last_fid) && !$last_fid['ok']) {
                $report['numeric_flags'][] = array('chunk' => $i + 1, 'missing' => array_slice($last_fid['missing'], 0, 20), 'added' => array_slice($last_fid['added'], 0, 20));
            }
        }
        $parts[] = $accepted;
    }
    return array('ok' => true, 'content' => implode("\n\n", $parts), 'report' => $report);
}

function PT_ExtractArticleMetadataFromMarkdown($markdown) {
    $meta = array(
        'seo_title' => '',
        'meta_description' => '',
        'primary_keyword' => '',
        'url_slug' => '',
        'body' => $markdown,
    );
    if (preg_match('/^SEO Title:\s*(.+)$/mi', $markdown, $m)) {
        $meta['seo_title'] = trim($m[1]);
    }
    if (preg_match('/^Meta Description:\s*(.+)$/mi', $markdown, $m)) {
        $meta['meta_description'] = trim($m[1]);
    }
    if (preg_match('/^Primary Keyword:\s*(.+)$/mi', $markdown, $m)) {
        $meta['primary_keyword'] = trim($m[1]);
    }
    if (preg_match('/^URL Slug:\s*(.+)$/mi', $markdown, $m)) {
        $meta['url_slug'] = trim($m[1]);
    }
    if (preg_match('/^---\s*\n([\s\S]*)$/', $markdown, $m)) {
        $meta['body'] = trim($m[1]);
    }
    return $meta;
}

function PT_EntityLibraryFindByNameOrAlias($library, $name) {
    $name_lower = strtolower(trim($name));
    if (empty($library['entities'])) {
        return null;
    }
    foreach ($library['entities'] as $slug => $entity) {
        if (strtolower($entity['name']) === $name_lower) {
            return $slug;
        }
        if (!empty($entity['aliases'])) {
            foreach ($entity['aliases'] as $alias) {
                if (strtolower(trim($alias)) === $name_lower) {
                    return $slug;
                }
            }
        }
    }
    return null;
}

function PT_SeoDeterministicAutoMerge($library, $comparison) {
    $summary = array(
        'entities_before' => !empty($library['entities']) ? count($library['entities']) : 0,
        'entities_after' => 0,
        'aliases_added' => 0,
        'new_entities_added' => 0,
        'merge_conflicts' => 0,
    );
    $merge_conflicts = array();
    if (empty($library['entities'])) {
        $library['entities'] = array();
    }
    if (!empty($comparison['auto_merge'])) {
        foreach ($comparison['auto_merge'] as $item) {
            $matched = !empty($item['matched_existing_name']) ? $item['matched_existing_name'] : '';
            $slug = PT_EntityLibraryFindByNameOrAlias($library, $matched);
            if ($slug && !empty($item['suggested_alias'])) {
                $alias = trim($item['suggested_alias']);
                if ($alias !== '' && !in_array($alias, $library['entities'][$slug]['aliases'], true)) {
                    $library['entities'][$slug]['aliases'][] = $alias;
                    $summary['aliases_added']++;
                }
            }
        }
    }
    if (!empty($comparison['suggested_aliases'])) {
        foreach ($comparison['suggested_aliases'] as $item) {
            $existing = !empty($item['existing_entity']) ? $item['existing_entity'] : '';
            $slug = PT_EntityLibraryFindByNameOrAlias($library, $existing);
            if ($slug && !empty($item['alias_to_add'])) {
                $alias = trim($item['alias_to_add']);
                if ($alias !== '' && !in_array($alias, $library['entities'][$slug]['aliases'], true)) {
                    $library['entities'][$slug]['aliases'][] = $alias;
                    $summary['aliases_added']++;
                }
            }
        }
    }
    if (!empty($comparison['add_new'])) {
        foreach ($comparison['add_new'] as $item) {
            $name = !empty($item['name']) ? trim($item['name']) : '';
            if ($name === '') {
                continue;
            }
            $existing_slug = PT_EntityLibraryFindByNameOrAlias($library, $name);
            if ($existing_slug) {
                $summary['merge_conflicts']++;
                $merge_conflicts[] = array(
                    'name' => $name,
                    'reason' => 'Entity already exists',
                    'existing_match' => $library['entities'][$existing_slug]['name'],
                );
                continue;
            }
            $slug = !empty($item['suggested_slug']) ? $item['suggested_slug'] : PT_SeoSlugify($name);
            $base_slug = $slug;
            $n = 2;
            while (isset($library['entities'][$slug])) {
                $slug = $base_slug . '-' . $n;
                $n++;
            }
            $library['entities'][$slug] = array(
                'name' => $name,
                'category' => !empty($item['category']) ? $item['category'] : 'concepts',
                'slug' => $slug,
                'aliases' => array(),
                'status' => 'active',
            );
            $summary['new_entities_added']++;
        }
    }
    $summary['entities_after'] = count($library['entities']);
    return array(
        'summary' => $summary,
        'updated_entity_library' => $library,
        'merge_conflicts' => $merge_conflicts,
    );
}

function PT_PersistEntityReviewQueueItems($video_id, $queue_output) {
    global $db;
    if (empty($queue_output['review_queue']) || !is_array($queue_output['review_queue'])) {
        return 0;
    }
    $count = 0;
    foreach ($queue_output['review_queue'] as $item) {
        $name = !empty($item['entity_name']) ? $item['entity_name'] : '';
        if ($name === '') {
            continue;
        }
        $review_id = 'rev_' . $video_id . '_' . PT_SeoSlugify($name) . '_' . substr(md5(json_encode($item)), 0, 8);
        $exists = $db->where('review_id', $review_id)->getValue(T_ENTITY_REVIEW_QUEUE, 'COUNT(*)');
        if ($exists > 0) {
            continue;
        }
        $db->insert(T_ENTITY_REVIEW_QUEUE, array(
            'review_id' => $review_id,
            'video_id' => (int) $video_id,
            'entity_name' => substr($name, 0, 255),
            'category' => !empty($item['category']) ? substr($item['category'], 0, 64) : '',
            'priority' => !empty($item['priority']) ? substr($item['priority'], 0, 32) : 'medium',
            'recommended_action' => !empty($item['recommended_action']) ? substr($item['recommended_action'], 0, 64) : '',
            'confidence' => isset($item['confidence']) ? (float) $item['confidence'] : null,
            'reason' => !empty($item['reason']) ? $item['reason'] : '',
            'possible_existing_match' => !empty($item['possible_existing_match']) ? substr($item['possible_existing_match'], 0, 255) : '',
            'suggested_slug' => !empty($item['suggested_slug']) ? substr($item['suggested_slug'], 0, 255) : '',
            'trigger_phrases' => !empty($item['trigger_phrases']) ? json_encode($item['trigger_phrases']) : '[]',
            'notes' => !empty($item['notes']) ? $item['notes'] : '',
            'status' => 'pending',
            'created_at' => time(),
        ));
        $count++;
    }
    return $count;
}

function PT_SeoPipelineBuildVars($article) {
    $standards = PT_LoadEditorialStandards();
    $library = PT_LoadEntityLibrary();
    $extraction_json = '';
    if (!empty($article->entity_extraction_output)) {
        $extraction_json = is_string($article->entity_extraction_output)
            ? $article->entity_extraction_output
            : json_encode($article->entity_extraction_output);
    }
    $comparison_json = '';
    if (!empty($article->entity_comparison_output)) {
        $comparison_json = is_string($article->entity_comparison_output)
            ? $article->entity_comparison_output
            : json_encode($article->entity_comparison_output);
    }
    return array(
        'EDITORIAL_STANDARDS_JSON' => json_encode($standards, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        'ENTITY_LIBRARY_JSON' => json_encode($library, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        'PASTE_TRANSCRIPT' => !empty($article->raw_transcript) ? $article->raw_transcript : '',
        'PASTE_CLEANED_TRANSCRIPT' => !empty($article->cleaned_transcript) ? $article->cleaned_transcript : '',
        'ENTITY_EXTRACTION_OUTPUT' => $extraction_json,
        'ENTITY_COMPARISON_RESULTS_JSON' => $comparison_json,
        'PASS_0_OUTPUT' => !empty($article->cleaned_transcript) ? $article->cleaned_transcript : '',
        'PASS_1_OUTPUT' => !empty($article->seo_blueprint) ? $article->seo_blueprint : '',
        'PASS_2_OUTPUT' => !empty($article->draft_article_markdown) ? $article->draft_article_markdown : '',
        'PASS_3_OUTPUT' => !empty($article->seo_audit) ? (is_string($article->seo_audit) ? $article->seo_audit : json_encode($article->seo_audit)) : '',
    );
}

function PT_SeoPipelineRunAiPass($video_id, $pass_name, $prompt_slug, $stage, $article, $json_output = false) {
    $template = PT_LoadPromptTemplate($prompt_slug);
    if ($template === '') {
        return array('ok' => false, 'error' => 'Prompt template not found: ' . $prompt_slug);
    }
    $prompt = PT_ReplacePromptPlaceholders($template, PT_SeoPipelineBuildVars($article));
    $result = PT_AiChat(array(array('role' => 'user', 'content' => $prompt)), $stage, array(
        'json' => $json_output, 'video_id' => $video_id, 'pass_name' => $pass_name,
    ));
    if (empty($result['ok'])) {
        return array('ok' => false, 'error' => $result['error'], 'content' => $result['content'], 'raw' => $result['raw'], 'truncated' => !empty($result['truncated']));
    }
    if ($json_output) {
        $validated = PT_SeoValidateJsonPass($result['content'], $pass_name, $video_id);
        if (empty($validated['ok'])) {
            return array('ok' => false, 'error' => $validated['error'], 'content' => $result['content'], 'raw' => $result['content']);
        }
        return array('ok' => true, 'data' => $validated['data'], 'content' => $result['content']);
    }
    return array('ok' => true, 'content' => $result['content']);
}

function PT_SeoPipelineMarkFailed($video_id, $pass_name, $error, $raw = null) {
    PT_UpsertVideoSeoArticle($video_id, array(
        'status' => 'failed',
        'failed_pass' => $pass_name,
        'error_message' => substr($error, 0, 2000),
        'failed_raw_response' => $raw,
    ));
}

function PT_SeoPipelineNextStatus($current) {
    $flow = array(
        'transcript_ready' => 'pass_0_running',
        'pass_0_complete' => 'entities_extracting',
        'entities_extracted' => 'entities_comparing',
        'entities_compared' => 'entity_library_updated',
        'entity_library_updated' => 'entity_review_queue_created',
        'entity_review_queue_created' => 'pass_1_running',
        'pass_1_complete' => 'pass_2_running',
        'pass_2_complete' => 'pass_3_running',
        'pass_3_complete' => 'pass_4_running',
    );
    return !empty($flow[$current]) ? $flow[$current] : null;
}

/** Status to fall back to when a step died mid-flight and left "*_running" behind. */
function PT_SeoPipelineRunningRevertMap() {
    return array(
        'pass_0_running' => 'transcript_ready',
        'entities_extracting' => 'pass_0_complete',
        'entities_comparing' => 'entities_extracted',
        'pass_1_running' => 'entity_review_queue_created',
        'pass_2_running' => 'pass_1_complete',
        'pass_3_running' => 'pass_2_complete',
        'pass_4_running' => 'pass_3_complete',
    );
}

/** Status at which a failed run resumes (the failed pass itself is re-run; earlier passes are NOT re-billed). */
function PT_SeoPipelineResumeStatusForFailedPass($failed_pass) {
    $map = array(
        'pass_0' => 'transcript_ready',
        'entity_extraction' => 'pass_0_complete',
        'entity_comparison' => 'entities_extracted',
        'entity_review_queue' => 'entity_library_updated',
        'pass_1' => 'entity_review_queue_created',
        'pass_2' => 'pass_1_complete',
        'pass_3' => 'pass_2_complete',
        'pass_4' => 'pass_3_complete',
    );
    return !empty($map[$failed_pass]) ? $map[$failed_pass] : null;
}

/**
 * Run the next step for a video. Only one worker may run a video at a time (named DB lock), so a cron job
 * and an admin "run step" click can never double-bill the same pass.
 */
function PT_SeoPipelineRunSingleStep($video_id, $options = array()) {
    $video_id = (int) $video_id;
    $lock = 'seo_pipeline_' . $video_id;
    if (!PT_AiAcquireLock($lock)) {
        return array('ok' => false, 'error' => 'Another worker is already processing this video', 'locked' => true);
    }
    try {
        return PT_SeoPipelineRunSingleStepLocked($video_id, $options);
    } finally {
        PT_AiReleaseLock($lock);
    }
}

function PT_SeoPipelineRunSingleStepLocked($video_id, $options = array()) {
    $video_id = (int) $video_id;
    $article = PT_GetVideoSeoArticle($video_id);
    if (!empty($article)) {
        // We hold the lock, so a "*_running" status can only be left over from a crashed worker.
        $revert = PT_SeoPipelineRunningRevertMap();
        $age = time() - (int) $article->updated_at;
        if (isset($revert[$article->status]) && (!empty($options['recover_stale']) || $age > 900)) {
            PT_UpsertVideoSeoArticle($video_id, array('status' => $revert[$article->status]));
            $article = PT_GetVideoSeoArticle($video_id);
        }
    }
    if (empty($article)) {
        $init = PT_InitializeSeoArticleFromTranscript($video_id);
        if (empty($init['ok'])) {
            return $init;
        }
        $article = PT_GetVideoSeoArticle($video_id);
    }
    $status = $article->status;
    if ($status === 'complete') {
        return array('ok' => true, 'done' => true, 'status' => $status);
    }
    if ($status === 'failed') {
        return array('ok' => false, 'error' => $article->error_message, 'status' => 'failed');
    }

    $standards_ver = PT_GetEditorialStandardsVersion();

    switch ($status) {
        case 'transcript_ready':
            PT_UpsertVideoSeoArticle($video_id, array('status' => 'pass_0_running'));
            $result = PT_SeoPipelineRunPass0($video_id, $article);
            if (empty($result['ok'])) {
                PT_SeoPipelineMarkFailed($video_id, 'pass_0', $result['error'], !empty($result['raw']) ? $result['raw'] : (!empty($result['content']) ? $result['content'] : null));
                return $result;
            }
            $cleaned = $result['content'];
            $quality = PT_ComputeTranscriptQuality($article->raw_transcript, $cleaned, $result['report']);
            PT_UpsertVideoSeoArticle($video_id, array(
                'cleaned_transcript' => $cleaned,
                'transcript_quality' => $quality,
                'pass_0_prompt_version' => PT_GetPromptVersion('pass-0-clean-transcript'),
                'editorial_standards_version' => $standards_ver,
                'status' => 'pass_0_complete',
            ));
            return array('ok' => true, 'status' => 'pass_0_complete');

        case 'pass_0_complete':
            PT_UpsertVideoSeoArticle($video_id, array('status' => 'entities_extracting'));
            $article = PT_GetVideoSeoArticle($video_id);
            $result = PT_SeoPipelineRunAiPass($video_id, 'entity_extraction', 'entity-extraction', 'entity_extraction', $article, true);
            if (empty($result['ok'])) {
                PT_SeoPipelineMarkFailed($video_id, 'entity_extraction', $result['error'], !empty($result['content']) ? $result['content'] : null);
                return $result;
            }
            PT_UpsertVideoSeoArticle($video_id, array(
                'entity_extraction_output' => $result['data'],
                'status' => 'entities_extracted',
            ));
            return array('ok' => true, 'status' => 'entities_extracted');

        case 'entities_extracted':
            PT_UpsertVideoSeoArticle($video_id, array('status' => 'entities_comparing'));
            $article = PT_GetVideoSeoArticle($video_id);
            $result = PT_SeoPipelineRunAiPass($video_id, 'entity_comparison', 'entity-comparison', 'entity_comparison', $article, true);
            if (empty($result['ok'])) {
                PT_SeoPipelineMarkFailed($video_id, 'entity_comparison', $result['error'], !empty($result['content']) ? $result['content'] : null);
                return $result;
            }
            PT_UpsertVideoSeoArticle($video_id, array(
                'entity_comparison_output' => $result['data'],
                'status' => 'entities_compared',
            ));
            return array('ok' => true, 'status' => 'entities_compared');

        case 'entities_compared':
            $article = PT_GetVideoSeoArticle($video_id);
            $comparison = is_string($article->entity_comparison_output)
                ? json_decode($article->entity_comparison_output, true)
                : (array) $article->entity_comparison_output;
            $library = PT_LoadEntityLibrary();
            $merge = PT_SeoDeterministicAutoMerge($library, $comparison);
            PT_SaveEntityLibrary($merge['updated_entity_library']);
            PT_UpsertVideoSeoArticle($video_id, array('status' => 'entity_library_updated'));
            return array('ok' => true, 'status' => 'entity_library_updated');

        case 'entity_library_updated':
            $article = PT_GetVideoSeoArticle($video_id);
            $result = PT_SeoPipelineRunAiPass($video_id, 'entity_review_queue', 'entity-review-queue', 'entity_review_queue', $article, true);
            if (empty($result['ok'])) {
                PT_SeoPipelineMarkFailed($video_id, 'entity_review_queue', $result['error'], !empty($result['content']) ? $result['content'] : null);
                return $result;
            }
            PT_UpsertVideoSeoArticle($video_id, array(
                'entity_review_queue_output' => $result['data'],
                'status' => 'entity_review_queue_created',
            ));
            PT_PersistEntityReviewQueueItems($video_id, $result['data']);
            return array('ok' => true, 'status' => 'entity_review_queue_created');

        case 'entity_review_queue_created':
            PT_UpsertVideoSeoArticle($video_id, array('status' => 'pass_1_running'));
            $article = PT_GetVideoSeoArticle($video_id);
            $result = PT_SeoPipelineRunAiPass($video_id, 'pass_1', 'pass-1-seo-blueprint', 'pass_1', $article, false);
            if (empty($result['ok'])) {
                PT_SeoPipelineMarkFailed($video_id, 'pass_1', $result['error'], !empty($result['content']) ? $result['content'] : null);
                return $result;
            }
            PT_UpsertVideoSeoArticle($video_id, array(
                'seo_blueprint' => $result['content'],
                'status' => 'pass_1_complete',
            ));
            return array('ok' => true, 'status' => 'pass_1_complete');

        case 'pass_1_complete':
            PT_UpsertVideoSeoArticle($video_id, array('status' => 'pass_2_running'));
            $article = PT_GetVideoSeoArticle($video_id);
            $result = PT_SeoPipelineRunAiPass($video_id, 'pass_2', 'pass-2-blog-article', 'pass_2', $article, false);
            if (empty($result['ok'])) {
                PT_SeoPipelineMarkFailed($video_id, 'pass_2', $result['error'], !empty($result['content']) ? $result['content'] : null);
                return $result;
            }
            $meta = PT_ExtractArticleMetadataFromMarkdown($result['content']);
            PT_UpsertVideoSeoArticle($video_id, array(
                'draft_article_markdown' => $result['content'],
                'seo_title' => $meta['seo_title'],
                'meta_description' => $meta['meta_description'],
                'primary_keyword' => $meta['primary_keyword'],
                'url_slug' => $meta['url_slug'],
                'status' => 'pass_2_complete',
            ));
            return array('ok' => true, 'status' => 'pass_2_complete');

        case 'pass_2_complete':
            PT_UpsertVideoSeoArticle($video_id, array('status' => 'pass_3_running'));
            $article = PT_GetVideoSeoArticle($video_id);
            $result = PT_SeoPipelineRunAiPass($video_id, 'pass_3', 'pass-3-seo-audit', 'pass_3', $article, true);
            if (empty($result['ok'])) {
                PT_SeoPipelineMarkFailed($video_id, 'pass_3', $result['error'], !empty($result['content']) ? $result['content'] : null);
                return $result;
            }
            PT_UpsertVideoSeoArticle($video_id, array(
                'seo_audit' => $result['data'],
                'status' => 'pass_3_complete',
            ));
            return array('ok' => true, 'status' => 'pass_3_complete');

        case 'pass_3_complete':
            PT_UpsertVideoSeoArticle($video_id, array('status' => 'pass_4_running'));
            $article = PT_GetVideoSeoArticle($video_id);
            $result = PT_SeoPipelineRunAiPass($video_id, 'pass_4', 'pass-4-final-revision', 'pass_4', $article, false);
            if (empty($result['ok'])) {
                PT_SeoPipelineMarkFailed($video_id, 'pass_4', $result['error'], !empty($result['content']) ? $result['content'] : null);
                return $result;
            }
            $meta = PT_ExtractArticleMetadataFromMarkdown($result['content']);
            PT_UpsertVideoSeoArticle($video_id, array(
                'final_article_markdown' => $result['content'],
                'seo_title' => $meta['seo_title'] ?: $article->seo_title,
                'meta_description' => $meta['meta_description'] ?: $article->meta_description,
                'primary_keyword' => $meta['primary_keyword'] ?: $article->primary_keyword,
                'url_slug' => $meta['url_slug'] ?: $article->url_slug,
                'status' => 'complete',
                'error_message' => '',
                'failed_pass' => null,
            ));
            return array('ok' => true, 'status' => 'complete', 'done' => true);

        default:
            if (strpos($status, '_running') !== false) {
                return array('ok' => false, 'error' => 'Pipeline step appears stuck in ' . $status);
            }
            $next = PT_SeoPipelineNextStatus($status);
            if ($next) {
                PT_UpsertVideoSeoArticle($video_id, array('status' => str_replace('_running', '', $next)));
                return PT_SeoPipelineRunSingleStepLocked($video_id, $options);
            }
            return array('ok' => false, 'error' => 'Unknown pipeline status: ' . $status);
    }
}

/** Put jobs back that were claimed but never finished (crashed worker). Needs migration 2032 (claimed_at). */
function PT_SeoResetStaleQueueJobs($max_age_seconds = 2700) {
    global $db;
    if (!PT_AiColumnExists(T_SEO_PIPELINE_QUEUE, 'claimed_at')) {
        return 0;
    }
    $cutoff = time() - (int) $max_age_seconds;
    $stale = $db->where('processing', 1)->where('claimed_at', $cutoff, '<')->get(T_SEO_PIPELINE_QUEUE);
    $n = 0;
    if (!empty($stale)) {
        foreach ($stale as $row) {
            $db->where('id', (int) PT_DbVal($row, 'id'))->update(T_SEO_PIPELINE_QUEUE, array('processing' => 0));
            $n++;
        }
    }
    return $n;
}

function PT_ProcessSeoPipelineQueueJob($queue_row) {
    global $db, $pt;
    $queue_id = (int) PT_DbVal($queue_row, 'id');
    if (!empty($pt->config->seo_pipeline_system) && $pt->config->seo_pipeline_system !== 'on') {
        $db->where('id', $queue_id)->delete(T_SEO_PIPELINE_QUEUE);
        return;
    }
    if (!PT_AiClaimQueueRow(T_SEO_PIPELINE_QUEUE, $queue_id)) {
        return; // another worker owns this job
    }
    @set_time_limit(0);
    $video_id = (int) PT_DbVal($queue_row, 'video_id');
    PT_AiBudgetBegin('seo_' . $video_id);
    $max_steps = 20;
    $steps = 0;
    $done = false;
    $options = array('recover_stale' => true); // we own the job, so any "*_running" status is leftover
    try {
        while ($steps < $max_steps && !$done) {
            $result = PT_SeoPipelineRunSingleStep($video_id, $options);
            $options = array();
            $steps++;
            if (empty($result['ok'])) {
                break;
            }
            if (!empty($result['done'])) {
                $done = true;
            }
            $article = PT_GetVideoSeoArticle($video_id);
            if (!empty($article) && in_array($article->status, array('complete', 'failed'), true)) {
                $done = true;
            }
        }
    } catch (Throwable $e) {
        PT_SeoPipelineMarkFailed($video_id, 'worker', $e->getMessage());
    }
    $db->where('id', $queue_id)->delete(T_SEO_PIPELINE_QUEUE);
}

function PT_GetSeoPipelineQueueCount() {
    global $db, $pt;
    $limit = !empty($pt->config->seo_pipeline_queue_count) ? (int) $pt->config->seo_pipeline_queue_count : 1;
    return max(1, min(3, $limit));
}

function PT_ListVideosForSeoPipeline($options = array()) {
    global $db;
    $limit = !empty($options['limit']) ? (int) $options['limit'] : 50;
    $offset = !empty($options['offset']) ? (int) $options['offset'] : 0;
    $channel_id = !empty($options['channel_id']) ? (int) $options['channel_id'] : 0;
    $where = "t.status = 'completed' AND t.plain_text IS NOT NULL AND t.plain_text <> ''";
    if ($channel_id > 0) {
        $where .= ' AND v.user_id = ' . $channel_id;
    }
    $sql = "SELECT v.id AS video_id, v.title, v.video_id AS public_id, t.status AS transcript_status,
            a.status AS seo_status, a.seo_title, a.updated_at AS seo_updated
            FROM " . T_VIDEO_TRANSCRIPTS . " t
            INNER JOIN " . T_VIDEOS . " v ON v.id = t.video_id
            LEFT JOIN " . T_VIDEO_SEO_ARTICLES . " a ON a.video_id = t.video_id
            WHERE {$where}
            ORDER BY v.time DESC
            LIMIT {$offset}, {$limit}";
    return $db->rawQuery($sql);
}

function PT_ApproveEntityReviewItem($review_id, $action, $admin_user_id, $extra = array()) {
    global $db;
    $row = $db->where('review_id', PT_Secure($review_id))->getOne(T_ENTITY_REVIEW_QUEUE);
    if (empty($row) || $row->status !== 'pending') {
        return array('ok' => false, 'error' => 'Review item not found or already processed');
    }
    $library = PT_LoadEntityLibrary();
    $name = $row->entity_name;
    $status_map = array(
        'approve_new' => 'approved_new',
        'merge_existing' => 'merged_existing',
        'create_alias' => 'alias_created',
        'ignore' => 'ignored',
        'needs_research' => 'needs_research',
    );
    if (empty($status_map[$action])) {
        return array('ok' => false, 'error' => 'Invalid action');
    }
    if ($action === 'approve_new') {
        $slug = !empty($extra['slug']) ? $extra['slug'] : (!empty($row->suggested_slug) ? $row->suggested_slug : PT_SeoSlugify($name));
        if (isset($library['entities'][$slug])) {
            $slug = $slug . '-' . time();
        }
        $library['entities'][$slug] = array(
            'name' => $name,
            'category' => $row->category ?: 'concepts',
            'slug' => $slug,
            'aliases' => array(),
            'status' => 'active',
        );
        PT_SaveEntityLibrary($library);
    } elseif ($action === 'merge_existing' || $action === 'create_alias') {
        $match = !empty($extra['existing_entity']) ? $extra['existing_entity'] : $row->possible_existing_match;
        $slug = PT_EntityLibraryFindByNameOrAlias($library, $match);
        if ($slug) {
            if ($action === 'create_alias' || $action === 'merge_existing') {
                if (!in_array($name, $library['entities'][$slug]['aliases'], true)) {
                    $library['entities'][$slug]['aliases'][] = $name;
                    PT_SaveEntityLibrary($library);
                }
            }
        }
    }
    $db->where('review_id', $row->review_id)->update(T_ENTITY_REVIEW_QUEUE, array(
        'status' => $status_map[$action],
        'reviewed_at' => time(),
        'reviewed_by' => (int) $admin_user_id,
    ));
    return array('ok' => true);
}

/**
 * Extension point: publish final Markdown to CMS (not implemented).
 */
function PT_SeoPublishArticleToCms($video_id) {
    $article = PT_GetVideoSeoArticle((int) $video_id);
    if (empty($article) || empty($article->final_article_markdown)) {
        return array('ok' => false, 'error' => 'No final article to publish');
    }
    return array('ok' => false, 'error' => 'CMS publishing not configured');
}

function PT_GetDisplayTranscriptForVideo($video_id) {
    $seo = PT_GetVideoSeoArticle($video_id);
    if (!empty($seo) && !empty($seo->cleaned_transcript)) {
        return $seo->cleaned_transcript;
    }
    $row = PT_GetVideoTranscript($video_id);
    $plain = PT_DbVal($row, 'plain_text'); // PT_GetVideoTranscript() returns an array
    return !empty($plain) ? $plain : '';
}
