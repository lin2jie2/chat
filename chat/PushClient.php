<?php
use Workerman\Connection\AsyncTcpConnection;

class PushClient
{
	public $autoConnect = true;

	public function __construct($connection)
	{
		$this->connection = $connection;

		$this->connect();
	}

	public function connect()
	{
		$this->server = new AsyncTcpConnection('Text://' . PUSH_SERVER_HOST);
		$this->server->onConnect = [$this, 'onConnect'];
		$this->server->onMessage = [$this, 'onMessage'];
		$this->server->onClose = [$this, 'onClose'];

		echo "正在连接到 PushServer\n";
		$this->server->connect();
		echo "连接请求已发送\n";
	}

	public function close()
	{
		$this->autoConnect = false;
		if (!empty($this->server))
		{
			$this->server->close();
			$this->server = null;
		}
	}

	public function onConnect($connection)
	{
		echo "已连接到PushServer\n";
	}

	public function onMessage($connection, $data)
	{
		echo "收到消息\n";
		list ($action, $args) = json_decode($data, true);
		if (method_exists($this, $action))
		{
			call_user_func_array([$this, $action], $args);
		}
	}

	public function onClose($connection)
	{
		if ($this->autoConnect)
		{
			echo "断开重连\n";
			$this->connect();
		}
	}

	// 关联会员
	public function linkUser($userId)
	{
		if (empty($this->connection->userId))
		{
			$this->connection->userId = $userId;
			$this->server->send(json_encode(['setConnectionInfo', ['userId', $userId]]));
			return true;
		}
		return false;
	}

	public function unlinkUser()
	{
		if (!empty($this->connection->userId))
		{
			$this->server->send(json_encode(['setConnectionInfo', ['userId', null]]));
			$this->connection->userId = null;
			return true;
		}
		return false;
	}

	// 推送消息到客户端
	public function pushTo($userId)
	{
		$messageQueueKey = "user.{$userId}.message";
		if ($this->redis->llen($messageQueueKey) > 0)
		{
			$dat = $this->redis->lpop($messageQueueKey);
			$this->server->send(json_encode(['sendMessage', [$userId, $dat]]));
		}
	}

	// 确认推送是否成功 clients
	public function messageSended($userId, $package, $clients)
	{
		if ($clients == 0)
		{
			$messageQueueKey = "user.{$userId}.message";
			$this->redis->lpush($messageQueueKey, $package);
		}
		else
		{
			$this->pushTo($userId);
		}
	}

	// 发送消息到客户端
	public function sendMessage($package)
	{
		if ($this->connection)
		{
			if ($this->connection->perMessageDeflate)
			{
				$package = gzdeflate($package, 9, ZLIB_ENCODING_DEFLATE);
			}
			$this->connection->send($package);
			$this->connection->lastActiveTime = time();
		}
	}

	public function __get($name)
	{
		if ($name == 'redis')
		{
			$config = include __DIR__ . '/redis.config.php';
			$this->redis = new Redis();
			$this->redis->connect($config['host'], $config['port'] ?? 6379);
			$this->redis->select($config['db'] ?? 0);
			return $this->redis;
		}
	}
}
