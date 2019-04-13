<?php

class ChatUserAgent extends UserAgent
{
	public function __construct($payload, $connection)
	{
		parent::__construct($payload, $connection);
	}
	
	// 设置缓存
	public function setup()
	{
		foreach ($this->database->findAll("SELECT id, username, title, avatar, bio, type, owner, verified FROM user") as $row)
		{
			$this->redis->hset("user", $row['id'], json_encode($row));
		}

		foreach ($this->database->findAll("SELECT userId, subscriberId, status, type FROM user_subscriber") as $row)
		{
			$this->redis->hset("user.{$row['userId']}.subscriber", $row['subscriberId'], json_encode(['status' => $row['status'], 'type' => $row['type']]));
		}
	}

	/**
	 * 设置名称
	 * @authorize
	 */
	public function setTitle($targetId, $title)
	{
		if ($this->verifyTitle_($title, '请输入名称', 'setTitle'))
		{
			$this->updateProfile_($this->userId, $targetId ?? $this->userId, 'title', $title, 'setTitle');
		}
	}

	/**
	 * 设置头像
	 * @authorize
	 */
	public function setAvatar($targetId, $avatar)
	{
		$avatar = trim($avatar);
		$this->updateProfile_($this->userId, $targetId ?? $this->userId, 'avatar', $avatar, 'setAvatar');
	}

	/**
	 * 设置简介
	 * @authorize
	 */
	public function setBio($targetId, $bio = '')
	{
		$bio = trim($bio);
		$this->updateProfile_($this->userId, $targetId ?? $this->userId, 'bio', $bio, 'setBio');
	}

	// 更新用户资料
	protected function updateProfile_($userId, $targetId, $field, $value, $action)
	{
		$own = $userId == $targetId or $this->database->find("SELECT 1 FROM user WHERE id = ? AND owner = ?", [$targetId, $userId]);
		if (!$own)
		{
			$this->send_to($userId, json_encode([$action, [false, '无权设置']]));
			$this->push_message($userId);
			return;
		}

		$successded = $this->database->update("UPDATE user SET {$field} = ? WHERE id = ?", [$value, $targetId]);
		if ($successded)
		{
			$profile = $this->database->find("SELECT id, username, title, avatar, bio, type, owner, verified FROM user WHERE id = ?", [$targetId]);
			$this->redis->hset("user", $targetId, json_encode($profile));

			$this->send_to($userId, json_encode([$action, [true, '设置成功']]));
		}
		else
		{
			$this->send_to($userId, json_encode([$action, [false, '设置失败']]));
		}
		$this->push_message($userId);
	}

	/**
	 * 用户资料
	 * @authorize
	 */
	public function userInfo($targetId = null)
	{
		$userId = $this->userId;
		$userInfo = $this->redis->hget("user", $targetId ?? $userId);
		$this->send_to($userId, json_encode(['userInfo', [true, json_decode($userInfo, true)]]));
		$this->push_message($userId);
	}

	/**
	 * 会话列表
	 * @authorize
	 */
	public function chatList()
	{
		$userId = $this->userId;
		$list = $this->redis->hgetall("user.{$userId}.subscriber");
		$this->send_to($userId, json_encode(['chatList', [true, $list]]));
		$this->push_message($userId);
	}

	/**
	 * 最新消息
	 * @authorize
	 */
	public function latestMessage($targetId)
	{
		$userId = $this->userId;
		$target = $this->redis->hget("user.{$userId}.subscriber", $targetId);
		if (!$target)
		{
			$this->send_to($userId, json_encode(['latestMessage', [false, '没有交集']]));
			$this->push_message($userId);
			return;
		}

		$message = $this->redis->hget("user.latest.message", ($target['type'] == 1 || $target['type'] == 4) ? $targetId . ':' . $userId : $targetId);
		$data = ['latestMessage', [true, ['targetId' => $targetId, 'message' => $message]]];
		$this->send_to($userId, json_encode($data));
		$this->push_message($userId);
	}

	/**
	 * 历史记录
	 * @authorize
	 */
	public function historyMessage($targetId, $sinceId, $count)
	{
		$userId = $this->userId;
		$target = $this->redis->hget("user.{$userId}.subscriber", $targetId);
		if (!$target)
		{
			$this->send_to($userId, json_encode(['historyMessage', [false, '没有交集']]));
			$this->push_message($userId);
			return;
		}

		$messages = [];
		if ($target['type'] == 1 || $target['type'] == 4)
		{
			$sql = "SELECT * FROM message WHERE id < ? AND receiverId = ? ORDER BY id DESC LIMIT ?";
			$messages = $this->database->findAll($sql, [$sinceId, $targetId, $count]);
		}
		else
		{
			$sql = "SELECT * FROM message WHERE id < ? AND ((receiverId = ? AND senderId = ?) OR (receiverId = ? AND senderId = ?)) ORDER BY id DESC LIMIT ?";
			$messages = $this->database->findAll($sql, [$sinceId, $targetId, $userId, $userId, $targetId, $count]);
		}
		$package = ['historyMessage', [true, ['targetId' => $targetId, 'sinceId' => $sinceId, 'messages' => $messages]]];
		$this->send_to($userId, json_encode($package));
		$this->push_message($userId);
	}

	/**
	 * 发送消息
	 * @authorize
	 */
	public function sendMessage($receiverId, $message)
	{
		$this->sendMessage_($this->userId, $receiverId, 1, $message, $this->msgId);
	}

	// 发送消息
	protected function sendMessage_($senderId, $receiverId, $type, $message, $msgId)
	{
		// 目标不存在
		$target = $receiverId ? json_decode($this->redis->hget("user", $receiverId) ?? 'null', true) : null;
		if (!$target)
		{
			// echo "目标({$receiverId})不存在\n";
			$this->send_to($senderId, json_encode(['sendMessage', [false, '对方不存在']]));
			$this->push_message($senderId);
			return;
		}

		$data = ['senderId' => $senderId, 'receiverId' => $receiverId, 'type' => $type, 'content' => $message, 'ctime' => time()];
		$this->database->insert('message', $data);
		$package = json_encode(['sendMessage', [true, $data]]);

		// 发给对方
		$subscriberKey = "user.{$receiverId}.subscriber";
		$status = json_decode($this->redis->hget($subscriberKey, $senderId) ?? 'null', true);

		// 个人
		if ($target['type'] == 1)
		{
			// 发给自己
			// echo "发给自己({$receiverId})\n";
			$this->send_to($senderId, $package);
			$this->push_message($senderId);
			$this->redis->hset("user.latest.message", $receiverId . ':' . $senderId, $package); // 给自己看

			// 发给对方
			if ($status == null)
			{
				// 相互关注
				$this->redis->hset("user.${senderId}.subscriber", $receiverId, json_encode(['status' => 1, 'type' => 2]));
				$this->redis->hset($subscriberKey, $senderId, json_encode(['status' => 1, 'type' => 2]));
				$r = $this->database->insert('user_subscriber', ['userId' => $receiverId, 'subscriberId' => $senderId, 'status' => 1, 'type' => 2]);
				$r = $this->database->insert('user_subscriber', ['userId' => $senderId, 'subscriberId' => $receiverId, 'status' => 1, 'type' => 2]);

				// echo "发给对方并({$receiverId})\n";
				$this->send_to($receiverId, $package);
				$this->push_message($receiverId);
				$this->redis->hset("user.latest.message", $senderId . ':' . $receiverId, $package); // 给对方看
			}
			else if ($status['status'] == 1)
			{
				// echo "发给对方({$receiverId})\n";
				$this->send_to($receiverId, $package);
				$this->push_message($receiverId);
				$this->redis->hset("user.latest.message", $senderId . ':' . $receiverId, $package); // 给对方看
			}
			return;
		}

		// 频道
		if ($target['type'] == 2)
		{
			if (!$status)
			{
				// echo "不在频道({$receiverId})里\n";
				$this->send_to($senderId, json_encode(['sendMessage', [false, '不在频道里']]));
				$this->push_message($senderId);
				return;
			}
			if ($status['type'] == 1)
			{
				// echo "不是频道({$receiverId})管理员\n";
				$this->send_to($senderId, json_encode(['sendMessage', [false, '您不是管理员']]));
				$this->push_message($senderId);
				return;
			}
			$subscribers = $this->redis->hgetall($subscriberKey);
			if ($subscribers)
			{
				foreach ($subscribers as $userId => $status)
				{
					if ($status == 1)
					{
						$this->send_to($userId, $package);
					}
				}
				foreach ($subscribers as $userId => $status)
				{
					if ($status == 1)
					{
						$this->push_message($userId);
					}
				}
			}
			$this->redis->hset("user.latest.message", $receiverId, $package);
			return;
		}

		// 群组
		if ($target['type'] == 3)
		{
			if (!$status)
			{
				// echo "不在群组({$receiverId})里\n";
				$this->send_to($senderId, json_encode(['sendMessage', [false, '不在群组里']]));
				$this->push_message($senderId);
				return;
			}
			if ($status['status'] == 2)
			{
				// echo "被群组({$receiverId})禁言\n";
				$this->send_to($senderId, json_encode(['sendMessage', [false, '您被禁言']]));
				$this->push_message($senderId);
				return;
			}
			if ($status['status'] == 3)
			{
				// echo "被群组({$receiverId})踢人\n";
				$this->send_to($senderId, json_encode(['sendMessage', [false, '您不该在这里']]));
				$this->push_message($senderId);
				return;
			}
			$subscribers = $this->redis->hgetall($subscriberKey);
			if ($subscribers)
			{
				foreach ($subscribers as $userId => $status)
				{
					$subscriberKey[$userId] = $status = json_decode($status);
					if ($status['type'] == 1)
					{
						$this->send_to($userId, $package);
					}
				}
				foreach ($subscribers as $userId => $status)
				{
					if ($status['type'] == 1)
					{
						$this->push_message($userId);
					}
				}
			}
			$this->redis->hset("user.latest.message", $receiverId, $package);
			return;
		}
	}

	/**
	 * 创建群组
	 * @authorize
	 */
	public function createGroup($username, $title)
	{
		$title = $this->verifyTitle_($title, '请输入群组名称', 'createGroup');
		if (!$title)
		{
			return;
		}
		
		$username = $this->verifyAccount_($username, 'createGroup');
		if (!$username)
		{
			return;
		}

		$accountType = 3;
		$accountId = $this->database->insert('user', ['username' => $username, 'salt' => '0000000', 'password' => '00000000000000000000000000000000', 'type' => $accountType, 'title' => $title, 'bio' => '', 'avatar' => null, 'owner' => $this->userId, 'verified' => 0]);
		if ($accountId)
		{
			$data = ['id' => $accountId, 'type' => $accountType, 'username' => $username, 'title' => $title, 'bio' => '', 'avatar' => null, 'owner' => $this->userId, 'verified' => 0];
			$this->redis->hset('user', json_encode($data));
			$this->send_to($this->userId, json_encode(['createGroup', [true, $data]]));
			return;
		}
		
		$this->send_client(false, 'createGroup', '群组创建失败');
	}

	/**
	 * 创建频道
	 * @authorize
	 */
	public function createChannel($username, $title)
	{
		$title = $this->verifyTitle_($title, '请输入频道名称', 'createChannel');
		if (!$title)
		{
			return;
		}
		
		$username = $this->verifyAccount_($username, 'createChannel');
		if (!$username)
		{
			return;
		}
		
		$accountType = 2;
		
		$accountId = $this->database->insert('user', ['username' => $username, 'salt' => '0000000', 'password' => '00000000000000000000000000000000', 'type' => $accountType, 'title' => $title, 'bio' => '', 'avatar' => null, 'owner' => $this->userId, 'verified' => 0]);
		if ($accountId)
		{
			$data = ['id' => $accountId, 'type' => $accountType, 'username' => $username, 'title' => $title, 'bio' => '', 'avatar' => null, 'owner' => $this->userId, 'verified' => 0];
			$this->redis->hset('user', json_encode($data));
			$this->send_to($this->userId, json_encode(['createChannel', [true, $data]]));
			return;
		}
		
		$this->send_client(false, 'createChannel', '频道创建失败');
	}
	
	/**
	 * 删除群组
	 * @authorize
	 */
	public function deleteGroup($groupId)
	{
		//
	}
	
	/**
	 * 删除频道
	 * @authorize
	 */
	public function deleteChannel($channelId)
	{
		//
	}

	/**
	 * 加入群组
	 * @authorize
	 */
	public function joinGroup($groupId)
	{
		// subscribe($targetUserId)
	}
	
	/**
	 * 加入频道
	 * @authorize
	 */
	public function subscribeChannel($channelId)
	{
		// subscribe($targetUserId)
	}

	/**
	 * 退出群组
	 * @authorize
	 */
	public function exitGroup($groupId)
	{
		// unsubscribe($targetUserId)
	}
	
	/**
	 * 退出频道
	 * @authorize
	 */
	public function unsubscribeChannel($channelId)
	{
		// unsubscribe($targetUserId)
	}
}