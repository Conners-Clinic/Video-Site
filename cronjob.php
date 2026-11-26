<?php
require_once "./assets/init.php";

// Resolve queued inputs and generated outputs inside this site's video uploads.
function PT_CronVideoPaths($location, $document_root) {
    if (!is_string($location) || strpos($location, 'upload/videos/') !== 0
        || strpos($location, '\\') !== false || preg_match('/[\x00-\x1F\x7F]/', $location)) {
        return false;
    }
    foreach (explode('/', $location) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }
    }
    $document_root = realpath($document_root);
    $upload_root = $document_root === false ? false : realpath($document_root . '/upload/videos');
    if ($upload_root === false || strpos($upload_root, $document_root . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }
    $input_path = $document_root . '/' . $location;
    $input = realpath($input_path);
    if ($input === false || !is_file($input) || !is_readable($input) || is_link($input_path)
        || strpos($input, $upload_root . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }
    $relative_base = pathinfo($location, PATHINFO_DIRNAME) . '/' . pathinfo($location, PATHINFO_FILENAME);
    $output_directory = realpath($document_root . '/' . pathinfo($relative_base, PATHINFO_DIRNAME));
    if ($output_directory === false || ($output_directory !== $upload_root
        && strpos($output_directory, $upload_root . DIRECTORY_SEPARATOR) !== 0)) {
        return false;
    }
    $outputs = array();
    foreach (array(240, 360, 480, 720, 1080, 2048, 4096) as $resolution) {
        $output = $document_root . '/' . $relative_base . '_' . $resolution . 'p_converted.mp4';
        if (is_link($output) || (file_exists($output) && (!is_file($output)
            || strpos((string) realpath($output), $upload_root . DIRECTORY_SEPARATOR) !== 0))) {
            return false;
        }
        $outputs[$resolution] = $output;
    }
    return array('input' => $input, 'relative_base' => $relative_base, 'outputs' => $outputs);
}
// End queued video path validation.


// Dashboard This Year
$type = 'This Year';

$dashboard = updateDashboardData($type);

updateDashboardDB($type,$dashboard);

// Dashboard Today
$type = 'Today';

$dashboard = updateDashboardData($type);

updateDashboardDB($type,$dashboard);

// Dashboard Yesterday
$type = 'Yesterday';

$dashboard = updateDashboardData($type);

updateDashboardDB($type,$dashboard);

// Dashboard This Week
$type = 'This Week';

$dashboard = updateDashboardData($type);

updateDashboardDB($type,$dashboard);

// Dashboard This Month
$type = 'This Month';

$dashboard = updateDashboardData($type);

updateDashboardDB($type,$dashboard);

// Dashboard Last Month
$type = 'Last Month';

$dashboard = updateDashboardData($type);

updateDashboardDB($type,$dashboard);



$db->where('name', 'cronjob_last_run')->update(T_CONFIG, array('value' => time()));

$getCompletedVideos = $db->rawQuery("SELECT * FROM " . T_UPLOADED_CUNKS . " WHERE timestamp < NOW() - INTERVAL 1 DAY;");
foreach ($getCompletedVideos as $key => $video) {
    $deleteFile = $db->where("id", $video->id)->delete(T_UPLOADED_CUNKS);
    @unlink($video->folderpath . '/' . $video->filename);
    if (file_exists($video->folderpath . '/' . $video->filename . '.part')) {
        @unlink($video->folderpath . '/' . $video->filename . '.part');
    }
}

$update_information = PT_UpdateAdminDetails();

$process_queue = $db->get(T_QUEUE, $pt->config->queue_count, "*");
if (count($process_queue) <= $pt->config->queue_count && count($process_queue) > 0) {
    foreach ($process_queue as $key => $value) {
        $video_paths = false;
        try {
            if ($value->processing == 0) {
                $video = $db->where("id", $value->video_id)->getOne(T_VIDEOS);
                $video_paths = $video ? PT_CronVideoPaths($video->video_location, __DIR__) : false;
                if ($video_paths === false) {
                    error_log('Skipped queued video with invalid local upload path; queue id ' . (int) $value->id);
                    continue;
                }
                $video_id = $video->id;
                $video_in_queue = $db
                    ->where("video_id", $video->id)
                    ->getOne(T_QUEUE);
                $db->where("video_id", $video->id);
                $db->update(T_QUEUE, [
                    "processing" => 1,
                ]);
                ob_end_clean();
                header("Content-Encoding: none");
                header("Connection: close");
                ignore_user_abort();
                ob_start();
                header("Content-Type: application/json");
                $size = ob_get_length();
                header("Content-Length: $size");
                ob_end_flush();
                flush();
                session_write_close();
                if (is_callable("fastcgi_finish_request")) {
                    fastcgi_finish_request();
                }
                if (is_callable('litespeed_finish_request')) {
                    litespeed_finish_request();
                }
                $video_res = $video_in_queue->video_res;
                $ffmpeg_b = escapeshellarg($pt->config->ffmpeg_binary_file);
                $ffmpeg_preset_arg = escapeshellarg($pt->config->convert_speed);
                $filepath = $video_paths['relative_base'];
                $time = time();
                $full_dir = str_replace("ajax", "/", __DIR__);

                $video_output_full_path_240 = escapeshellarg($video_paths['outputs'][240]);
                $video_output_full_path_360 = escapeshellarg($video_paths['outputs'][360]);
                $video_output_full_path_480 = escapeshellarg($video_paths['outputs'][480]);
                $video_output_full_path_720 = escapeshellarg($video_paths['outputs'][720]);
                $video_output_full_path_1080 = escapeshellarg($video_paths['outputs'][1080]);
                $video_output_full_path_2048 = escapeshellarg($video_paths['outputs'][2048]);
                $video_output_full_path_4096 = escapeshellarg($video_paths['outputs'][4096]);

                $video_file_full_path = escapeshellarg($video_paths['input']);


                    $shell = shell_exec(
                        "$ffmpeg_b -y -i $video_file_full_path -vcodec libx264 -preset $ffmpeg_preset_arg -filter:v scale=426:-2 -crf 26 $video_output_full_path_240 2>&1"
                    );
                    $upload_s3 = PT_UploadToS3(
                        $filepath . "_240p_converted.mp4"
                    );
                    $db->where("id", $video->id);
                    $db->update(T_VIDEOS, [
                        "converted" => 1,
                        "240p" => 1,
                        "video_location" => $filepath . "_240p_converted.mp4",
                    ]);
                if (
                    ($video_res >= 640 || $video_res == 0) &&
                    $pt->config->p360 == "on"
                ) {
                    $shell = shell_exec(
                        "$ffmpeg_b -y -i $video_file_full_path -vcodec libx264 -preset $ffmpeg_preset_arg -filter:v scale=640:-2 -crf 26 $video_output_full_path_360 2>&1"
                    );
                    $upload_s3 = PT_UploadToS3(
                        $filepath . "_360p_converted.mp4"
                    );
                    $db->where("id", $video->id);
                    $db->update(T_VIDEOS, [
                        "360p" => 1,
                        "converted" => 1,
                        "video_location" => $filepath . "_360p_converted.mp4",
                    ]);
                }

                if (
                    ($video_res >= 854 || $video_res == 0) &&
                    $pt->config->p480 == "on"
                ) {
                    $shell = shell_exec(
                        "$ffmpeg_b -y -i $video_file_full_path -vcodec libx264 -preset $ffmpeg_preset_arg -filter:v scale=854:-2 -crf 26 $video_output_full_path_480 2>&1"
                    );
                    $upload_s3 = PT_UploadToS3(
                        $filepath . "_480p_converted.mp4"
                    );
                    $db->where("id", $video->id);
                    $db->update(T_VIDEOS, [
                        "480p" => 1,
                        "converted" => 1,
                        "video_location" => $filepath . "_480p_converted.mp4",
                    ]);
                }

                if (
                    ($video_res >= 1280 || $video_res == 0) &&
                    $pt->config->p720 == "on"
                ) {
                    $shell = shell_exec(
                        "$ffmpeg_b -y -i $video_file_full_path -vcodec libx264 -preset $ffmpeg_preset_arg -filter:v scale=1280:-2 -crf 26 $video_output_full_path_720 2>&1"
                    );
                    $upload_s3 = PT_UploadToS3(
                        $filepath . "_720p_converted.mp4"
                    );
                    $db->where("id", $video->id);
                    $db->update(T_VIDEOS, [
                        "720p" => 1,
                        "converted" => 1,
                        "video_location" => $filepath . "_720p_converted.mp4",
                    ]);
                }

                if (
                    ($video_res >= 1920 || $video_res == 0) &&
                    $pt->config->p1080 == "on"
                ) {
                    $shell = shell_exec(
                        "$ffmpeg_b -y -i $video_file_full_path -vcodec libx264 -preset $ffmpeg_preset_arg -filter:v scale=1920:-2 -crf 26 $video_output_full_path_1080 2>&1"
                    );
                    $upload_s3 = PT_UploadToS3(
                        $filepath . "_1080p_converted.mp4"
                    );
                    $db->where("id", $video->id);
                    $db->update(T_VIDEOS, [
                        "1080p" => 1,
                        "converted" => 1,
                        "video_location" => $filepath . "_1080p_converted.mp4",
                    ]);
                }

                if ($video_res >= 2048 && $pt->config->p2048 == "on") {
                    $shell = shell_exec(
                        "$ffmpeg_b -y -i $video_file_full_path -vcodec libx264 -preset $ffmpeg_preset_arg -filter:v scale=2048:-2 -crf 26 $video_output_full_path_2048 2>&1"
                    );
                    $upload_s3 = PT_UploadToS3(
                        $filepath . "_2048p_converted.mp4"
                    );
                    $db->where("id", $video->id);
                    $db->update(T_VIDEOS, [
                        "2048p" => 1,
                        "converted" => 1,
                        "video_location" => $filepath . "_2048p_converted.mp4",
                    ]);
                }

                if ($video_res >= 3840 && $pt->config->p4096 == "on") {
                    $shell = shell_exec(
                        "$ffmpeg_b -y -i $video_file_full_path -vcodec libx264 -preset $ffmpeg_preset_arg -filter:v scale=3840:-2 -crf 26 $video_output_full_path_4096 2>&1"
                    );
                    $upload_s3 = PT_UploadToS3(
                        $filepath . "_4096p_converted.mp4"
                    );
                    $db->where("id", $video->id);
                    $db->update(T_VIDEOS, [
                        "4096p" => 1,
                        "converted" => 1,
                        "video_location" => $filepath . "_4096p_converted.mp4",
                    ]);
                }

                if ($video_paths !== false && is_file($video_paths['input'])) {
                    unlink($video_paths['input']);
                }
                                $db->where("video_id", $video->id)->delete(T_QUEUE);
                pt_push_channel_notifiations($video_id);
            }
        } catch (Exception $e) {
            $db->where("video_id", $video->id)->delete(T_QUEUE);
            if ($video_paths !== false && is_file($video_paths['input'])) {
                unlink($video_paths['input']);
            }
        }
    }
}

$users_id = $db->where('subscriber_price',0,'>')->get(T_USERS,null,array('id'));
$ids = array();
foreach ($users_id as $key => $value) {
    $ids[] = $value->id;
}

if (!empty($ids)) {
   $subscribers = $db->where('user_id',$ids,"IN")->where('time',strtotime("-30 days"),'<')->get(T_SUBSCRIPTIONS);
    foreach ($subscribers as $key => $value) {
        $user = $db->where('id',$value->user_id)->getOne(T_USERS);
        $subscriber = $db->where('id',$value->subscriber_id)->where("admin", "0")->getOne(T_USERS);
        if (!empty($user) && !empty($subscriber) && $user->subscriber_price > 0 && $subscriber->wallet >= $user->subscriber_price) {

            $user_id = $user->id;
            $admin__com = ($pt->config->admin_com_subscribers * $user->subscriber_price)/100;
            $pt->config->payment_currency = $pt->config->payment_currency.'_PERCENT';
            $payment_data         = array(
                'user_id' => $user_id,
                'video_id'    => 0,
                'paid_id'  => $subscriber->id,
                'amount'    => $user->subscriber_price,
                'admin_com'    => $pt->config->admin_com_subscribers,
                'currency'    => $pt->config->payment_currency,
                'time'  => time(),
                'type' => 'subscribe'
            );
            $db->insert(T_VIDEOS_TRSNS,$payment_data);
            $balance = $user->subscriber_price - $admin__com;
            $db->rawQuery("UPDATE ".T_USERS." SET `balance` = `balance`+ '".$balance."' WHERE `id` = '".$user_id."'");

            $update = array('wallet' => $db->dec($user->subscriber_price));
            $go_pro = $db->where('id',$subscriber->id)->update(T_USERS,$update);

            $db->where('id',$value->id)->update(T_SUBSCRIPTIONS,array('time' => time()));
        }
        else{
            $db->where('id',$value->id)->delete(T_SUBSCRIPTIONS);
        }
    }
}



$expired_subs   = $db->where('expire',time(),'<')->where('expire',0,'>')->get(T_PAYMENTS);
$admin = $db->where('admin',1)->getOne(T_USERS); 
foreach ($expired_subs as $value){
    $subscriber = $db->where('id',$value->user_id)->where("admin", "0")->getOne(T_USERS);
    $db->where('id',$value->id)->update(T_PAYMENTS,array('expire' => 0));
    if (!empty($subscriber) && $subscriber->wallet >= $pt->config->pro_pkg_price) {
        $price = $pt->config->pro_pkg_price;
        $update = array('is_pro' => 1,'verified' => 1,'wallet' => $db->dec($price));
        $go_pro = $db->where('id',$subscriber->id)->update(T_USERS,$update);
        if ($go_pro === true) {
            $payment_data         = array(
                'user_id' => $subscriber->id,
                'type'    => 'pro',
                'amount'  => $price,
                'date'    => date('n') . '/' . date('Y'),
                'expire'  => strtotime("+30 days")
            );

            $db->insert(T_PAYMENTS,$payment_data);
            $db->where('user_id',$subscriber->id)->update(T_VIDEOS,array('featured' => 1));

            $notif_data = array(
                'notifier_id' => $admin->id,
                'recipient_id' => $subscriber->id,
                'type' => 'pro_renew',
                'url' => "wallet",
                'time' => time()
            );
            pt_notify($notif_data);
        }

    }
    else{
        $update         = array('is_pro' => 0,'verified' => 0);
        $db->where('id',$subscriber->id)->update(T_USERS,$update);
        $db->where('user_id',$subscriber->id)->update(T_VIDEOS,array('featured' => 0));
        $notif_data = array(
            'notifier_id' => $admin->id,
            'recipient_id' => $subscriber->id,
            'type' => 'pro_ended',
            'url' => "go_pro",
            'time' => time()
        );
        pt_notify($notif_data);
    }
}

PT_UpdateAdminDetails();
header("Content-type: application/json");
echo json_encode(["status" => 200, "message" => "success"]);
exit();