<?php
require_once './assets/init.php';

if (empty($pt->config->seo_pipeline_system) || $pt->config->seo_pipeline_system != 'on') {
    header('Content-Type: application/json');
    echo json_encode(array('status' => 200, 'message' => 'SEO pipeline disabled'));
    exit();
}

$db->where('name', 'seo_pipeline_cron_last_run')->update(T_CONFIG, array('value' => time()));

PT_SeoResetStaleQueueJobs();
$queue_count = PT_GetSeoPipelineQueueCount();
$processing = $db->where('processing', 1)->getValue(T_SEO_PIPELINE_QUEUE, 'COUNT(*)');
$max_concurrent = !empty($pt->config->seo_pipeline_max_concurrent) ? (int) $pt->config->seo_pipeline_max_concurrent : 1;
if ($processing >= $max_concurrent) {
    header('Content-Type: application/json');
    echo json_encode(array('status' => 200, 'message' => 'Max concurrent jobs running'));
    exit();
}

$slots = max(1, $max_concurrent - (int) $processing);
$take = min($queue_count, $slots);
$process_queue = $db->where('processing', 0)->orderBy('priority', 'DESC')->orderBy('created_at', 'ASC')->get(T_SEO_PIPELINE_QUEUE, $take);

if (empty($process_queue)) {
    header('Content-Type: application/json');
    echo json_encode(array('status' => 200, 'message' => 'No jobs in queue'));
    exit();
}

ob_end_clean();
header('Content-Encoding: none');
header('Connection: close');
ignore_user_abort();
ob_start();
header('Content-Type: application/json');
echo json_encode(array('status' => 200, 'message' => 'Processing ' . count($process_queue) . ' SEO job(s)'));
$size = ob_get_length();
header('Content-Length: ' . $size);
ob_end_flush();
flush();
session_write_close();
if (is_callable('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
if (is_callable('litespeed_finish_request')) {
    litespeed_finish_request();
}

foreach ($process_queue as $queue_row) {
    try {
        PT_ProcessSeoPipelineQueueJob($queue_row);
    } catch (Throwable $e) {
        PT_SeoPipelineMarkFailed((int) $queue_row->video_id, 'cron', $e->getMessage());
        $db->where('id', (int) $queue_row->id)->delete(T_SEO_PIPELINE_QUEUE);
    }
}
