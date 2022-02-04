<?php

define('DOMAIN',	'');
define('IP',		'');

include "proxy.php";

$mgr = new ProxyManager(array(
	'oldhost' => DOMAIN,
	'newhost' => $_SERVER['HTTP_HOST'],
	'ip' => IP,
	// 'port' => 80,
));

$mgr->proxy();
$mgr->push();

?>