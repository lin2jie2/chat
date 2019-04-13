<?php
use Workerman\Worker;
use Workerman\Lib\Timer;

require __DIR__ . '/Workerman/Autoloader.php';

class SocketServer
{
	public function __construct($host, $count, $timeout, $name)
	{
		$this->host = $host;
		$this->count = $count;
		$this->timeout = $timeout;
		$this->name = $name;
	}

	public function run($UserAgentClass)
	{
		$this->UserAgentClass = $UserAgentClass;

		$this->server = new Worker('websocket://' . $this->host);
		$this->server->count = $this->count;
		$this->server->name = $this->name;

		$this->server->onWorkerStart = [$this, 'onStart'];
		$this->server->onWebSocketConnect = [$this, 'onWebSocketConnect'];
		$this->server->onConnect = [$this, 'onConnect'];
		$this->server->onMessage = [$this, 'onMessage'];
		$this->server->onClose = [$this, 'onClose'];

		Worker::runAll();
	}

	public function onStart()
	{
		Timer::add(1.0, function() {
			$now = time();
			$expired = $now - $this->timeout;
			foreach ($this->server->connections as $connection)
			{
				if (empty($connection->lastActiveTime))
				{
					$connection->lastActiveTime = $now;
				}
				else if ($connection->lastActiveTime < $expired)
				{
					$connection->pushClient->close();
					$connection->close();
				}
			}
		});
	}

	public function onWebSocketConnect($connection)
	{
		$connection->perMessageDeflate = false;
		if (!empty($_SERVER['HTTP_SEC_WEBSOCKET_EXTENSIONS']))
		{
			if (explode(';', $_SERVER['HTTP_SEC_WEBSOCKET_EXTENSIONS'])[0] == 'permessage-deflate')
			{
				// $connection->perMessageDeflate = true;
			}
		}
		if ($connection->perMessageDeflate)
		{
			$connection->headers = ['Sec-WebSocket-Extensions: permessage-deflate'];
		}
	}

	public function onConnect($connection)
	{
		$connection->pushClient = new PushClient($connection);
		echo "{$connection->id} connected\n";
	}

	public function onMessage($connection, $data)
	{
		echo "{$connection->id} messaged\n";
		if ($connection->perMessageDeflate)
		{
			// echo "\n\n" . strlen($data) . "\n";
			// for ($i = 0; $i < strlen($data); $i += 1) echo ord($data[$i]) . " "; echo "\n";
			$data = gzinflate(substr($data, 2, -4));	// 浏览器编码与PHP编码不一致
		}

		$payload = json_decode($data, true);
		if ($payload)
		{
			$agent = $this->UserAgentClass;
			new $agent($payload, $connection);
		}
		$connection->lastActiveTime = time();
	}

	public function onClose($connection)
	{
		$connection->pushClient->close();
	}
}
