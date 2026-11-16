<?php

/**
 * Shared OpenAI client for the transcript summary and SEO content pipeline.
 *
 * Responsibilities (and the only place they live):
 *  - Model/stage configuration (content-pipeline/config/pipeline-models.json + admin overrides)
 *  - Building Chat Completions requests that suit each model family
 *  - Retries for transient failures, truncation detection, usage + cost accounting
 *  - Per-job budgets (cost and call ceilings)
 *  - Token-aware transcript splitting
 *  - Small DB helpers: atomic queue claims and named locks
 *
 * No model name is hard-coded here: everything comes from pipeline-models.json.
 * Compatible with PHP 7.1+.
 */

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

function PT_AiConfigPath() {
    return PT_TranscriptRootDir() . 'content-pipeline/config/pipeline-models.json';
}

function PT_AiConfig($reload = false) {
    static $cache = null;
    if ($reload || $cache === null) {
        $cache = array();
        $path = PT_AiConfigPath();
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $cache = $decoded;
            }
        }
    }
    if (!empty($GLOBALS['PT_AI_CONFIG_OVERRIDE']) && is_array($GLOBALS['PT_AI_CONFIG_OVERRIDE'])) {
        // Test hook: section-level overrides (never set in production code).
        $merged = $cache;
        foreach ($GLOBALS['PT_AI_CONFIG_OVERRIDE'] as $section => $values) {
            $merged[$section] = array_merge(isset($merged[$section]) && is_array($merged[$section]) ? $merged[$section] : array(), (array) $values);
        }
        return $merged;
    }
    return $cache;
}

function PT_AiConfigSection($section) {
    $cfg = PT_AiConfig();
    return (!empty($cfg[$section]) && is_array($cfg[$section])) ? $cfg[$section] : array();
}

/** Admin-tunable limit with JSON default (config key: ai_<name>). */
function PT_AiLimit($name) {
    global $pt;
    $key = 'ai_' . $name;
    if (isset($pt->config->{$key}) && is_numeric($pt->config->{$key}) && (float) $pt->config->{$key} > 0) {
        return (float) $pt->config->{$key};
    }
    $limits = PT_AiConfigSection('limits');
    return isset($limits[$name]) ? (float) $limits[$name] : 0.0;
}

/** Model for a stage: admin override > pipeline-models.json default. Returns '' if unconfigured. */
function PT_AiStageModel($stage) {
    global $pt;
    $config_key = 'seo_pipeline_model_' . $stage;
    if (!empty($pt->config->{$config_key})) {
        return trim($pt->config->{$config_key});
    }
    $defaults = PT_AiConfigSection('defaults');
    return !empty($defaults[$stage]) ? (string) $defaults[$stage] : '';
}

function PT_AiStageSettings($stage) {
    $all = PT_AiConfigSection('stage_settings');
    return !empty($all[$stage]) && is_array($all[$stage]) ? $all[$stage] : array();
}

function PT_AiStageList() {
    return array_keys(PT_AiConfigSection('defaults'));
}

function PT_AiModelInfo($model) {
    $models = PT_AiConfigSection('models');
    if (isset($models[$model]) && is_array($models[$model])) {
        return $models[$model];
    }
    $unknown = PT_AiConfigSection('unknown_model');
    return !empty($unknown) ? $unknown : array(
        'reasoning' => true, 'token_param' => 'max_completion_tokens', 'temperature' => false,
        'price_input' => 0, 'price_cached_input' => 0, 'price_output' => 0,
    );
}

// ---------------------------------------------------------------------------
// Cost and tokens
// ---------------------------------------------------------------------------

/** Conservative token estimate (no tokenizer on shared hosting): errs high so chunking stays safe. */
function PT_AiEstimateTokens($text) {
    $text = (string) $text;
    if ($text === '') {
        return 0;
    }
    return (int) ceil(strlen($text) / 3.3);
}

function PT_AiEstimateCost($model, $usage) {
    $info = PT_AiModelInfo($model);
    $prompt = !empty($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : 0;
    $cached = !empty($usage['cached_tokens']) ? min((int) $usage['cached_tokens'], $prompt) : 0;
    $completion = !empty($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : 0;
    $in = isset($info['price_input']) ? (float) $info['price_input'] : 0.0;
    $cin = isset($info['price_cached_input']) ? (float) $info['price_cached_input'] : $in;
    $out = isset($info['price_output']) ? (float) $info['price_output'] : 0.0;
    if (!empty($info['long_context_threshold']) && $prompt > (int) $info['long_context_threshold']) {
        $im = isset($info['long_context_input_multiplier']) ? (float) $info['long_context_input_multiplier'] : 1.0;
        $om = isset($info['long_context_output_multiplier']) ? (float) $info['long_context_output_multiplier'] : 1.0;
        $in *= $im;
        $cin *= $im;
        $out *= $om;
    }
    $cost = (($prompt - $cached) * $in + $cached * $cin + $completion * $out) / 1000000;
    return round($cost, 6);
}

// ---------------------------------------------------------------------------
// Per-job budget (cost and call ceilings, aggregate usage)
// ---------------------------------------------------------------------------

function PT_AiBudgetBegin($label = 'job', $overrides = array()) {
    $GLOBALS['PT_AI_BUDGET'] = array(
        'label' => $label,
        'calls' => 0,
        'cost' => 0.0,
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'reasoning_tokens' => 0,
        'cached_tokens' => 0,
        'max_cost' => isset($overrides['max_cost']) ? (float) $overrides['max_cost'] : PT_AiLimit('max_cost_usd_per_job'),
        'max_calls' => isset($overrides['max_calls']) ? (int) $overrides['max_calls'] : (int) PT_AiLimit('max_calls_per_job'),
    );
}

function PT_AiBudget() {
    if (empty($GLOBALS['PT_AI_BUDGET'])) {
        PT_AiBudgetBegin('request');
    }
    return $GLOBALS['PT_AI_BUDGET'];
}

/** @return string error message if the budget is exhausted, '' otherwise */
function PT_AiBudgetCheck() {
    $b = PT_AiBudget();
    if ($b['max_calls'] > 0 && $b['calls'] >= $b['max_calls']) {
        return 'Per-job API call limit reached (' . $b['max_calls'] . ' calls). Raise "ai_max_calls_per_job" if this is expected.';
    }
    if ($b['max_cost'] > 0 && $b['cost'] >= $b['max_cost']) {
        return sprintf('Per-job estimated cost limit reached ($%.2f of $%.2f). Raise "ai_max_cost_usd_per_job" if this is expected.', $b['cost'], $b['max_cost']);
    }
    return '';
}

function PT_AiBudgetRecord($usage, $cost) {
    PT_AiBudget();
    $b = &$GLOBALS['PT_AI_BUDGET'];
    $b['calls']++;
    $b['cost'] += (float) $cost;
    foreach (array('prompt_tokens', 'completion_tokens', 'reasoning_tokens', 'cached_tokens') as $k) {
        $b[$k] += !empty($usage[$k]) ? (int) $usage[$k] : 0;
    }
}

function PT_AiBudgetSnapshot() {
    $b = PT_AiBudget();
    $b['cost'] = round($b['cost'], 6);
    return $b;
}

/** Usage accrued between two snapshots. */
function PT_AiBudgetDelta($before, $after) {
    $d = array();
    foreach (array('calls', 'prompt_tokens', 'completion_tokens', 'reasoning_tokens', 'cached_tokens') as $k) {
        $d[$k] = (int) $after[$k] - (int) $before[$k];
    }
    $d['cost_usd'] = round((float) $after['cost'] - (float) $before['cost'], 6);
    return $d;
}

// ---------------------------------------------------------------------------
// HTTP transport (replaceable in tests via $GLOBALS['PT_AI_TRANSPORT'])
// ---------------------------------------------------------------------------

function PT_AiHttpPost($payload, $api_key, $timeout) {
    if (!empty($GLOBALS['PT_AI_TRANSPORT']) && is_callable($GLOBALS['PT_AI_TRANSPORT'])) {
        return call_user_func($GLOBALS['PT_AI_TRANSPORT'], $payload, $timeout);
    }
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ));
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return array('status' => $status, 'body' => ($body === false ? '' : $body), 'error' => $err);
}

function PT_AiSleep($seconds) {
    if (!empty($GLOBALS['PT_AI_NO_SLEEP'])) {
        return;
    }
    sleep((int) $seconds);
}

// ---------------------------------------------------------------------------
// Request building and response parsing
// ---------------------------------------------------------------------------

function PT_AiBuildPayload($messages, $model, $stage, $options = array()) {
    $info = PT_AiModelInfo($model);
    $settings = PT_AiStageSettings($stage);
    $max_out = isset($options['max_completion_tokens']) ? (int) $options['max_completion_tokens']
        : (isset($settings['max_completion_tokens']) ? (int) $settings['max_completion_tokens'] : 8000);
    if (!empty($info['max_output'])) {
        $max_out = min($max_out, (int) $info['max_output']);
    }
    $payload = array('model' => $model, 'messages' => $messages);
    $token_param = !empty($info['token_param']) ? $info['token_param'] : 'max_completion_tokens';
    $payload[$token_param] = $max_out;

    if (!empty($info['reasoning'])) {
        $effort = isset($options['reasoning_effort']) ? $options['reasoning_effort']
            : (isset($settings['reasoning_effort']) ? $settings['reasoning_effort'] : null);
        if ($effort !== null && $effort !== '') {
            $payload['reasoning_effort'] = $effort;
        }
    }
    if (!empty($info['temperature'])) {
        $temp = isset($options['temperature']) ? $options['temperature']
            : (isset($settings['temperature']) ? $settings['temperature'] : null);
        if ($temp !== null) {
            $payload['temperature'] = (float) $temp;
        }
    }
    if (!empty($options['json'])) {
        $payload['response_format'] = array('type' => 'json_object');
    }
    return $payload;
}

function PT_AiExtractUsage($data) {
    $u = (!empty($data['usage']) && is_array($data['usage'])) ? $data['usage'] : array();
    $prompt = isset($u['prompt_tokens']) ? (int) $u['prompt_tokens'] : (isset($u['input_tokens']) ? (int) $u['input_tokens'] : 0);
    $completion = isset($u['completion_tokens']) ? (int) $u['completion_tokens'] : (isset($u['output_tokens']) ? (int) $u['output_tokens'] : 0);
    $reasoning = !empty($u['completion_tokens_details']['reasoning_tokens']) ? (int) $u['completion_tokens_details']['reasoning_tokens'] : 0;
    $cached = !empty($u['prompt_tokens_details']['cached_tokens']) ? (int) $u['prompt_tokens_details']['cached_tokens'] : 0;
    return array(
        'prompt_tokens' => $prompt,
        'completion_tokens' => $completion,
        'reasoning_tokens' => $reasoning,
        'cached_tokens' => $cached,
    );
}

/** Which unsupported request field does a 400 error point at? ('' if none/unsafe to strip) */
function PT_AiUnsupportedParam($data, $payload) {
    if (empty($data['error']) || !is_array($data['error'])) {
        return '';
    }
    $err = $data['error'];
    $msg = !empty($err['message']) ? (string) $err['message'] : '';
    $param = !empty($err['param']) ? (string) $err['param'] : '';
    $code = !empty($err['code']) ? (string) $err['code'] : '';
    $looks_unsupported = ($code === 'unsupported_parameter' || $code === 'unsupported_value'
        || stripos($msg, 'unsupported') !== false || stripos($msg, 'not supported') !== false);
    if (!$looks_unsupported) {
        return '';
    }
    $strippable = array('temperature', 'reasoning_effort', 'response_format', 'max_tokens', 'max_completion_tokens');
    if ($param !== '' && in_array($param, $strippable, true) && array_key_exists($param, $payload)) {
        return $param;
    }
    foreach ($strippable as $candidate) {
        if (array_key_exists($candidate, $payload) && stripos($msg, "'" . $candidate . "'") !== false) {
            return $candidate;
        }
    }
    return '';
}

function PT_AiAltTokenParam($param) {
    return $param === 'max_tokens' ? 'max_completion_tokens' : ($param === 'max_completion_tokens' ? 'max_tokens' : '');
}

/**
 * One chat completion for a stage.
 *
 * $options: video_id, pass_name (log label), json (bool), model, timeout,
 *           max_completion_tokens, reasoning_effort, temperature
 * @return array ok, content, error, error_code, truncated, finish_reason, usage, cost_usd, model, raw, attempts, adjusted
 */
function PT_AiChat($messages, $stage, $options = array()) {
    global $pt;
    $model = !empty($options['model']) ? $options['model'] : PT_AiStageModel($stage);
    $pass_name = !empty($options['pass_name']) ? $options['pass_name'] : $stage;
    $video_id = !empty($options['video_id']) ? (int) $options['video_id'] : 0;
    $fail = function ($code, $message, $extra = array()) use ($model, $pass_name, $video_id) {
        $res = array_merge(array(
            'ok' => false, 'content' => '', 'error' => $message, 'error_code' => $code, 'truncated' => false,
            'finish_reason' => '', 'usage' => array(), 'cost_usd' => 0.0, 'model' => $model, 'raw' => '', 'attempts' => 0, 'adjusted' => array(),
        ), $extra);
        return $res;
    };

    $api_key = !empty($pt->config->openai_api_key) ? trim($pt->config->openai_api_key) : '';
    if ($api_key === '') {
        return $fail('no_key', 'OpenAI API key is not configured');
    }
    if ($model === '') {
        return $fail('no_model', 'No model configured for stage "' . $stage . '"');
    }
    $budget_error = PT_AiBudgetCheck();
    if ($budget_error !== '') {
        return $fail('budget', $budget_error);
    }

    $settings = PT_AiStageSettings($stage);
    $timeout = !empty($options['timeout']) ? (int) $options['timeout'] : (!empty($settings['timeout']) ? (int) $settings['timeout'] : 180);
    $payload = PT_AiBuildPayload($messages, $model, $stage, $options);
    $max_retries = (int) PT_AiLimit('max_retries');
    $base_delay = max(1, (int) PT_AiLimit('retry_base_delay_seconds'));
    $started = time();
    $adjusted = array();
    $attempt = 0;
    $transient_failures = 0;
    $http = null;
    $data = null;

    while (true) {
        $attempt++;
        $http = PT_AiHttpPost($payload, $api_key, $timeout);
        $status = isset($http['status']) ? (int) $http['status'] : 0;
        $body = isset($http['body']) ? (string) $http['body'] : '';
        $data = json_decode($body, true);

        if ($status >= 200 && $status < 300 && is_array($data)) {
            break;
        }
        if ($status >= 200 && $status < 300) {
            // 2xx but not JSON: treat as malformed, never as success
            $res = $fail('malformed', 'OpenAI returned a malformed (non-JSON) response', array('raw' => substr($body, 0, 4000), 'attempts' => $attempt));
            PT_AiLogCall(array('video_id' => $video_id, 'pass_name' => $pass_name, 'model_used' => $model, 'started_at' => $started, 'status' => 'failed', 'error_message' => $res['error'], 'raw_response' => $res['raw']));
            return $res;
        }
        if ($status === 400 && is_array($data) && count($adjusted) < 3) {
            $bad = PT_AiUnsupportedParam($data, $payload);
            if ($bad !== '') {
                $alt = PT_AiAltTokenParam($bad);
                $value = $payload[$bad];
                unset($payload[$bad]);
                if ($alt !== '' && !array_key_exists($alt, $payload)) {
                    $payload[$alt] = $value; // swap max_tokens <-> max_completion_tokens
                }
                $adjusted[] = $bad;
                continue;
            }
        }
        $quota = is_array($data) && !empty($data['error']['code']) && $data['error']['code'] === 'insufficient_quota';
        $transient = ($status === 0 || $status === 408 || $status === 409 || $status === 429 || $status >= 500) && !$quota;
        if ($transient && $transient_failures < $max_retries) {
            $transient_failures++;
            PT_AiSleep($base_delay * pow(2, $transient_failures - 1));
            continue;
        }
        if ($status === 0) {
            $msg = 'OpenAI request failed: ' . (!empty($http['error']) ? $http['error'] : 'no response');
            $code = 'network';
        } else {
            $api_msg = (is_array($data) && !empty($data['error']['message'])) ? $data['error']['message'] : substr($body, 0, 500);
            $msg = 'OpenAI HTTP ' . $status . ': ' . $api_msg;
            $code = $quota ? 'quota' : ($status === 401 ? 'auth' : ($status === 429 ? 'rate_limit' : 'http_' . $status));
        }
        $res = $fail($code, $msg, array('raw' => substr($body, 0, 4000), 'attempts' => $attempt, 'adjusted' => $adjusted));
        PT_AiLogCall(array('video_id' => $video_id, 'pass_name' => $pass_name, 'model_used' => $model, 'started_at' => $started, 'status' => 'failed', 'error_message' => $msg, 'raw_response' => $res['raw']));
        return $res;
    }

    // --- parse a 2xx response ---
    $usage = PT_AiExtractUsage($data);
    $cost = PT_AiEstimateCost($model, $usage);
    PT_AiBudgetRecord($usage, $cost); // tokens were billed even if the content is unusable

    $choice = (!empty($data['choices'][0]) && is_array($data['choices'][0])) ? $data['choices'][0] : array();
    $finish = !empty($choice['finish_reason']) ? (string) $choice['finish_reason'] : '';
    $message = (!empty($choice['message']) && is_array($choice['message'])) ? $choice['message'] : array();
    $content = '';
    if (isset($message['content']) && is_string($message['content'])) {
        $content = trim($message['content']);
    }
    $refusal = !empty($message['refusal']) && is_string($message['refusal']) ? $message['refusal'] : '';

    $result = array(
        'ok' => false, 'content' => $content, 'error' => '', 'error_code' => '', 'truncated' => false,
        'finish_reason' => $finish, 'usage' => $usage, 'cost_usd' => $cost, 'model' => $model,
        'raw' => '', 'attempts' => $attempt, 'adjusted' => $adjusted,
    );
    if ($finish === 'length') {
        $result['truncated'] = true;
        $result['error_code'] = 'truncated';
        $result['error'] = 'Output was cut off at the token limit (' . $usage['completion_tokens'] . ' tokens, ' . $usage['reasoning_tokens'] . ' of them reasoning). Raise the stage limit in pipeline-models.json or lower reasoning effort.';
    } elseif ($finish === 'content_filter') {
        $result['error_code'] = 'content_filter';
        $result['error'] = 'OpenAI blocked the response (content filter)';
    } elseif ($refusal !== '') {
        $result['error_code'] = 'refusal';
        $result['error'] = 'OpenAI refused the request: ' . substr($refusal, 0, 300);
    } elseif ($content === '') {
        $result['error_code'] = 'empty';
        $result['error'] = 'OpenAI returned empty content';
    } else {
        $result['ok'] = true;
    }
    if (!$result['ok']) {
        $result['raw'] = substr(json_encode($data), 0, 4000);
    }

    PT_AiLogCall(array(
        'video_id' => $video_id,
        'pass_name' => $pass_name,
        'model_used' => $model,
        'usage' => $usage,
        'cost_usd' => $cost,
        'finish_reason' => $finish,
        'started_at' => $started,
        'status' => $result['ok'] ? 'success' : 'failed',
        'error_message' => $result['ok'] ? null : $result['error'],
        'raw_response' => $result['ok'] ? null : $result['raw'],
    ));
    return $result;
}

// ---------------------------------------------------------------------------
// Logging (works before and after migration 2032 adds the extra columns)
// ---------------------------------------------------------------------------

function PT_AiColumnExists($table, $column) {
    global $db;
    if (!isset($GLOBALS['PT_AI_COLUMN_CACHE'])) {
        $GLOBALS['PT_AI_COLUMN_CACHE'] = array();
    }
    $cache = &$GLOBALS['PT_AI_COLUMN_CACHE'];
    $key = $table . '.' . $column;
    if (!isset($cache[$key])) {
        $cache[$key] = false;
        try {
            $rows = $db->rawQuery("SHOW COLUMNS FROM `" . preg_replace('/[^a-z0-9_]/i', '', $table) . "` LIKE '" . preg_replace('/[^a-z0-9_]/i', '', $column) . "'");
            $cache[$key] = !empty($rows);
        } catch (Exception $e) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

function PT_AiLogCall($data) {
    global $db;
    $usage = !empty($data['usage']) ? $data['usage'] : array();
    $row = array(
        'video_id' => (int) (isset($data['video_id']) ? $data['video_id'] : 0),
        'pass_name' => !empty($data['pass_name']) ? substr($data['pass_name'], 0, 64) : '',
        'model_used' => !empty($data['model_used']) ? substr($data['model_used'], 0, 64) : '',
        'input_tokens' => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : (isset($data['input_tokens']) ? (int) $data['input_tokens'] : null),
        'output_tokens' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : (isset($data['output_tokens']) ? (int) $data['output_tokens'] : null),
        'started_at' => (int) (isset($data['started_at']) ? $data['started_at'] : time()),
        'completed_at' => !empty($data['completed_at']) ? (int) $data['completed_at'] : time(),
        'status' => !empty($data['status']) ? $data['status'] : 'unknown',
        'error_message' => !empty($data['error_message']) ? substr($data['error_message'], 0, 5000) : null,
        'raw_response' => !empty($data['raw_response']) ? $data['raw_response'] : null,
    );
    if (PT_AiColumnExists(T_SEO_PIPELINE_LOGS, 'cost_usd')) {
        $row['reasoning_tokens'] = isset($usage['reasoning_tokens']) ? (int) $usage['reasoning_tokens'] : 0;
        $row['cached_tokens'] = isset($usage['cached_tokens']) ? (int) $usage['cached_tokens'] : 0;
        $row['cost_usd'] = isset($data['cost_usd']) ? (float) $data['cost_usd'] : 0;
        $row['finish_reason'] = !empty($data['finish_reason']) ? substr($data['finish_reason'], 0, 32) : null;
    }
    return $db->insert(T_SEO_PIPELINE_LOGS, $row);
}

/** Aggregate token/cost report for one video from the call log (admin + reporting). */
function PT_AiJobUsageReport($video_id, $since = 0) {
    global $db;
    $video_id = (int) $video_id;
    $has_cost = PT_AiColumnExists(T_SEO_PIPELINE_LOGS, 'cost_usd');
    $q = $db->where('video_id', $video_id);
    if ($since > 0) {
        $q = $q->where('started_at', (int) $since, '>=');
    }
    $rows = $q->get(T_SEO_PIPELINE_LOGS);
    $report = array('calls' => 0, 'failed_calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => 0.0, 'by_stage' => array());
    if (empty($rows)) {
        return $report;
    }
    foreach ($rows as $r) {
        $stage = PT_DbVal($r, 'pass_name', '');
        $in = (int) PT_DbVal($r, 'input_tokens', 0);
        $out = (int) PT_DbVal($r, 'output_tokens', 0);
        $cost = $has_cost ? (float) PT_DbVal($r, 'cost_usd', 0) : PT_AiEstimateCost(PT_DbVal($r, 'model_used', ''), array('prompt_tokens' => $in, 'completion_tokens' => $out));
        $report['calls']++;
        if (PT_DbVal($r, 'status') !== 'success') {
            $report['failed_calls']++;
        }
        $report['input_tokens'] += $in;
        $report['output_tokens'] += $out;
        $report['reasoning_tokens'] += (int) PT_DbVal($r, 'reasoning_tokens', 0);
        $report['cost_usd'] += $cost;
        if (!isset($report['by_stage'][$stage])) {
            $report['by_stage'][$stage] = array('calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0.0, 'model' => PT_DbVal($r, 'model_used', ''));
        }
        $report['by_stage'][$stage]['calls']++;
        $report['by_stage'][$stage]['input_tokens'] += $in;
        $report['by_stage'][$stage]['output_tokens'] += $out;
        $report['by_stage'][$stage]['cost_usd'] += $cost;
    }
    $report['cost_usd'] = round($report['cost_usd'], 4);
    return $report;
}

// ---------------------------------------------------------------------------
// Atomic claims and named locks (duplicate-processing protection)
// ---------------------------------------------------------------------------

/** Atomically flip a queue row from processing=0 to 1. Only one worker can win. */
function PT_AiClaimQueueRow($table, $row_id) {
    global $db;
    $fields = array('processing' => 1);
    if (PT_AiColumnExists($table, 'claimed_at')) {
        $fields['claimed_at'] = time();
    }
    $db->where('id', (int) $row_id)->where('processing', 0)->update($table, $fields);
    return !empty($db->count) && (int) $db->count > 0;
}

function PT_AiAcquireLock($name) {
    global $db;
    $name = 'pt_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
    try {
        $rows = $db->rawQuery("SELECT GET_LOCK('" . $name . "', 0) AS got");
    } catch (Exception $e) {
        return true; // do not block work if the DB cannot lock; claims still protect queue rows
    }
    if (empty($rows)) {
        return true;
    }
    $first = $rows[0];
    $got = PT_DbVal($first, 'got', null);
    return $got === null ? true : ((int) $got === 1);
}

function PT_AiReleaseLock($name) {
    global $db;
    $name = 'pt_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
    try {
        $db->rawQuery("SELECT RELEASE_LOCK('" . $name . "')");
    } catch (Exception $e) {
    }
}

// ---------------------------------------------------------------------------
// Token-aware transcript splitting
// ---------------------------------------------------------------------------

/** Split text into atomic units (lines, then sentences, then word runs) none larger than $max_tokens. */
function PT_AiTextUnits($text, $max_tokens) {
    $units = array();
    $text = str_replace("\r\n", "\n", trim((string) $text));
    if ($text === '') {
        return $units;
    }
    foreach (preg_split('/\n+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (PT_AiEstimateTokens($line) <= $max_tokens) {
            $units[] = $line;
            continue;
        }
        foreach (preg_split('/(?<=[.!?])\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) as $sentence) {
            if (PT_AiEstimateTokens($sentence) <= $max_tokens) {
                $units[] = $sentence;
                continue;
            }
            // unpunctuated run (common in raw Whisper text): split on word boundaries
            $words = preg_split('/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY);
            $buf = '';
            foreach ($words as $w) {
                if ($buf !== '' && PT_AiEstimateTokens($buf . ' ' . $w) > $max_tokens) {
                    $units[] = $buf;
                    $buf = '';
                }
                $buf = ($buf === '') ? $w : $buf . ' ' . $w;
            }
            if ($buf !== '') {
                $units[] = $buf;
            }
        }
    }
    return $units;
}

/**
 * Split into chunks of about $target_tokens, on unit boundaries, with whole-unit overlap.
 * Guarantees: every unit appears in at least one chunk; chunks advance; no unit is cut mid-way.
 * @return array list of array('text','first_unit','last_unit','tokens','overlap_units')
 */
function PT_AiSplitText($text, $target_tokens, $overlap_tokens = 0) {
    $target_tokens = max(200, (int) $target_tokens);
    $overlap_tokens = max(0, min((int) $overlap_tokens, (int) floor($target_tokens / 2)));
    $units = PT_AiTextUnits($text, $target_tokens);
    $n = count($units);
    $chunks = array();
    if ($n === 0) {
        return $chunks;
    }
    $sizes = array();
    foreach ($units as $i => $u) {
        $sizes[$i] = PT_AiEstimateTokens($u) + 1;
    }
    $start = 0;
    while ($start < $n) {
        $tokens = 0;
        $end = $start;
        while ($end < $n && ($end === $start || $tokens + $sizes[$end] <= $target_tokens)) {
            $tokens += $sizes[$end];
            $end++;
        }
        $last = $end - 1;
        $chunks[] = array(
            'text' => implode(' ', array_slice($units, $start, $end - $start)),
            'first_unit' => $start,
            'last_unit' => $last,
            'tokens' => $tokens,
        );
        if ($end >= $n) {
            break;
        }
        $next = $end;
        $back = 0;
        while ($next - 1 > $start && $back + $sizes[$next - 1] <= $overlap_tokens) {
            $back += $sizes[$next - 1];
            $next--;
        }
        $start = max($next, $start + 1);
    }
    foreach ($chunks as $i => $c) {
        $chunks[$i]['overlap_units'] = ($i > 0) ? max(0, $chunks[$i - 1]['last_unit'] - $c['first_unit'] + 1) : 0;
    }
    return $chunks;
}

/** True when every unit of the text is inside some chunk (no content dropped). */
function PT_AiChunksCoverText($text, $chunks, $target_tokens) {
    $n = count(PT_AiTextUnits($text, max(200, (int) $target_tokens)));
    if ($n === 0) {
        return empty($chunks);
    }
    $seen = array_fill(0, $n, false);
    foreach ($chunks as $c) {
        for ($i = $c['first_unit']; $i <= $c['last_unit']; $i++) {
            $seen[$i] = true;
        }
    }
    return !in_array(false, $seen, true);
}

// ---------------------------------------------------------------------------
// Medical-fact fidelity guard for cleaned transcripts
// ---------------------------------------------------------------------------

/** Numeric facts (doses, lab values, percentages, years, counts) found in text, normalised. */
function PT_AiExtractNumericFacts($text) {
    $facts = array();
    if (preg_match_all('/\d[\d,]*(?:\.\d+)?/u', (string) $text, $m)) {
        foreach ($m[0] as $num) {
            $num = rtrim(str_replace(',', '', $num), '.');
            if ($num !== '') {
                $facts[] = $num;
            }
        }
    }
    return $facts;
}

/**
 * Compare digits in the raw chunk with the cleaned chunk (multiset comparison).
 * Cleanup may not remove or invent numbers.
 * @return array ok, missing[], added[]
 */
function PT_AiNumericFidelity($raw, $cleaned) {
    $count = function ($text) {
        $c = array();
        foreach (PT_AiExtractNumericFacts($text) as $f) {
            $c[$f] = isset($c[$f]) ? $c[$f] + 1 : 1;
        }
        return $c;
    };
    $r = $count($raw);
    $c = $count($cleaned);
    $missing = array();
    $added = array();
    foreach ($r as $f => $n) {
        if (!isset($c[$f]) || $c[$f] < $n) {
            $missing[] = (string) $f;
        }
    }
    foreach ($c as $f => $n) {
        if (!isset($r[$f]) || $r[$f] < $n) {
            $added[] = (string) $f;
        }
    }
    return array('ok' => empty($missing) && empty($added), 'missing' => $missing, 'added' => $added);
}
