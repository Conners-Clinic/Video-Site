<?php

function PT_TranscriptMonitorConfig($key, $default = '') {
    global $pt;
    $val = isset($pt->config->{$key}) ? $pt->config->{$key} : $default;
    return $val;
}

function PT_TranscriptMonitorIsEnabled() {
    return PT_TranscriptMonitorConfig('transcript_load_monitor', 'on') === 'on';
}

function PT_GetCpuCount() {
    $cpus = 0;
    if (is_readable('/proc/cpuinfo')) {
        $cpus = substr_count((string) file_get_contents('/proc/cpuinfo'), 'processor');
    }
    if ($cpus < 1 && function_exists('shell_exec')) {
        $n = @shell_exec('nproc 2>/dev/null');
        if ($n !== null && ctype_digit(trim($n))) {
            $cpus = (int) trim($n);
        }
    }
    return $cpus > 0 ? $cpus : 1;
}

function PT_GetMemoryUsagePercent() {
    if (!is_readable('/proc/meminfo')) {
        return null;
    }
    $info = file_get_contents('/proc/meminfo');
    if ($info === false) {
        return null;
    }
    $total = 0;
    $available = 0;
    if (preg_match('/MemTotal:\s+(\d+)/', $info, $m)) {
        $total = (int) $m[1];
    }
    if (preg_match('/MemAvailable:\s+(\d+)/', $info, $m)) {
        $available = (int) $m[1];
    }
    if ($total < 1) {
        return null;
    }
    if ($available < 1 && preg_match('/MemFree:\s+(\d+)/', $info, $m)) {
        $available = (int) $m[1];
    }
    $used = $total - $available;
    return round(($used / $total) * 100, 1);
}

function PT_GetTranscriptQueueCounts() {
    global $db;
    $queued = (int) $db->arraybuilder()->rawQueryValue(
        "SELECT COUNT(*) FROM " . T_TRANSCRIPT_QUEUE . " WHERE processing = 0 LIMIT 1"
    );
    $processing = (int) $db->arraybuilder()->rawQueryValue(
        "SELECT COUNT(*) FROM " . T_TRANSCRIPT_QUEUE . " WHERE processing = 1 LIMIT 1"
    );
    $transcript_processing = (int) $db->arraybuilder()->rawQueryValue(
        "SELECT COUNT(*) FROM " . T_VIDEO_TRANSCRIPTS . " WHERE status = 'processing' LIMIT 1"
    );
    return array(
        'queued' => $queued,
        'processing' => $processing,
        'transcript_processing' => $transcript_processing,
    );
}

function PT_GetServerLoadSnapshot() {
    $loads = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    $cpus = PT_GetCpuCount();
    $load1 = ($loads !== false && isset($loads[0])) ? round((float) $loads[0], 2) : null;
    $load5 = ($loads !== false && isset($loads[1])) ? round((float) $loads[1], 2) : null;
    $load15 = ($loads !== false && isset($loads[2])) ? round((float) $loads[2], 2) : null;
    $per_cpu = ($load1 !== null && $cpus > 0) ? round($load1 / $cpus, 2) : null;
    $queue = PT_GetTranscriptQueueCounts();
    $jobs_per_run = (int) PT_TranscriptMonitorConfig('transcript_queue_count', 1);
    if ($jobs_per_run < 1) {
        $jobs_per_run = 1;
    }
    if ($jobs_per_run > 5) {
        $jobs_per_run = 5;
    }

    return array(
        'time' => time(),
        'load_1' => $load1,
        'load_5' => $load5,
        'load_15' => $load15,
        'cpus' => $cpus,
        'load_per_cpu' => $per_cpu,
        'memory_percent' => PT_GetMemoryUsagePercent(),
        'queue' => $queue,
        'jobs_per_cron' => $jobs_per_run,
        'load_available' => ($load1 !== null),
    );
}

function PT_EvaluateTranscriptLoadHealth($snapshot) {
    if (empty($snapshot['load_available'])) {
        return array(
            'level' => 'unknown',
            'label' => 'Unavailable',
            'message' => 'Load average is not available on this host (sys_getloadavg).',
            'advice' => array(),
        );
    }
    $warn = (float) PT_TranscriptMonitorConfig('transcript_load_warn_per_cpu', '0.85');
    $crit = (float) PT_TranscriptMonitorConfig('transcript_load_crit_per_cpu', '1.25');
    if ($warn < 0.1) {
        $warn = 0.85;
    }
    if ($crit <= $warn) {
        $crit = $warn + 0.4;
    }
    $ratio = (float) $snapshot['load_per_cpu'];
    $advice = array();
    $queue = $snapshot['queue'];
    if ($ratio >= $crit) {
        $advice[] = 'Slow transcription cron to every 5–10 minutes (e.g. */5 in crontab).';
        $advice[] = 'Lower "Jobs per cron run" to 1–2 in Transcribe Videos settings.';
        if (!empty($queue['processing']) && (int) $queue['processing'] > 1) {
            $advice[] = 'Multiple Whisper jobs may be running at once — wait for the current batch to finish before the next cron hit.';
        }
        return array(
            'level' => 'critical',
            'label' => 'High load',
            'message' => '1-minute load per CPU is ' . $ratio . ' (critical threshold ' . $crit . ').',
            'advice' => $advice,
        );
    }
    if ($ratio >= $warn) {
        $advice[] = 'Consider */5 cron instead of */2 if the queue is draining slowly.';
        if ((int) $snapshot['jobs_per_cron'] > 2) {
            $advice[] = 'Try lowering jobs per cron run to 2.';
        }
        return array(
            'level' => 'warning',
            'label' => 'Elevated',
            'message' => '1-minute load per CPU is ' . $ratio . ' (warning threshold ' . $warn . ').',
            'advice' => $advice,
        );
    }
    return array(
        'level' => 'ok',
        'label' => 'Healthy',
        'message' => 'Load per CPU is ' . $ratio . ' — within normal range.',
        'advice' => array(),
    );
}

function PT_GetTranscriptLoadHistory() {
    $raw = PT_TranscriptMonitorConfig('transcript_load_history', '[]');
    $history = json_decode($raw, true);
    return is_array($history) ? $history : array();
}

function PT_SaveTranscriptLoadHistory($history) {
    global $db;
    $max = 120;
    if (count($history) > $max) {
        $history = array_slice($history, -$max);
    }
    $json = json_encode($history);
    $exists = $db->where('name', 'transcript_load_history')->getValue(T_CONFIG, 'COUNT(*)');
    if ($exists) {
        $db->where('name', 'transcript_load_history')->update(T_CONFIG, array('value' => $json));
    } else {
        $db->insert(T_CONFIG, array('name' => 'transcript_load_history', 'value' => $json));
    }
}

function PT_ShouldRecordTranscriptLoadHistory() {
    $last = (int) PT_TranscriptMonitorConfig('transcript_load_history_last', 0);
    return (time() - $last) >= 120;
}

function PT_RecordTranscriptLoadSnapshot($record_history = true) {
    global $db;
    $snapshot = PT_GetServerLoadSnapshot();
    $health = PT_EvaluateTranscriptLoadHealth($snapshot);
    if ($record_history && PT_ShouldRecordTranscriptLoadHistory()) {
        $history = PT_GetTranscriptLoadHistory();
        $history[] = array(
            'time' => $snapshot['time'],
            'load_per_cpu' => $snapshot['load_per_cpu'],
            'load_1' => $snapshot['load_1'],
            'memory_percent' => $snapshot['memory_percent'],
            'level' => $health['level'],
            'queued' => $snapshot['queue']['queued'],
            'processing' => $snapshot['queue']['processing'],
        );
        PT_SaveTranscriptLoadHistory($history);
        $now = time();
        $exists = $db->where('name', 'transcript_load_history_last')->getValue(T_CONFIG, 'COUNT(*)');
        if ($exists) {
            $db->where('name', 'transcript_load_history_last')->update(T_CONFIG, array('value' => $now));
        } else {
            $db->insert(T_CONFIG, array('name' => 'transcript_load_history_last', 'value' => $now));
        }
    }
    return array('snapshot' => $snapshot, 'health' => $health);
}

function PT_GetTranscriptLoadAlertEmail() {
    global $pt;
    $custom = trim(PT_TranscriptMonitorConfig('transcript_load_alert_email', ''));
    if ($custom !== '' && filter_var($custom, FILTER_VALIDATE_EMAIL)) {
        return $custom;
    }
    if (!empty($pt->config->email) && filter_var($pt->config->email, FILTER_VALIDATE_EMAIL)) {
        return $pt->config->email;
    }
    return '';
}

function PT_BuildTranscriptLoadAlertBody($snapshot, $health) {
    global $pt;
    $site = !empty($pt->config->site_url) ? $pt->config->site_url : '';
    $lines = array();
    $lines[] = 'Transcription server load alert (' . strtoupper($health['level']) . ')';
    $lines[] = '';
    $lines[] = $health['message'];
    $lines[] = '';
    $lines[] = 'Load average (1 / 5 / 15 min): '
        . ($snapshot['load_1'] !== null ? $snapshot['load_1'] : '—') . ' / '
        . ($snapshot['load_5'] !== null ? $snapshot['load_5'] : '—') . ' / '
        . ($snapshot['load_15'] !== null ? $snapshot['load_15'] : '—');
    $lines[] = 'CPU cores: ' . (int) $snapshot['cpus'];
    $lines[] = 'Load per CPU (1 min): ' . ($snapshot['load_per_cpu'] !== null ? $snapshot['load_per_cpu'] : '—');
    if ($snapshot['memory_percent'] !== null) {
        $lines[] = 'Memory used: ' . $snapshot['memory_percent'] . '%';
    }
    $lines[] = 'Transcript queue — waiting: ' . (int) $snapshot['queue']['queued']
        . ', cron processing flag: ' . (int) $snapshot['queue']['processing'];
    $lines[] = 'Jobs per cron run (setting): ' . (int) $snapshot['jobs_per_cron'];
    $lines[] = '';
    if (!empty($health['advice'])) {
        $lines[] = 'Suggested actions:';
        foreach ($health['advice'] as $tip) {
            $lines[] = '• ' . $tip;
        }
        $lines[] = '';
    }
    $admin_link = function_exists('PT_LoadAdminLinkSettings')
        ? PT_LoadAdminLinkSettings('transcribe-videos')
        : ($site . '/admin-cp/transcribe-videos');
    $lines[] = 'Review: ' . $admin_link;
    return implode("\n", $lines);
}

function PT_MaybeSendTranscriptLoadAlert($snapshot, $health, $force = false) {
    global $pt;
    if (!$force && !PT_TranscriptMonitorIsEnabled()) {
        return array('sent' => false, 'reason' => 'monitor_off');
    }
    if (!$force && !in_array($health['level'], array('warning', 'critical'), true)) {
        return array('sent' => false, 'reason' => 'level_ok');
    }
    $to = PT_GetTranscriptLoadAlertEmail();
    if ($to === '') {
        return array('sent' => false, 'reason' => 'no_email');
    }
    $cooldown = (int) PT_TranscriptMonitorConfig('transcript_load_email_cooldown', 3600);
    if ($cooldown < 300) {
        $cooldown = 300;
    }
    $last = (int) PT_TranscriptMonitorConfig('transcript_load_last_alert', 0);
    if (!$force && $last > 0 && (time() - $last) < $cooldown) {
        return array('sent' => false, 'reason' => 'cooldown', 'next_after' => $last + $cooldown);
    }
    $subject = '[' . $pt->config->name . '] Transcription load ' . $health['label'];
    if ($health['level'] === 'critical') {
        $subject = '[' . $pt->config->name . '] URGENT: transcription server load high';
    }
    $body = PT_BuildTranscriptLoadAlertBody($snapshot, $health);
    $send_message_data = array(
        'from_email' => $pt->config->email,
        'from_name' => $pt->config->name,
        'to_email' => $to,
        'to_name' => 'Admin',
        'subject' => $subject,
        'charSet' => 'utf-8',
        'message_body' => $body,
        'is_html' => false,
        'return' => 'debug',
    );
    $result = PT_SendMessage($send_message_data);
    if ($result === true) {
        global $db;
        $exists = $db->where('name', 'transcript_load_last_alert')->getValue(T_CONFIG, 'COUNT(*)');
        $now = time();
        if ($exists) {
            $db->where('name', 'transcript_load_last_alert')->update(T_CONFIG, array('value' => $now));
        } else {
            $db->insert(T_CONFIG, array('name' => 'transcript_load_last_alert', 'value' => $now));
        }
        return array('sent' => true, 'to' => $to);
    }
    return array('sent' => false, 'reason' => 'smtp_failed', 'error' => is_string($result) ? $result : 'unknown');
}

function PT_GetTranscriptLoadStatusPayload($check_alerts = false) {
    $record = PT_RecordTranscriptLoadSnapshot(true);
    $alert = array('sent' => false, 'reason' => 'skipped_poll');
    if ($check_alerts) {
        $alert = PT_MaybeSendTranscriptLoadAlert($record['snapshot'], $record['health']);
    }
    $cooldown = (int) PT_TranscriptMonitorConfig('transcript_load_email_cooldown', 3600);
    $last_alert = (int) PT_TranscriptMonitorConfig('transcript_load_last_alert', 0);
    return array(
        'snapshot' => $record['snapshot'],
        'health' => $record['health'],
        'history' => array_slice(PT_GetTranscriptLoadHistory(), -24),
        'monitor_enabled' => PT_TranscriptMonitorIsEnabled(),
        'alert_email' => PT_GetTranscriptLoadAlertEmail(),
        'last_alert' => $last_alert > 0 ? $last_alert : null,
        'alert_cooldown' => $cooldown,
        'last_alert_result' => $alert,
    );
}
