<?php
use Workerman\Worker;

class PushServer
{
	public function __construct()
	{
		$this->server = new Worker("Text://" . PUSH_SERVER_HOST);
		$this->server->count = 1;
		$this->server->name = 'PushServer';
		$this->server->onConnect = [$this, 'onConnect'];
		$this->server->onMessage = [$this, 'onMessage'];
	}

	public function run()
	{
		Worker::runAll();
	}

	public function onConnect($connection)
	{
		echo "收到来自 PushClient 的连接请求\n";
	}

	public function onMessage($connection, $data)
	{
		echo "收到 PushClient 消息\n";
		list ($action, $args) = json_decode($data, true);
		if (method_exists($this, $action))
		{
			call_user_func_array([$this, $action], array_merge([$connection], $args));
		}
	}

	public function setConnectionInfo($connection, $key, $value)
	{
		$connection->$key = $value;
	}

	public function sendMessage($connection, $userId, $package)
	{
		$clients = 0;
		foreach ($this->server->connections as $conn)
		{
			if (!empty($conn->userId) && $conn->userId == $userId)
			{
				$conn->send(json_encode(['sendMessage', [$package]]));
				$clients += 1;
			}
		}
		$connection->send(json_encode(['messageSended', [$userId, $package, $clients]]));
	}

	public function broadcast($connection, $action, $args)
	{
		foreach ($this->server->connections as $conn)
		{
			$conn->send(json_encode([$action, $args]));
		}
	}
}
