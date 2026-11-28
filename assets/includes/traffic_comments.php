<?php
/** Bounded replies with ordinary links, so deep links and AJAX page replacement work. */
function PT_ReplyPageNumber($value) {
    return is_scalar($value) && ctype_digit((string) $value) ? min(10000, max(1, (int) $value)) : 1;
}

function PT_WatchCommentFocus($videoId) {
    global $db, $pt;
    $commentId = (int) (is_scalar($_GET['cl'] ?? null) ? $_GET['cl'] : 0);
    $replyId = (int) (is_scalar($_GET['rl'] ?? null) ? $_GET['rl'] : 0);
    if ($replyId > 0) {
        $reply = $db->where('id', $replyId)->where('user_id', $pt->blocked_array, 'NOT IN')->getOne(T_COMM_REPLIES, 'comment_id');
        $commentId = $reply ? (int) $reply->comment_id : 0;
    }
    if ($commentId <= 0) { return null; }
    return $db->where('id', $commentId)->where('video_id', (int) $videoId)
        ->where('user_id', $pt->blocked_array, 'NOT IN')->getOne(T_COMMENTS);
}

function PT_WatchReplyPage($comment) {
    global $db, $pt;
    $page = (int) ($_GET['cl'] ?? 0) === (int) $comment->id ? PT_ReplyPageNumber($_GET['reply_page'] ?? 1) : 1;
    $replyId = (int) (is_scalar($_GET['rl'] ?? null) ? $_GET['rl'] : 0);
    if ($replyId > 0) {
        $target = $db->where('id', $replyId)->where('comment_id', $comment->id)
            ->where('user_id', $pt->blocked_array, 'NOT IN')->getOne(T_COMM_REPLIES, 'id');
        if ($target) {
            $before = $db->where('comment_id', $comment->id)->where('id', $replyId, '<')
                ->where('user_id', $pt->blocked_array, 'NOT IN')->getValue(T_COMM_REPLIES, 'count(*)');
            $page = intdiv((int) $before, 5) + 1;
        }
    }
    $rows = $db->where('comment_id', $comment->id)->where('user_id', $pt->blocked_array, 'NOT IN')
        ->orderBy('id', 'ASC')->get(T_COMM_REPLIES, array(($page - 1) * 5, 6));
    $more = count($rows) > 5;
    return array('rows' => array_slice($rows, 0, 5), 'page' => $page, 'more' => $more);
}

function PT_RenderWatchReplies($comment) {
    global $db, $pt, $user, $lang;
    $result = PT_WatchReplyPage($comment);
    $rows = $result['rows'];
    $owners = PT_VideoCardOwners($db, array_map(static function ($row) { return $row->user_id; }, $rows));
    $ids = array_map(static function ($row) { return (int) $row->id; }, $rows);
    $votes = array();
    if ($ids) {
        $viewer = IS_LOGGED ? (int) $user->id : 0;
        $counts = $db->rawQuery('SELECT reply_id, type, COUNT(*) AS total, MAX(user_id = ?) AS mine FROM ' . T_COMMENTS_LIKES
            . ' WHERE reply_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY reply_id, type', array_merge(array($viewer), $ids));
        foreach ($counts as $count) { $votes[$count->reply_id][$count->type] = $count; }
    }
    $html = '';
    foreach ($rows as $reply) {
        $owner = $owners[$reply->user_id] ?? null;
        if (!$owner) { continue; }
        $pt->is_reply_owner = IS_LOGGED && ((int) $reply->user_id === (int) $user->id || !empty($pt->video_owner));
        $pt->is_ro_verified = $owner->verified == 1;
        $html .= PT_LoadPage('watch/replies', array('ID' => $reply->id,
            'TEXT' => PT_Markup(PT_Duration($reply->text)), 'TIME' => PT_Time_Elapsed_String($reply->time),
            'USER_DATA' => $owner, 'COMM_ID' => $comment->id,
            'LIKES' => $votes[$reply->id][1]->total ?? 0, 'DIS_LIKES' => $votes[$reply->id][2]->total ?? 0,
            'LIKED' => IS_LOGGED && !empty($votes[$reply->id][1]->mine) ? 'active' : '',
            'DIS_LIKED' => IS_LOGGED && !empty($votes[$reply->id][2]->mine) ? 'active' : ''));
    }
    if ($result['page'] > 1 || $result['more']) {
        // Fetch only the public URL key, never hydrate another watch page.
        $key = $db->where('id', $comment->video_id)->getValue(T_VIDEOS, 'video_id');
        $base = PT_Link('watch/' . rawurlencode($key) . '?cl=' . (int) $comment->id . '&reply_page=');
        $html .= '<nav class="reply-pagination" aria-label="Reply pages">';
        if ($result['page'] > 1) { $html .= '<a href="' . htmlspecialchars($base . ($result['page'] - 1), ENT_QUOTES, 'UTF-8') . '#comment-' . (int) $comment->id . '">' . htmlspecialchars($lang->previous ?? 'Previous', ENT_QUOTES, 'UTF-8') . '</a> '; }
        if ($result['more']) { $html .= '<a href="' . htmlspecialchars($base . ($result['page'] + 1), ENT_QUOTES, 'UTF-8') . '#comment-' . (int) $comment->id . '">' . htmlspecialchars($lang->next ?? 'Next', ENT_QUOTES, 'UTF-8') . '</a>'; }
        $html .= '</nav>';
    }
    return $html;
}
