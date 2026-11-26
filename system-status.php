<?php
require_once('assets/init.php');
if (!defined('IS_LOGGED') || !IS_LOGGED || !PT_IsAdmin()) {
    http_response_code(403);
    exit('Administrator access required');
}
$getStatus = getStatus(['curl' => true, "nodejsport" => true, "htaccess" => true]);
?>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>System Status</title>
	<link rel="stylesheet" href="<?php echo $pt->config->theme_url;?>/css/style.css">

</head>
<body>
	<div class="container content-container">
		<ul style="list-style: circle;">
	<?php if (!empty($getStatus)) { ?> 
        <?php
        foreach ($getStatus as $key => $value) {?>
            <li style="margin-bottom: 20px;"><?php echo ($value["type"] == "error") ? '<strong style="color: red">Important!</strong>' : '<strong style="color: #f98f1d">Warning:</strong>';?> <?php echo $value["message"];?></li>
    <?php }} else { ?>
        <li>All good, no issues found.</li>
    <?php } ?>
    </ul>
	</div>
</body>
</html>