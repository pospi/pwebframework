<?php
/**
 * DBase (MySQL legacy client)
 *
 * A database abstraction layer allowing the use of many common database client APIs.
 *
 * Note that $DB_TYPE is not used with this implementation - only MySQL is available.
 *
 * @package 	pWebFramework
 * @author 		Sam Pospischil <pospi@spadgos.com>
 * @since		15/8/2012
 * @requires	MySQL library
 * @requires	DBase
 */

class DBase_mysql extends DBase
{
	public function __connect($user, $pass, $dbName, $host = 'localhost', $port = null)
	{
		$this->conn = mysql_connect($host . ($port ? ":$port" : ''), $user, $pass);
		mysql_select_db($dbName, $this->conn);
	}

	// detect invalid arguments
	public function setConnection($conn)
	{
		if (!is_resource($conn) || get_resource_type($conn) != 'mysql link') {
			trigger_error("Invalid type for provided database connection handle", E_USER_ERROR);
		}
		parent::setConnection($conn);
	}

	// allow reconnection via ping() function
	public function reconnect()
	{
		if (!mysql_ping($this->conn)) {
			return parent::reconnect();
		}
	}

	public function __realQuery($sql)
	{
		return @mysql_query($sql, $this->conn);
	}

	public function __realExec($sql, $returnMode = null)
	{
		return array(@mysql_query($sql, $this->conn) !== false, null);
	}

	public function nextRow($fetchMode = null)
	{
		if (!isset($fetchMode)) {
			$fetchMode = self::FETCH_ASSOC;
		}

		switch ($fetchMode) {
			case self::FETCH_ARRAY:
				$cb = 'mysql_fetch_row';
				break;
			case self::FETCH_ASSOC:
				$cb = 'mysql_fetch_assoc';
				break;
			case self::FETCH_OBJECT:
				$cb = 'mysql_fetch_object';
				break;
		}

		return call_user_func($cb, $this->lastResult);
	}

	public function quotestring($param)
	{
		if ($param === null) {
			return 'NULL';
		}
		return "'" . mysql_real_escape_string($param, $this->conn) . "'";
	}

	public function lastInsertId()
	{
		return mysql_insert_id($this->conn);
	}

	public function affectedRows()
	{
		return mysql_affected_rows($this->conn);
	}

	public function lastError()
	{
		return array(mysql_errno($this->conn), mysql_error($this->conn));
	}

	public function __realStart()
	{
		return false !== @mysql_query("BEGIN", $this->conn);
	}

	public function __realCommit()
	{
		return false !== @mysql_query("COMMIT", $this->conn);
	}

	public function __realRollback()
	{
		return false !== @mysql_query("ROLLBACK", $this->conn);
	}

	public function __call($method, $args)
	{
		array_push($args, $this->conn);
		return call_user_func_array('mysql_' . $method, $args);
	}

	public function __get($param)
	{
		$info = mysql_info($this->conn);
		return isset($info[$param]) ? $info[$param] : null;
	}
}
