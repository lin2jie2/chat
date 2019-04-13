<?php

class UserAgent
{
	public function __construct($payload, $connection)
	{
		$this->connection_ = $connection;

		list ($action, $arguments, $this->msgId, $this->accessToken) = $payload;
		if (method_exists($this, $action))
		{
			if ($action != 'refreshToken')
			{
				if (!$this->accessToken)
				{
					$this->send_client(false, 'refreshToken', '请先获取会话');
					return;
				}

				$reflector = new ReflectionClass($this);
				$doc = $reflector->getMethod($action)->getDocComment();
				if (strpos($doc, '@authorize') !== false)
				{
					$this->userId = $this->find_user_by_token($this->accessToken);
					if ($this->userId == 0)
					{
						$this->send_client(false, 'signIn', '请先登录');
						return;
					}

					$this->link_connection($this->userId, $this->connection_);
				}
			}

			call_user_func_array([$this, $action], $arguments);
		}
	}

	/**
	 * Access Token
	 */
	public function refreshToken()
	{
		$userId = null;
		if ($this->accessToken)
		{
			$userId = $this->find_user_by_token($this->accessToken);
			$this->remove_user_token($this->accessToken);
		}
		$token = md5(microtime(true));
		$this->send_client(true, 'refreshToken', $token);
		if ($userId)
		{
			$this->set_user_token($userId, $token);
		}
	}

	/**
	 * 保持连接
	 */
	public function keepAlive()
	{
		if ($this->accessToken)
		{
			$this->userId = $this->find_user_by_token($this->accessToken);
			if ($this->userId)
			{
				$this->link_connection($this->userId, $this->connection_);
			}
		}
	}

	/**
	 * 注册
	 */
	public function signUp($username, $password)
	{
		if ($password == '')
		{
			$this->send_client(false, 'signUp', '请输入登录密码');
			return;
		}

		$username = $this->verifyAccount_($username, 'signUp');
		if (!$username)
		{
			return;
		}
		
		$salt = substr(md5(microtime(true)), 7, 8);
		$userId = $this->database->insert('user', ['username' => $username, 'salt' => $salt, 'password' => md5(md5($password) . $salt)]);
		if (!$userId)
		{
			$this->send_client(false, 'signUp', '注册失败');
			return;
		}

		$this->redis->hset('user', $userId, json_encode(['id' => $userId, 'username' => $username, 'title' => '', 'avatar' => null, 'bio' => '', 'type' => 1, 'owner' => 0, 'verified' => 0]));
		$this->send_client(true, 'signUp', '注册成功');

		$this->set_user_token($userId, $this->accessToken);
		$this->link_connection($userId, $this->connection_);
	}

	// 验证帐号
	protected function verifyAccount_($username, $action)
	{
		$username = trim($username);
		if (!preg_match('/^[a-zA-Z0-9]{5,30}$/', $username))
		{
			$this->send_client(false, $action, '请输入 5-30 位 英文数字组合');
			return false;
		}
		$exists = $this->database->find("SELECT id FROM user WHERE username = ?", [$username]);
		if ($exists)
		{
			$this->send_client(false, $action, '帐号已存在');
			return;
		}
		return $username;
	}
	
	// 验证名称
	protected function verifyTitle_($title, $errorMessage, $action)
	{
		$title = trim($title);
		if (!$title)
		{
			$this->send_client(false, $action, $errorMessage);
			return false;
		}
		return $title;
	}

	/**
	 * 登录
	 */
	public function signIn($username, $password)
	{
		$username = trim($username);
		if (!$username)
		{
			$this->send_client(false, 'signIn', '请输入账号');
			return;
		}
		if (!$password)
		{
			$this->send_client(false, 'signIn', '请输入密码');
			return;
		}
		$user = $this->database->find("SELECT id, salt, password FROM user WHERE username = ?", [$username]);
		if (!$user)
		{
			$this->send_client(false, 'signIn', '账号不存在');
			return;
		}
		if (md5(md5($password) . $user['salt']) != $user['password'])
		{
			$this->send_client(false, 'signIn', '密码错误');
			return;
		}

		$this->send_client(true, 'signIn', 'OK');

		$userId = $user['id'];
		$this->set_user_token($userId, $this->accessToken);
		$this->link_connection($userId, $this->connection_);
	}

	/**
	 * 退出
	 * @authorize
	 */
	public function signOut()
	{
		$this->remove_user_token($this->accessToken);
		$this->unlink_connection($this->connection_);

		$this->send_client(true, 'signOut', null);
	}

	/**
	 * 通信
	 */
	protected function send_client($successded, $action, $data = null)
	{
		$dat = json_encode([$action, [$successded, $data]]);
		if ($this->connection_->perMessageDeflate)
		{
			$dat = gzdeflate($dat, 9, ZLIB_ENCODING_DEFLATE);
		}
		$this->connection_->send($dat);
	}

	// 关联会员
	protected function link_connection($userId, $connection)
	{
		$link = $connection->pushClient->linkUser($userId);
		if ($link)
		{
			$this->push_message($userId);
		}
	}

	// 解除会员关联
	protected function unlink_connection($connection)
	{
		$connection->pushClient->unlinkUser();
	}

	// 通过Token查找用户ID
	protected function find_user_by_token($accessToken)
	{
		return intval($this->redis->hget('user.token', $accessToken));
	}

	// 更新用户Token
	protected function set_user_token($userId, $accessToken)
	{
		$this->redis->hset('user.token', $accessToken, $userId);
	}

	// 删除用户Token
	protected function remove_user_token($accessToken)
	{
		$this->redis->hdel('user.token', $accessToken);
	}

	// 发送到对方邮箱
	protected function send_to($userId, $package)
	{
		$messageQueueKey = "user.{$userId}.message";
		$this->redis->rpush($messageQueueKey, $package);
	}

	// 向用户推送消息
	protected function push_message($userId)
	{
		$this->connection_->pushClient->pushTo($userId);
	}

	// 转存到数据库
	protected function dump_message($action, $query, $data = [])
	{
		// $this->database->$action('message', $package);
		var_dump($package);
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

		if ($name == 'database')
		{
			$dbconfig = include __DIR__ . '/database.config.php';
			$this->database = new DataService($dbconfig);
			return $this->database;
		}
	}
}
