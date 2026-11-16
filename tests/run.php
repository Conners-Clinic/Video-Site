<?php
/**
 * Offline test suite for the AI summary / SEO pipeline. No network, no DB, no API key.
 *   php tests/run.php            run everything
 *   php tests/run.php summary    run tests whose name contains "summary"
 */
require __DIR__ . '/bootstrap.php';

$GLOBALS['T_PASS'] = 0;
$GLOBALS['T_FAIL'] = array();
$GLOBALS['T_NAME'] = '';
$filter = isset($argv[1]) ? $argv[1] : '';

function ok($cond, $msg = '') {
    if ($cond) { $GLOBALS['T_PASS']++; return; }
    $GLOBALS['T_FAIL'][] = $GLOBALS['T_NAME'] . ': ' . $msg;
}
function eq($a, $b, $msg = '') {
    ok($a === $b, $msg . ' (expected ' . var_export($b, true) . ', got ' . var_export($a, true) . ')');
}
function has($hay, $needle, $msg = '') { ok(strpos((string) $hay, (string) $needle) !== false, $msg . ' [missing: ' . substr($needle, 0, 80) . ']'); }
function hasnt($hay, $needle, $msg = '') { ok(strpos((string) $hay, (string) $needle) === false, $msg . ' [unexpected: ' . substr($needle, 0, 80) . ']'); }
function test($name, $fn) {
    global $filter;
    if ($filter !== '' && stripos($name, $filter) === false) { return; }
    $GLOBALS['T_NAME'] = $name;
    reset_world();
    try { $fn(); } catch (Throwable $e) { $GLOBALS['T_FAIL'][] = $name . ': EXCEPTION ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(); }
}

/** Mock that behaves like the real summary prompts: digests list the markers they see; final lists markers from its input. */
function summary_mock($opts = array()) {
    mock_api(function ($p, $n) use ($opts) {
        $sys = $p['messages'][0]['content'];
        $user = user_text($p);
        preg_match_all('/POINT-(\d+)/', $user, $m);
        $nums = array_values(array_unique($m[1]));
        if (!empty($opts['fail_on']) && $n === $opts['fail_on']) { return api_ok('this is not json at all'); }
        if (!empty($opts['fail_from']) && $n >= $opts['fail_from']) { return api_ok('{"nope":1}'); }
        if (strpos($sys, 'ONE section') !== false) {
            $first = $nums ? 'POINT-' . $nums[0] : 'none';
            $last = $nums ? 'POINT-' . end($nums) : 'none';
            return api_ok(json_encode(array('section_summary' => "Section covers $first through $last.", 'key_points' => array("Starts at $first", "Ends at $last"), 'specifics' => array())), array('prompt' => 40000, 'completion' => 400));
        }
        $take = array();
        if ($nums) {
            $idx = array(0, (int) floor(count($nums) / 2), count($nums) - 1);
            foreach (array_unique($idx) as $i) { $take[] = 'Covers POINT-' . $nums[$i]; }
        }
        return api_ok(json_encode(array('summary' => 'A summary of the whole video.', 'key_takeaways' => $take)), array('prompt' => 15000, 'completion' => 300));
    });
}

// ============================================================ configuration
test('config: no model names hard-coded in PHP', function () {
    foreach (array('functions_ai.php', 'functions_ai_summary.php', 'functions_seo_pipeline.php', 'functions_transcribe.php') as $f) {
        $src = file_get_contents($GLOBALS['REPO'] . '/assets/includes/' . $f);
        ok(!preg_match('/gpt-\d|gpt-4o|gpt-6/i', $src), "$f contains a model name");
    }
    $src = file_get_contents($GLOBALS['REPO'] . '/ajax/ap.php') . file_get_contents($GLOBALS['REPO'] . '/admin-panel/pages/seo-articles/content.html') . file_get_contents($GLOBALS['REPO'] . '/admin-panel/pages/transcribe-videos/content.html');
    ok(!preg_match('/gpt-\d|gpt-4o/i', $src), 'admin/ajax contain a model name');
});

test('config: default stage models', function () {
    foreach (array('summary', 'summary_sections', 'pass_0', 'entity_extraction', 'entity_comparison', 'entity_review_queue', 'pass_1', 'pass_3', 'json_repair') as $s) {
        eq(PT_AiStageModel($s), 'gpt-6-luna', "stage $s");
    }
    eq(PT_AiStageModel('pass_2'), 'gpt-6.1-terra', 'pass_2');
    eq(PT_AiStageModel('pass_4'), 'gpt-6.1-terra', 'pass_4');
});

test('config: admin override wins per stage', function () {
    global $pt;
    $pt->config->seo_pipeline_model_pass_2 = 'gpt-6-luna';
    eq(PT_AiStageModel('pass_2'), 'gpt-6-luna');
    eq(PT_AiStageModel('pass_4'), 'gpt-6.1-terra');
});

test('config: every default model has pricing/capabilities entry', function () {
    foreach (PT_AiConfigSection('defaults') as $stage => $model) {
        if ($model === 'deterministic') { continue; }
        $models = PT_AiConfigSection('models');
        ok(isset($models[$model]), "no catalog entry for $model ($stage)");
        ok(!empty(PT_AiStageSettings($stage)), "no stage settings for $stage");
    }
});

// ============================================================ request building / compatibility
test('compat: gpt-6 payload uses max_completion_tokens + reasoning_effort, no temperature/max_tokens', function () {
    $p = PT_AiBuildPayload(array(array('role' => 'user', 'content' => 'x')), 'gpt-6-luna', 'pass_2', array('json' => true));
    ok(isset($p['max_completion_tokens']), 'max_completion_tokens');
    ok(!isset($p['max_tokens']), 'no max_tokens');
    ok(!isset($p['temperature']), 'no temperature');
    eq($p['reasoning_effort'], 'low');
    eq($p['response_format']['type'], 'json_object');
});

test('compat: extraction stages use no reasoning, drafting uses low', function () {
    eq(PT_AiBuildPayload(array(), 'gpt-6-luna', 'pass_0')['reasoning_effort'], 'none');
    eq(PT_AiBuildPayload(array(), 'gpt-6-luna', 'entity_review_queue')['reasoning_effort'], 'none');
    eq(PT_AiBuildPayload(array(), 'gpt-6.1-terra', 'pass_2')['reasoning_effort'], 'low');
});

test('compat: legacy gpt-4o payload keeps max_tokens + temperature, no reasoning_effort', function () {
    $p = PT_AiBuildPayload(array(), 'gpt-4o', 'pass_2');
    ok(isset($p['max_tokens']) && !isset($p['max_completion_tokens']));
    eq($p['temperature'], 0.6);
    ok(!isset($p['reasoning_effort']));
});

test('compat: rejected temperature/reasoning param is stripped once and the call succeeds', function () {
    mock_api(function ($p, $n) {
        if (isset($p['reasoning_effort'])) { return api_http(400, "Unsupported parameter: 'reasoning_effort'", 'unsupported_parameter', 'reasoning_effort'); }
        return api_ok('hello');
    });
    $r = PT_AiChat(array(array('role' => 'user', 'content' => 'hi')), 'summary', array('video_id' => 5));
    ok($r['ok']);
    eq($r['adjusted'], array('reasoning_effort'));
    eq(count($GLOBALS['CALLS']), 2);
});

test('compat: max_tokens <-> max_completion_tokens swap on rejection', function () {
    mock_api(function ($p, $n) {
        if (isset($p['max_completion_tokens'])) { return api_http(400, "Unsupported parameter: 'max_completion_tokens' is not supported with this model.", 'unsupported_parameter', 'max_completion_tokens'); }
        return api_ok('ok');
    });
    $r = PT_AiChat(array(array('role' => 'user', 'content' => 'hi')), 'summary');
    ok($r['ok'], $r['error']);
    ok(isset($GLOBALS['CALLS'][1]['max_tokens']));
});

// ============================================================ malformed / failing responses
test('errors: non-JSON 200 body fails visibly', function () {
    mock_api(function () { return array('status' => 200, 'body' => '<html>gateway</html>', 'error' => ''); });
    $r = PT_AiChat(array(array('role' => 'user', 'content' => 'x')), 'summary');
    ok(!$r['ok']); eq($r['error_code'], 'malformed');
});
test('errors: missing choices / empty content', function () {
    mock_api(function () { return array('status' => 200, 'body' => json_encode(array('usage' => array('prompt_tokens' => 5))), 'error' => ''); });
    $r = PT_AiChat(array(array('role' => 'user', 'content' => 'x')), 'summary');
    ok(!$r['ok']); eq($r['error_code'], 'empty');
});
test('errors: finish_reason=length is a failure, never a silent partial', function () {
    mock_api(function () { return api_ok('{"summary":"half a sent', array('finish' => 'length', 'completion' => 12000, 'reasoning' => 11900)); });
    $r = PT_AiChat(array(array('role' => 'user', 'content' => 'x')), 'summary');
    ok(!$r['ok']); ok($r['truncated']); eq($r['error_code'], 'truncated');
    has($r['error'], 'reasoning');
    ok($r['cost_usd'] > 0, 'truncated call is still costed');
});
test('errors: refusal and content_filter', function () {
    mock_api(function () { return array('status' => 200, 'body' => json_encode(array('choices' => array(array('finish_reason' => 'stop', 'message' => array('content' => null, 'refusal' => 'I cannot help with that'))), 'usage' => array())), 'error' => ''); });
    eq(PT_AiChat(array(array('role' => 'user', 'content' => 'x')), 'summary')['error_code'], 'refusal');
    mock_api(function () { return api_ok('x', array('finish' => 'content_filter')); });
    eq(PT_AiChat(array(array('role' => 'user', 'content' => 'x')), 'summary')['error_code'], 'content_filter');
});
test('errors: transient 500 retried then succeeds; permanent errors not retried', function () {
    mock_api(function ($p, $n) { return $n < 3 ? api_http(500, 'server error') : api_ok('fine'); });
    $r = PT_AiChat(array(array('role' => 'user', 'content' => 'x')), 'summary');
    ok($r['ok']); eq($r['attempts'], 3);
    mock_api(function () { return api_http(401, 'bad key', 'invalid_api_key'); });
    $r = PT_AiChat(array(array('role' => 'user', 'content' => 'x')), 'summary');
    ok(!$r['ok']); eq(count($GLOBALS['CALLS']), 1, 'auth error must not retry'); eq($r['error_code'], 'auth');
    mock_api(function () { return api_http(429, 'You exceeded your current quota', 'insufficient_quota'); });
    PT_AiChat(array(array('role' => 'user', 'content' => 'x')), 'summary');
    eq(count($GLOBALS['CALLS']), 1, 'quota error must not retry');
});
test('errors: persistent 503 stops after the retry limit', function () {
    mock_api(function () { return api_http(503, 'overloaded'); });
    $r = PT_AiChat(array(array('role' => 'user', 'content' => 'x')), 'summary');
    ok(!$r['ok']); eq(count($GLOBALS['CALLS']), 3); // 1 try + 2 retries
});
test('errors: missing API key makes no request', function () {
    global $pt; $pt->config->openai_api_key = '';
    mock_api(function () { return api_ok('x'); });
    $r = PT_AiChat(array(array('role' => 'user', 'content' => 'x')), 'summary');
    ok(!$r['ok']); eq(count($GLOBALS['CALLS']), 0);
});

// ============================================================ usage, cost, budgets
test('cost: Luna / Terra list prices, reasoning tokens counted in output, cache discount', function () {
    eq(PT_AiEstimateCost('gpt-6-luna', array('prompt_tokens' => 200000, 'completion_tokens' => 100000)), 0.07);
    eq(PT_AiEstimateCost('gpt-6.1-terra', array('prompt_tokens' => 200000, 'completion_tokens' => 100000)), 0.7);
    eq(PT_AiEstimateCost('gpt-6-luna', array('prompt_tokens' => 200000, 'cached_tokens' => 200000, 'completion_tokens' => 0)), 0.002);
    // long-context surcharge: >272K input is 2x input, 1.5x output
    eq(PT_AiEstimateCost('gpt-6-luna', array('prompt_tokens' => 300000, 'completion_tokens' => 10000)), round(300000 * 0.2 / 1e6 + 10000 * 0.75 / 1e6, 6));
});
test('usage: reasoning/cached tokens parsed and logged with cost', function () {
    global $db;
    $db->columns[T_SEO_PIPELINE_LOGS . '.cost_usd'] = true;
    mock_api(function () { return api_ok('ok', array('prompt' => 2000, 'completion' => 500, 'reasoning' => 300, 'cached' => 1000)); });
    $r = PT_AiChat(array(array('role' => 'user', 'content' => 'x')), 'summary', array('video_id' => 9, 'pass_name' => 'summary'));
    eq($r['usage']['reasoning_tokens'], 300); eq($r['usage']['cached_tokens'], 1000);
    $log = array_values($db->tables[T_SEO_PIPELINE_LOGS])[0];
    eq($log['reasoning_tokens'], 300); ok($log['cost_usd'] > 0); eq($log['video_id'], 9);
    $rep = PT_AiJobUsageReport(9);
    eq($rep['calls'], 1); eq($rep['input_tokens'], 2000);
});
test('usage: logging works before the migration (no extra columns)', function () {
    global $db;
    mock_api(function () { return api_ok('ok'); });
    PT_AiChat(array(array('role' => 'user', 'content' => 'x')), 'summary', array('video_id' => 9));
    $log = array_values($db->tables[T_SEO_PIPELINE_LOGS])[0];
    ok(!array_key_exists('cost_usd', $log)); eq($log['status'], 'success');
});
test('budget: call ceiling stops runaway jobs', function () {
    mock_api(function () { return api_ok('ok'); });
    PT_AiBudgetBegin('t', array('max_calls' => 2, 'max_cost' => 100));
    ok(PT_AiChat(array(array('role' => 'user', 'content' => 'x')), 'summary')['ok']);
    ok(PT_AiChat(array(array('role' => 'user', 'content' => 'x')), 'summary')['ok']);
    $r = PT_AiChat(array(array('role' => 'user', 'content' => 'x')), 'summary');
    ok(!$r['ok']); eq($r['error_code'], 'budget'); eq(count($GLOBALS['CALLS']), 2);
});
test('budget: cost ceiling stops further calls', function () {
    mock_api(function () { return api_ok('ok', array('prompt' => 900000, 'completion' => 100000)); }); // ~ $0.14 each on Luna
    PT_AiBudgetBegin('t', array('max_calls' => 50, 'max_cost' => 0.2));
    PT_AiChat(array(array('role' => 'user', 'content' => 'x')), 'summary');
    PT_AiChat(array(array('role' => 'user', 'content' => 'x')), 'summary');
    $r = PT_AiChat(array(array('role' => 'user', 'content' => 'x')), 'summary');
    eq($r['error_code'], 'budget'); has($r['error'], 'cost limit');
});

// ============================================================ chunking
test('chunker: short text is one chunk', function () {
    $c = PT_AiSplitText('Hello there. This is short.', 40000, 1000);
    eq(count($c), 1);
});
test('chunker: super long text is covered, bounded, overlapping, in order', function () {
    $text = make_transcript(120000);
    $c = PT_AiSplitText($text, 5000, 300);
    ok(count($c) > 5);
    ok(PT_AiChunksCoverText($text, $c, 5000), 'every sentence in some chunk');
    $prev_last = -1;
    foreach ($c as $i => $ch) {
        ok($ch['tokens'] <= 5000 + 1, "chunk $i within target");
        ok($ch['first_unit'] > ($i > 0 ? $c[$i - 1]['first_unit'] : -1), 'advances');
        if ($i > 0) { ok($ch['first_unit'] <= $prev_last + 1, 'no gap'); ok($ch['overlap_units'] >= 1, 'has overlap'); }
        $prev_last = $ch['last_unit'];
    }
    has($c[count($c) - 1]['text'], 'POINT-' . (count(PT_AiTextUnits($text, 5000)) - 1), 'last sentence in last chunk');
});
test('chunker: zero overlap means no repeated sentences (Pass 0)', function () {
    $text = make_transcript(30000);
    $c = PT_AiSplitText($text, 3000, 0);
    $joined = implode(' ', array_map(function ($x) { return $x['text']; }, $c));
    eq($joined, trim($text), 'rejoined chunks equal the original text');
});
test('chunker: unpunctuated whisper text is split on word boundaries without loss', function () {
    $words = array();
    for ($i = 0; $i < 20000; $i++) { $words[] = 'w' . $i; }
    $text = implode(' ', $words);
    $c = PT_AiSplitText($text, 2000, 0);
    ok(count($c) > 3);
    eq(implode(' ', array_map(function ($x) { return $x['text']; }, $c)), $text);
});

// ============================================================ summaries
test('summary: short transcript = one call with the whole text', function () {
    summary_mock();
    $t = make_transcript(300);
    $r = PT_AiSummarizeTranscript(sample_video(), $t);
    ok($r['ok'], $r['error']); eq($r['mode'], 'single'); eq(count($GLOBALS['CALLS']), 1);
    has(user_text($GLOBALS['CALLS'][0]), $t, 'full transcript sent');
    ok(count($r['key_takeaways']) >= 1);
});
test('summary: one-hour transcript is NOT cut at 6000 chars and is one call', function () {
    summary_mock();
    $t = make_transcript(9500); // ~1 hour of speech
    ok(strlen($t) > 50000);
    $r = PT_AiSummarizeTranscript(sample_video(), $t);
    ok($r['ok'], $r['error']); eq($r['mode'], 'single'); eq(count($GLOBALS['CALLS']), 1);
    $sent = user_text($GLOBALS['CALLS'][0]);
    ok(strlen($sent) > strlen($t), 'payload carries entire transcript');
    has($sent, 'POINT-0'); has($sent, 'POINT-' . (count(explode('Number ', $t)) - 2), 'last line of the hour reached the model');
    eq($GLOBALS['CALLS'][0]['model'], 'gpt-6-luna');
});
test('summary: important information near the END is sent and surfaces in takeaways', function () {
    summary_mock();
    $t = make_transcript(9000) . ' In the final minutes the doctor warns that POINT-9999 stopping curcumin before surgery matters.';
    $r = PT_AiSummarizeTranscript(sample_video(), $t);
    ok($r['ok']);
    has(user_text($GLOBALS['CALLS'][0]), 'POINT-9999', 'end-of-video fact reached the model');
    has(implode(' ', $r['key_takeaways']), 'POINT-9999', 'takeaways include the end of the video');
});
test('summary: exceptionally long transcript uses sections, covers start/middle/end, few calls', function () {
    summary_mock();
    $t = make_transcript(260000); // ~ 4 000 000 chars? no: about 1.6M chars, ~490k tokens
    $tok = PT_AiEstimateTokens($t);
    ok($tok > 150000 && $tok < 600000, "token estimate $tok");
    $r = PT_AiSummarizeTranscript(sample_video(), $t);
    ok($r['ok'], $r['error']);
    eq($r['mode'], 'multi');
    $sections = $r['sections'];
    ok($sections >= 10, "sections=$sections");
    eq(count($GLOBALS['CALLS']), $sections + 1, 'one call per section + one final, nothing extra');
    $final = user_text($GLOBALS['CALLS'][count($GLOBALS['CALLS']) - 1]);
    $last_marker = count(PT_AiTextUnits($t, 40000)) - 1;
    has($final, '[Section 1 of ' . $sections . ']'); has($final, '[Section ' . $sections . ' of ' . $sections . ']');
    has($final, 'POINT-0'); has($final, 'POINT-' . $last_marker, 'last section digest reaches the final call');
    preg_match_all('/POINT-(\d+)/', implode(' ', $r['key_takeaways']), $m);
    ok(count($m[1]) >= 3 && (int) max($m[1]) > $last_marker * 0.9 && (int) min($m[1]) < $last_marker * 0.1, 'takeaways span the whole video');
    ok($r['usage']['calls'] === count($GLOBALS['CALLS']));
});
test('summary: every section of the long transcript is actually sent', function () {
    summary_mock();
    $t = make_transcript(260000);
    PT_AiSummarizeTranscript(sample_video(), $t);
    $seen = array();
    foreach ($GLOBALS['CALLS'] as $c) {
        if (strpos($c['messages'][0]['content'], 'ONE section') === false) { continue; }
        preg_match_all('/POINT-(\d+)/', user_text($c), $m);
        foreach ($m[1] as $n) { $seen[(int) $n] = true; }
    }
    $total = count(PT_AiTextUnits($t, 40000));
    $missing = 0;
    for ($i = 0; $i < $total; $i++) { if (empty($seen[$i])) { $missing++; } }
    eq($missing, 0, 'sentences never sent to the model');
});
test('summary: hierarchical merge kicks in when digests are too large', function () {
    summary_mock();
    $GLOBALS['PT_AI_CONFIG_OVERRIDE'] = array('summary' => array('single_pass_max_input_tokens' => 2000, 'chunk_tokens' => 1500, 'chunk_overlap_tokens' => 100, 'reduce_max_input_tokens' => 200));
    $r = PT_AiSummarizeTranscript(sample_video(), make_transcript(6000));
    ok($r['ok'], $r['error']);
    $merges = 0;
    foreach ($GLOBALS['CALLS'] as $c) { if (strpos(user_text($c), 'Merged group') !== false) { $merges++; } }
    ok($merges >= 1, 'a merge level ran');
});
test('summary: a failing section fails the whole run visibly (no partial summary)', function () {
    summary_mock(array('fail_from' => 3));
    $r = PT_AiSummarizeTranscript(sample_video(), make_transcript(260000));
    ok(!$r['ok']); eq($r['summary'], ''); has($r['error'], 'Section');
    ok(count($GLOBALS['CALLS']) < 12, 'stops early instead of burning calls');
});
test('summary: malformed final JSON / empty summary are failures', function () {
    mock_api(function () { return api_ok('Sure! Here is your summary: not json'); });
    $r = PT_AiSummarizeTranscript(sample_video(), make_transcript(200));
    ok(!$r['ok']); has($r['error'], 'JSON');
    mock_api(function () { return api_ok('{"summary":"","key_takeaways":["a"]}'); });
    ok(!PT_AiSummarizeTranscript(sample_video(), make_transcript(200))['ok']);
});
test('summary: json inside a markdown fence is accepted', function () {
    mock_api(function () { return api_ok("```json\n{\"summary\":\"Fenced ok.\",\"key_takeaways\":[\"One\",\"Two\"]}\n```"); });
    $r = PT_AiSummarizeTranscript(sample_video(), make_transcript(200));
    ok($r['ok']); eq($r['key_takeaways'], array('One', 'Two'));
});
test('summary: truncated summary output is reported, not saved', function () {
    mock_api(function () { return api_ok('{"summary":"Cut o', array('finish' => 'length')); });
    $r = PT_AiSummarizeTranscript(sample_video(), make_transcript(200));
    ok(!$r['ok']); has($r['error'], 'cut off');
});
test('summary: absurdly long transcripts stop at the safety cap', function () {
    $GLOBALS['PT_AI_CONFIG_OVERRIDE'] = array('limits' => array('summary_max_transcript_tokens' => 1000));
    mock_api(function () { return api_ok('{}'); });
    $r = PT_AiSummarizeTranscript(sample_video(), make_transcript(5000));
    ok(!$r['ok']); eq(count($GLOBALS['CALLS']), 0); has($r['error'], 'too long');
});
test('summary: custom admin prompt is still honoured', function () {
    global $pt;
    $pt->config->openai_summary_prompt = 'CUSTOM-HOUSE-STYLE key_takeaways summary JSON.';
    summary_mock();
    PT_AiSummarizeTranscript(sample_video(), make_transcript(200));
    has($GLOBALS['CALLS'][0]['messages'][0]['content'], 'CUSTOM-HOUSE-STYLE');
});

// ============================================================ numeric fidelity + medical terminology
test('fidelity: doses, labs and percentages must survive cleanup unchanged', function () {
    $raw = 'She takes 5000 IU of vitamin D3 and 1,000 mg of curcumin; CA 19-9 was 38 U/mL, down 12.5 percent in 2024.';
    ok(PT_AiNumericFidelity($raw, 'She takes 5000 IU of vitamin D3 and 1,000 mg of curcumin; her CA 19-9 was 38 U/mL, down 12.5 percent in 2024.')['ok']);
    $bad = PT_AiNumericFidelity($raw, 'She takes 500 IU of vitamin D3 and 1,000 mg of curcumin; CA 19-9 was 38 U/mL, down 12.5 percent in 2024.');
    ok(!$bad['ok']); ok(in_array('5000', $bad['missing'], true)); ok(in_array('500', $bad['added'], true));
    $dropped = PT_AiNumericFidelity($raw, 'She takes vitamin D3 and curcumin.');
    ok(!$dropped['ok']);
});

function pass0_article($raw) {
    return (object) array('raw_transcript' => $raw, 'cleaned_transcript' => '', 'entity_extraction_output' => '', 'entity_comparison_output' => '', 'seo_blueprint' => '', 'draft_article_markdown' => '', 'seo_audit' => '');
}
function pass0_text($p) { $u = user_text($p); $i = strrpos($u, "RAW TRANSCRIPT:\n"); $i = $i === false ? strrpos($u, "RAW TRANSCRIPT:\r\n") : $i; return trim(substr($u, $i + 16)); }

test('pass0: one-hour transcript cleaned in token-sized chunks, nothing dropped or duplicated', function () {
    mock_api(function ($p) { return api_ok(str_replace(', um,', '', pass0_text($p)), array('prompt' => 9000, 'completion' => 6000)); });
    $raw = str_replace('we always', 'we, um, always', make_transcript(9500));
    $r = PT_SeoPipelineRunPass0(1, pass0_article($raw));
    ok($r['ok'], isset($r['error']) ? $r['error'] : '');
    ok($r['report']['chunks'] >= 2);
    eq(count($GLOBALS['CALLS']), $r['report']['chunks'], 'one call per chunk (no retries needed)');
    eq(PT_AiSplitText($raw, 5000, 0) !== array(), true);
    for ($i = 0; $i < 400; $i += 37) { has($r['content'], "POINT-$i.", "sentence $i kept"); }
    eq(substr_count($r['content'], 'POINT-100.'), 1, 'no duplicates');
    hasnt($r['content'], ', um,');
    eq($r['report']['fallback_chunks'], array());
    eq($GLOBALS['CALLS'][0]['reasoning_effort'], 'none');
});
test('pass0: truncated cleanup fails the pass visibly (old code silently saved the cut-off text)', function () {
    mock_api(function ($p) { return api_ok(substr(pass0_text($p), 0, 500), array('finish' => 'length')); });
    $r = PT_SeoPipelineRunPass0(1, pass0_article(make_transcript(2000)));
    ok(!$r['ok']); ok(!empty($r['truncated'])); has($r['error'], 'Pass 0 part 1');
});
test('pass0: model that changes a dose is retried, then the raw chunk is kept and flagged', function () {
    mock_api(function ($p) { return api_ok(str_replace('5000 IU', '500 IU', pass0_text($p))); });
    $raw = 'Dr. Conners recommends 5000 IU of vitamin D3 daily for patients with low levels. ' . make_transcript(100);
    $r = PT_SeoPipelineRunPass0(1, pass0_article($raw));
    ok($r['ok']);
    has($r['content'], '5000 IU', 'original dose preserved');
    hasnt($r['content'], ' 500 IU');
    eq($r['report']['fallback_chunks'], array(1));
    ok(!empty($r['report']['numeric_flags']));
    eq(count($GLOBALS['CALLS']), 2, 'exactly one retry');
    has(user_text($GLOBALS['CALLS'][1]), 'previous attempt was rejected');
});
test('pass0: model that drops half the text is rejected', function () {
    mock_api(function ($p) { $t = pass0_text($p); return api_ok(substr($t, 0, (int) (strlen($t) * 0.3))); });
    $r = PT_SeoPipelineRunPass0(1, pass0_article(make_transcript(800)));
    ok($r['ok']); ok(!empty($r['report']['fallback_chunks']));
    has($r['content'], 'POINT-0.'); 
    $q = PT_ComputeTranscriptQuality(make_transcript(800), $r['content'], $r['report']);
    ok($q['score'] < 100); ok(!empty($q['warnings']));
});
test('pass0: medical terminology survives (mock cleans fillers only)', function () {
    mock_api(function ($p) { return api_ok(str_replace(' um', '', pass0_text($p))); });
    $raw = 'Um the Rife machine and IV vitamin C 50 grams are used um with 5-FU and gemcitabine for BRCA1 positive patients; CA 19-9 of 38 U/mL and low dose naltrexone 4.5 mg nightly.';
    $r = PT_SeoPipelineRunPass0(1, pass0_article($raw));
    ok($r['ok']);
    foreach (array('Rife machine', 'IV vitamin C 50 grams', '5-FU', 'gemcitabine', 'BRCA1', 'CA 19-9 of 38 U/mL', 'naltrexone 4.5 mg') as $term) { has($r['content'], $term); }
    eq(file_get_contents(__DIR__ . '/fixtures/medical_terms.txt') !== false, true);
});
test('pass0: raw transcript is never modified by the pipeline', function () {
    global $db;
    mock_api(function ($p) { return api_ok(pass0_text($p)); });
    $raw = make_transcript(300);
    $db->insert(T_VIDEO_SEO_ARTICLES, array('video_id' => 1, 'raw_transcript' => $raw, 'status' => 'transcript_ready', 'updated_at' => time()));
    PT_SeoPipelineRunSingleStep(1);
    $a = PT_GetVideoSeoArticle(1);
    eq($a->raw_transcript, $raw); eq($a->status, 'pass_0_complete'); ok($a->cleaned_transcript !== '');
});

// ============================================================ reliability: duplicates, locks, failures
function seed_transcript($video_id, $text = null, $extra = array()) {
    global $db;
    $db->insert(T_VIDEOS, array('id' => $video_id, 'video_id' => 'v' . $video_id, 'title' => 'T', 'tags' => '', 'description' => 'ORIGINAL DESCRIPTION'));
    $db->insert(T_VIDEO_TRANSCRIPTS, array_merge(array('video_id' => $video_id, 'status' => 'completed', 'plain_text' => $text === null ? make_transcript(400) : $text, 'seo_summary' => '', 'key_takeaways' => null), $extra));
}
test('dup: completed article is not re-queued (no repeat charges)', function () {
    global $db;
    seed_transcript(1);
    $db->insert(T_VIDEO_SEO_ARTICLES, array('video_id' => 1, 'status' => 'complete', 'raw_transcript' => 'x', 'updated_at' => time()));
    ok(PT_EnqueueSeoPipeline(1) === false);
    eq(count(isset($db->tables[T_SEO_PIPELINE_QUEUE]) ? $db->tables[T_SEO_PIPELINE_QUEUE] : array()), 0);
    eq(PT_GetVideoSeoArticle(1)->status, 'complete', 'status untouched');
});
test('dup: double enqueue produces one queue row', function () {
    global $db;
    seed_transcript(1);
    PT_EnqueueSeoPipeline(1); PT_EnqueueSeoPipeline(1);
    eq(count($db->tables[T_SEO_PIPELINE_QUEUE]), 1);
});
test('dup: two workers cannot claim the same queue row', function () {
    global $db;
    $id = $db->insert(T_SEO_PIPELINE_QUEUE, array('video_id' => 1, 'processing' => 0, 'created_at' => time()));
    ok(PT_AiClaimQueueRow(T_SEO_PIPELINE_QUEUE, $id), 'first claim');
    ok(!PT_AiClaimQueueRow(T_SEO_PIPELINE_QUEUE, $id), 'second claim must fail');
});
test('dup: a second worker is refused while a video is locked (cron vs admin click)', function () {
    global $db;
    seed_transcript(1);
    $db->insert(T_VIDEO_SEO_ARTICLES, array('video_id' => 1, 'status' => 'transcript_ready', 'raw_transcript' => make_transcript(100), 'updated_at' => time()));
    $db->held_locks['pt_seo_pipeline_1'] = true;
    mock_api(function () { return api_ok('x'); });
    $r = PT_SeoPipelineRunSingleStep(1);
    ok(!$r['ok'] && !empty($r['locked'])); eq(count($GLOBALS['CALLS']), 0, 'no API call while locked');
});
test('dup: lock is released after a step', function () {
    global $db;
    seed_transcript(1);
    $db->insert(T_VIDEO_SEO_ARTICLES, array('video_id' => 1, 'status' => 'complete', 'updated_at' => time()));
    PT_SeoPipelineRunSingleStep(1);
    eq($db->own_locks, array());
});
test('failed: failed pass is resumed at that pass, earlier outputs are kept (no re-billing)', function () {
    global $db;
    seed_transcript(1);
    $db->insert(T_VIDEO_SEO_ARTICLES, array('video_id' => 1, 'status' => 'failed', 'failed_pass' => 'pass_2', 'error_message' => 'boom', 'raw_transcript' => 'raw', 'cleaned_transcript' => 'CLEAN', 'seo_blueprint' => 'BLUEPRINT', 'updated_at' => time()));
    ok(PT_EnqueueSeoPipeline(1));
    $a = PT_GetVideoSeoArticle(1);
    eq($a->status, 'pass_1_complete'); eq($a->cleaned_transcript, 'CLEAN'); eq($a->seo_blueprint, 'BLUEPRINT'); eq($a->error_message, '');
});
test('failed: failed step records pass + message; nothing advances', function () {
    global $db;
    mock_api(function () { return api_http(400, 'invalid request'); });
    seed_transcript(1);
    $db->insert(T_VIDEO_SEO_ARTICLES, array('video_id' => 1, 'status' => 'transcript_ready', 'raw_transcript' => make_transcript(100), 'updated_at' => time()));
    $r = PT_SeoPipelineRunSingleStep(1);
    ok(!$r['ok']);
    $a = PT_GetVideoSeoArticle(1);
    eq($a->status, 'failed'); eq($a->failed_pass, 'pass_0'); has($a->error_message, 'invalid request');
});
test('failed: crashed worker leaves *_running; job recovers instead of sticking forever', function () {
    global $db;
    seed_transcript(1);
    mock_api(function ($p) { return api_ok(pass0_text($p)); });
    $db->insert(T_VIDEO_SEO_ARTICLES, array('video_id' => 1, 'status' => 'pass_0_running', 'raw_transcript' => make_transcript(100), 'updated_at' => time()));
    $r = PT_SeoPipelineRunSingleStep(1, array('recover_stale' => true));
    ok($r['ok'], isset($r['error']) ? $r['error'] : ''); eq(PT_GetVideoSeoArticle(1)->status, 'pass_0_complete');
});
test('failed: stale claimed jobs are put back (needs migration 2032)', function () {
    global $db;
    $db->columns[T_SEO_PIPELINE_QUEUE . '.claimed_at'] = true;
    $db->insert(T_SEO_PIPELINE_QUEUE, array('video_id' => 1, 'processing' => 1, 'claimed_at' => time() - 7200, 'created_at' => 1));
    $db->insert(T_SEO_PIPELINE_QUEUE, array('video_id' => 2, 'processing' => 1, 'claimed_at' => time() - 60, 'created_at' => 1));
    eq(PT_SeoResetStaleQueueJobs(2700), 1);
    eq($db->tables[T_SEO_PIPELINE_QUEUE][1]['processing'], 0); eq($db->tables[T_SEO_PIPELINE_QUEUE][2]['processing'], 1);
});
test('failed: per-job cost ceiling fails the pass with a clear message', function () {
    global $db;
    seed_transcript(1);
    mock_api(function ($p) { return api_ok(pass0_text($p)); });
    $db->insert(T_VIDEO_SEO_ARTICLES, array('video_id' => 1, 'status' => 'transcript_ready', 'raw_transcript' => make_transcript(9000), 'updated_at' => time()));
    PT_AiBudgetBegin('t', array('max_calls' => 2, 'max_cost' => 100));
    $r = PT_SeoPipelineRunSingleStep(1);
    ok(!$r['ok']); has(PT_GetVideoSeoArticle(1)->error_message, 'call limit'); eq(PT_GetVideoSeoArticle(1)->failed_pass, 'pass_0');
});

// full run with mocked API
function pipeline_mock() {
    mock_api(function ($p, $n) {
        $u = user_text($p);
        $model = $p['model'];
        if (strpos($u, 'RAW TRANSCRIPT:') !== false) { return api_ok(pass0_text($p)); }
        if (strpos($u, 'entity extraction and normalization') !== false) { return api_ok('{"entities":[]}'); }
        if (strpos($u, 'entity library comparison') !== false) { return api_ok('{"auto_merge":[],"add_new":[],"needs_review":[],"suggested_aliases":[]}'); }
        if (strpos($u, 'entity review queue manager') !== false) { return api_ok('{"review_queue":[]}'); }
        if (strpos($u, 'SEO strategist') !== false) { return api_ok('# Blueprint'); }
        if (strpos($u, 'elite SEO content writer') !== false) { return api_ok("SEO Title: Draft\nMeta Description: d\n\n# Draft", array('prompt' => 20000, 'completion' => 6000)); }
        if (strpos($u, 'content auditor') !== false) { return api_ok('{"score":90}'); }
        return api_ok("SEO Title: Final\n\n# Final article", array('prompt' => 25000, 'completion' => 7000));
    });
}
test('pipeline: full mocked run completes, uses configured models, reports usage; re-run is blocked', function () {
    global $db;
    pipeline_mock();
    seed_transcript(1);
    ok(PT_EnqueueSeoPipeline(1));
    $row = $db->tables[T_SEO_PIPELINE_QUEUE][1];
    PT_ProcessSeoPipelineQueueJob((object) $row);
    $a = PT_GetVideoSeoArticle(1);
    eq($a->status, 'complete', 'status (error: ' . $a->error_message . ')');
    ok(strpos($a->final_article_markdown, 'Final article') !== false, 'final article stored');
    $models = array();
    foreach ($GLOBALS['CALLS'] as $c) { $models[$c['model']] = true; }
    eq(array_keys($models), array('gpt-6-luna', 'gpt-6.1-terra'), 'only configured models used');
    $drafting = 0;
    foreach ($GLOBALS['CALLS'] as $c) { if ($c['model'] === 'gpt-6.1-terra') { $drafting++; } }
    eq($drafting, 2, 'Terra only for pass 2 and pass 4');
    $rep = PT_AiJobUsageReport(1);
    ok($rep['calls'] === 8 && $rep['failed_calls'] === 0, 'usage report: calls=' . $rep['calls'] . ' failed=' . $rep['failed_calls']);
    eq(count($db->tables[T_SEO_PIPELINE_QUEUE]), 0, 'queue row removed');
    $calls_before = count($GLOBALS['CALLS']);
    ok(PT_EnqueueSeoPipeline(1) === false, 'completed article cannot be re-queued by accident');
    eq(count($GLOBALS['CALLS']), $calls_before);
});
test('pipeline: JSON pass that comes back malformed is repaired once; truncated JSON is NOT "repaired"', function () {
    global $db;
    $n = 0;
    mock_api(function ($p) use (&$n) {
        $u = user_text($p);
        if (strpos($u, 'fix') !== false || strpos($p['messages'][0]['content'], 'fix malformed JSON') !== false) { return api_ok('{"entities":["fixed"]}'); }
        return api_ok('{"entities": [oops');
    });
    $a = (object) array('raw_transcript' => 'r', 'cleaned_transcript' => 'c', 'entity_extraction_output' => '', 'entity_comparison_output' => '', 'seo_blueprint' => '', 'draft_article_markdown' => '', 'seo_audit' => '');
    $r = PT_SeoPipelineRunAiPass(1, 'entity_extraction', 'entity-extraction', 'entity_extraction', $a, true);
    ok($r['ok']); eq($r['data']['entities'], array('fixed'));
    mock_api(function () { return api_ok('{"entities": ["a", "b', array('finish' => 'length')); });
    $r = PT_SeoPipelineRunAiPass(1, 'entity_extraction', 'entity-extraction', 'entity_extraction', $a, true);
    ok(!$r['ok'] && !empty($r['truncated'])); eq(count($GLOBALS['CALLS']), 1, 'no repair attempt on truncated output');
});

// ============================================================ summaries: descriptions, regeneration, source preservation
test('desc: finishing a transcript never overwrites the description by default', function () {
    global $db;
    summary_mock();
    seed_transcript(1, make_transcript(300), array('status' => 'processing'));
    $v = $db->getOne(T_VIDEOS);
    PT_FinalizeTranscriptWithSeo($v, make_transcript(300), 'upload/transcripts/x.vtt', 'en');
    eq($db->tables[T_VIDEOS][1]['description'], 'ORIGINAL DESCRIPTION');
    ok($db->tables[T_VIDEO_TRANSCRIPTS][1]['seo_summary'] !== '', 'summary still stored for the watch page');
});
test('desc: auto-apply is opt-in and keeps a backup of the original', function () {
    global $db, $pt;
    summary_mock();
    $pt->config->transcript_auto_apply_description = 'on';
    $db->columns[T_VIDEO_TRANSCRIPTS . '.description_backup'] = true;
    seed_transcript(1, make_transcript(300), array('status' => 'processing'));
    PT_FinalizeTranscriptWithSeo($db->getOne(T_VIDEOS), make_transcript(300), 'x.vtt', 'en');
    ok($db->tables[T_VIDEOS][1]['description'] !== 'ORIGINAL DESCRIPTION');
    eq($db->tables[T_VIDEO_TRANSCRIPTS][1]['description_backup'], 'ORIGINAL DESCRIPTION');
});
test('regen: regenerates from stored text without Whisper, keeps transcript, leaves description alone', function () {
    global $db;
    summary_mock();
    $plain = make_transcript(500);
    seed_transcript(1, $plain);
    $r = PT_RegenerateSeoSummaryForVideo(1);
    ok($r['ok'], isset($r['error']) ? $r['error'] : '');
    eq($db->tables[T_VIDEO_TRANSCRIPTS][1]['plain_text'], $plain, 'source transcript untouched');
    eq($db->tables[T_VIDEOS][1]['description'], 'ORIGINAL DESCRIPTION');
    ok(!$r['description_applied']);
    ok($r['usage']['calls'] === 1 && $r['usage']['cost_usd'] > 0);
});
test('regen: existing summary is not re-billed unless forced', function () {
    global $db;
    summary_mock();
    seed_transcript(1, make_transcript(300), array('seo_summary' => 'Existing summary', 'key_takeaways' => json_encode(array('A'))));
    $r = PT_RegenerateSeoSummaryForVideo(1);
    ok(!$r['ok'] && !empty($r['skipped'])); eq(count($GLOBALS['CALLS']), 0);
    $r = PT_RegenerateSeoSummaryForVideo(1, array('force' => true));
    ok($r['ok']); eq(count($GLOBALS['CALLS']), 1);
});
test('regen: concurrent regeneration of the same video is refused', function () {
    global $db;
    summary_mock();
    seed_transcript(1);
    $db->held_locks['pt_summary_1'] = true;
    $r = PT_RegenerateSeoSummaryForVideo(1);
    ok(!$r['ok'] && !empty($r['skipped'])); eq(count($GLOBALS['CALLS']), 0);
});
test('regen: failure is stored visibly and the old summary survives', function () {
    global $db;
    mock_api(function () { return api_ok('garbage'); });
    seed_transcript(1, make_transcript(300), array('seo_summary' => 'Old good summary'));
    $r = PT_RegenerateSeoSummaryForVideo(1, array('force' => true));
    ok(!$r['ok']);
    eq($db->tables[T_VIDEO_TRANSCRIPTS][1]['seo_summary'], 'Old good summary');
    ok($db->tables[T_VIDEO_TRANSCRIPTS][1]['error_message'] !== '');
});
test('connection test uses the configured summary model and is capped to one cheap call', function () {
    mock_api(function () { return api_ok('OK'); });
    $r = PT_TestOpenAiConnection();
    ok($r['ok']); eq(count($GLOBALS['CALLS']), 1); eq($GLOBALS['CALLS'][0]['model'], 'gpt-6-luna'); eq($GLOBALS['CALLS'][0]['max_completion_tokens'], 64);
});

// ============================================================ benchmark scoring (Python, no audio / no API)
test('transcription benchmark scorer: WER and term recall', function () {
    $out = shell_exec('python3 -I ' . escapeshellarg($GLOBALS['REPO'] . '/scripts/benchmark_transcription.py') . ' --self-test 2>&1');
    has($out, 'self-test ok');
});

echo "\n";
$fail = $GLOBALS['T_FAIL'];
echo $GLOBALS['T_PASS'] . ' assertions passed, ' . count($fail) . " failed\n";
foreach ($fail as $f) { echo "  FAIL  $f\n"; }
exit(count($fail) ? 1 : 0);
