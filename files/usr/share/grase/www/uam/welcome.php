<?php

require_once('includes/site.inc.php');



//
$loginurl = parse_url($_GET['loginurl']);
$query = $loginurl['query'];
parse_str($query, $uamopts);

if(isset($_GET['loginurl'])){
	$loginlink = "http://10.1.0.1/grase/uam/mini?$query";
	$loginlink2 = $_GET['loginurl'];
}else{
	$loginlink = "http://10.1.0.1/grase/uam/mini";
	$loginlink2 = "http://10.1.0.1:3990/prelogin";
}

$smarty->assign("user_url", $uamopts['userurl']);

$smarty->assign("loginlink", $loginlink);
$smarty->assign("loginlink2", $loginlink2);
$smarty->assign("RealHostname", trim(file_get_contents('/etc/hostname')));

if(isset($_GET['help'])){
	$smarty->display('help.tpl');
}else{
	$smarty->display('welcome.tpl');
}

?>

