<?php
ini_set('memory_limit', '4G');
date_default_timezone_set('Asia/Shanghai');
error_reporting(E_ALL);

define('PUSH_SERVER_HOST', '127.0.0.1:44444');

require __DIR__ . '/SocketServer.php';
require __DIR__ . '/PushServer.php';
require __DIR__ . '/PushClient.php';
require __DiR__ . '/UserAgent.php';

require __DIR__ . '/ChatUserAgent.php';

// setup
if (!in_array('quick', $argv))
{
	$chatUserAgent = new ChatUserAgent([null, null, null, null], null);
	$chatUserAgent->setup();
}

$pushServer = new PushServer();
$socketServer = new SocketServer('0.0.0.0:55555', 4, 30, 'ChatServer');
$socketServer->run('ChatUserAgent');
