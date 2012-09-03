<?php
/**
 * DBase (MySQLi client)
 *
 * A database abstraction layer allowing the use of many common database client APIs.
 *
 * Note that $DB_TYPE is not used with this implementation - only MySQL is available.
 *
 * @package 	pWebFramework
 * @author 		Sam Pospischil <pospi@spadgos.com>
 * @since		15/8/2012
 * @requires	MySQLi library
 * @requires	DBase
 */

class DBase_mysqli extends DBase
{
	public function __connect($user, $pass, $dbName, $host = 'localhost', $port = null)
	{
		$this->conn = mysqli_connect($host, $user, $pass, $dbName, $port);
	}

	// detect invalid arguments
	public function setConnection($conn)
	{
		if (!$conn instanceof mysqli) {
			trigger_error("Invalid type for provided database connection handle", E_USER_ERROR);
		}
		parent::setConnection($conn);
	}

	// allow reconnection via ping() function
	public function reconnect()
	{
		if (!$this->conn->ping()) {
			return parent::reconnect();
		}
	}

	public function __realQuery($sql)
	{
		return @$this->conn->query($sql);
	}

	public function __realExec($sql, $returnMode = null)
	{
		$result = @$this->conn->query($sql);
		return array((bool)$result, null);
	}

	public function nextRow($fetchMode = null)
	{
		if (!isset($fetchMode)) {
			$fetchMode = self::FETCH_ASSOC;
		}

		switch ($fetchMode) {
			case self::FETCH_ARRAY:
				$cb = 'fetch_row';
				break;
			case self::FETCH_ASSOC:
				$cb = 'fetch_assoc';
				break;
			case self::FETCH_OBJECT:
				$cb = 'fetch_object';
				break;
		}

		return call_user_func(array($this->lastResult, $cb));
	}

	public function quotestring($param)
	{
		if ($param === null) {
			return 'NULL';
		}
		return $this->conn->real_escape_string($param);
	}

	public function lastInsertId()
	{
		return $this->conn->insert_id;
	}

	public function affectedRows()
	{
		return $this->conn->affected_rows;
	}

	public function lastError()
	{
		return array($this->conn->errno, $this->conn->error);
	}

	public function __realStart()
	{
		return $this->conn->autocommit(false);
	}

	public function __realCommit()
	{
		$ok = $this->conn->commit();
		$this->conn->autocommit(true);
		return $ok;
	}

	public function __realRollback()
	{
		return $this->conn->rollback();
	}
}
