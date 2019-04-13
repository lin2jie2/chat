<?php
class DataService {
	public $errorInfo = null;

	private $pdo = null;
	private $stmt = null;

	public function __construct($conf, $persit = false) {
		$this->conf = $conf;
		$this->persit = $persit;
		$this->init();
	}

	public function init()
	{
		$conf = $this->conf;
		$persit = $this->persit;

		$dsn = "mysql:host={$conf['host']};port={$conf['port']};dbname={$conf['dbname']};charset={$conf['charset']}";
		$attrs = [];
		if ($persit)
		{
			$attrs[PDO::ATTR_PERSISTENT] = true;
		}
		if (isset($conf['timeout']) && $conf['timeout'] > 0)
		{
			$attrs[PDO::ATTR_TIMEOUT] = $conf['timeout'];
		}
		$this->pdo = new PDO($dsn, $conf['user'], $conf['password'], $attrs);
	}

	public function keepAlive()
	{
		try
		{
			$this->pdo->query('SELECT 1');
			$error = $this->pdo->errorInfo();
			if ($error[2] == 'MySQL server has gone away')
			{
				$this->init();
			}
		}
		catch (PDOException $e)
		{
			$this->init();
		}
	}

	public function __destruct()
	{
		if ($this->pdo)
		{
			$this->pdo = null;
		}
	}

	public function findAll($sql, $values = []) {
		if ($this->query($sql, $values)) {
			$metas = $this->getMetas();
			$rows = [];
			foreach ($this->stmt->fetchAll(PDO::FETCH_NUM) as $row) {
				$r = [];
				foreach ($row as $index => $v) {
					$r[$metas[$index]['name']] = $this->php_value($v, $metas[$index]['native_type']);
				}
				$rows[] = $r;
			}
			return $rows;
		}
		else {
			return false;
		}
	}

	public function php_value($value, $native_type) {
		switch ($native_type) {
			case 'LONG':
			case 'TINY':
				return intval($value);
			case 'VAR_STRING':
				return $value;
			default:
				return $value;
		}
	}

	public function find($sql, $values = []) {
		if ($this->query($sql, $values)) {
			$t = $this->stmt->fetch(PDO::FETCH_NUM);
			if (!$t) {
				return false;
			}
			$metas = $this->getMetas();
			$row = [];
			foreach ($t as $index => $v) {
				$row[$metas[$index]['name']] = $this->php_value($v, $metas[$index]['native_type']);
			}
			return $row;
		}
		else {
			return false;
		}
	}

	private function getMetas() {
		$columns = [];
		for ($cols = $this->stmt->columnCount(), $i = 0; $i < $cols; $i += 1) {
			$columns[] = $this->stmt->getColumnMeta($i);
		}
		return $columns;
	}

	public function count($sql, $values = []) {
		$success = $this->query($sql, $values);
		if ($success) {
			foreach ($this->stmt->fetch(PDO::FETCH_ASSOC) as $field => $value) {
				return intval($value);
			}
		}
		else {
			return false;
		}
	}

	public function insert($table, $set) {
		$fields = [];
		$values = [];
		$q = [];
		foreach ($set as $field => $value) {
			$fields[] = $field;
			$values[] = $value;
			$q[] = '?';
		}
		$fields = implode('`, `', $fields);
		$q = implode(', ', $q);
		$sql = "INSERT INTO {$table}(`{$fields}`) VALUES({$q})";
		if ($this->query($sql, $values)) {
			return $this->pdo->lastInsertId();
		}
		else {
			return false;
		}
	}

	public function batchInsert($table, $sets, $duplicate = '', $ignore = '')
	{
		$fields = [];
		$values = [];
		$qs = [];
		foreach ($sets as $index => $set)
		{
			$q = [];
			foreach ($set as $field => $value)
			{
				if ($index == 0)
				{
					$fields[] = $field;
				}
				$q[] = '?';
				$values[] = $value;
			}
			$qs[] = '(' . implode(",", $q) . ')';
		}
		$fields = implode('`, `', $fields);
		$sql = "INSERT {$ignore} INTO {$table}(`{$fields}`) VALUES". implode(",", $qs) . ' ' . $duplicate;
		if ($this->query($sql, $values))
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	public function update($sql, $values = []) {
		$success = $this->query($sql, $values);
		if($success) {
			$rows = $this->stmt->rowCount();
			return $rows > 0 ? $rows : true;
		} else {
			return false;
		}
	}

	public function updateTable($table, $sets, $id) {
		$a = [];
		$b = [];
		foreach ($sets as $k => $v) {
			$a[] = "`{$k}` = ? ";
			$b[] = $v;
		}
		$sql = "UPDATE {$table} SET " . implode(',', $a) . " WHERE id = ?";
		$b[] = $id;
		return $this->update($sql, $b);
	}

	public function delete($sql, $values = []) {
		$success = $this->query($sql, $values);
		if($success) {
			$rows = $this->stmt->rowCount();
			return $rows > 0 ? $rows : true;
		} else {
			return false;
		}
	}

	public function transaction() {
		return $this->pdo->beginTransaction();
	}

	public function commit() {
		return $this->pdo->commit();
	}

	public function rollback() {
		return $this->pdo->rollBack();
	}

	public function query($sql, $values = []) {
		$this->keepAlive();

		$this->stmt = $this->pdo->prepare($sql);
		if($this->stmt) {
			foreach($values as $index => $value) {
				$this->stmt->bindValue($index + 1, $value, $this->getDataType($value));
			}
			if($this->stmt->execute()) {
				$this->errorInfo = null;
				return true;
			} else {
				$this->errorInfo = $this->stmt->errorInfo();
				return false;
			}
		} else {
			$this->errorInfo = $this->pdo->errorInfo();
			return false;
		}
	}

	public function getList($sql_list, $values, $page, $pagesize) {
		$page = intval($page);
		$pagesize = intval($pagesize);
		$sql_count = preg_replace('/^(.+)(order\s+.+)$/is', '${1}', preg_replace('/^(select\s+)(.+)(\s+from\s+.+)$/is', '${1} COUNT(*) ${3}', $sql_list));
		$total = $this->count($sql_count, $values);
		$pages = ceil($total / $pagesize);
		if ($page > $pages) {
			$page = $pages;
		}
		if ($page < 1) {
			$page = 1;
		}
		$from = $page * $pagesize - $pagesize;
		$values[] = $pagesize;
		$list = $this->findAll($sql_list . " LIMIT {$from}, ?", $values);
		return [
			'page' => intval($page),
			'pages' => $pages,
			'total' => $total,
			'pagesize' => intval($pagesize),
			'items' => $list,
		];
	}

	private function getDataType($value) {
		if (is_null($value)) {
			return PDO::PARAM_NULL;
		}
		else if (is_bool($value)) {
			return PDO::PARAM_BOOL;
		}
		else if (is_int($value)) {
			return PDO::PARAM_INT;
		}
		else if (is_double($value)) {
			// return PDO::PARAM_NUM;
			return PDO::PARAM_STR;
		}
		else {
			return PDO::PARAM_STR;
		}
	}
}
