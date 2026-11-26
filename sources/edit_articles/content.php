<?php
if (IS_LOGGED == false || $pt->config->all_create_articles != 'on') {
    header("Location: " . PT_Link('login'));
    exit();
}
if (empty($_GET['id'])) {
    header("Location: " . PT_Link('login'));
    exit();
}
$_GET['id'] = strip_tags($_GET['id']);
$id    = PT_Secure($_GET['id']);
$article = $db->where('id', $id)->where('active', '1')->getOne(T_POSTS);
if (empty($article) || $article->user_id != $pt->user->id) {
    header("Location: " . PT_Link(''));
    exit();
}



$pt->page_url_ = $pt->config->site_url.'/edit_articles/'.$id;
$pt->article       = $article;
$pt->page        = 'edit_articles';
$pt->title       = $lang->edit_article . ' | ' . $pt->config->title;
$pt->description = $pt->config->description;
$pt->keyword     = $pt->config->keyword;
$pt->content     = PT_LoadPage('edit_articles/content',array(
    'ID' => $article->id,
    'TITLE' => htmlspecialchars((string) (PT_EncodeArticleTemplateBraces($article->title)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false),
    'DESC' => htmlspecialchars((string) (PT_EncodeArticleTemplateBraces($article->description)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false),
    'IMAGE' => htmlspecialchars((string) (PT_EncodeArticleTemplateBraces(PT_GetMedia($article->image))), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false),
    'TEXT' => htmlspecialchars((string) (PT_SanitizeArticleHtml(PT_Decode($article->text))), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true),
    'TIME' => date('F/m/Y h:i',$article->time),
    'VIEWS' => number_format($article->views),
    'SHARED' => number_format($article->shared),
    'CATEGORY_ID' => $article->category,
    'TAGS' => htmlspecialchars((string) (PT_EncodeArticleTemplateBraces($article->tags)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false),
    'POST_ENCODED_URL' => urlencode(PT_Link('articles/read/' . PT_URLSlug($article->title,$article->id))),
));