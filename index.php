<?php

define('DOMAIN',	'');
define('IP',		'');

include "proxy.php";

$mgr = new ProxyManager(array(
	'oldhost' => DOMAIN,
	'newhost' => $_SERVER['HTTP_HOST'],
	'ip' => IP,
	'secure' => null, // true / false enforces http(s) on proxied end
	// 'port' => 80,
));

$mgr->proxy();
$mgr->push();

?>