<?php
/**
 * DBase (PDO client)
 *
 * A database abstraction layer allowing the use of many common database client APIs.
 *
 * This class is built for MySQL, but there's no reason most of it won't work with
 * other database engines.
 * You can change the class variable $DB_TYPE to set a different database engine
 * to use with the underlying PDO handle.
 *
 * @package 	pWebFramework
 * @author 		Sam Pospischil <pospi@spadgos.com>
 * @since		15/8/2012
 * @requires	PDO (PHP Data Objects)
 * @requires	DBase
 */

class DBase_pdo extends DBase
{
	private $lastAffectedRows;	// last operation's affected rowcount

	public function __connect($user, $pass, $dbName, $host = 'localhost', $port = null)
	{
		$this->conn = new PDO($this->DB_TYPE . ":dbname={$dbName};host={$host}", $user, $pass);
	}

	// detect invalid arguments
	public function setConnection($conn)
	{
		if (!$conn instanceof PDO) {
			trigger_error("Invalid type for provided database connection handle", E_USER_ERROR);
		}
		parent::setConnection($conn);
	}

	public function __realQuery($sql)
	{
		$this->lastAffectedRows = 0;
		try {
			$result = $this->conn->query($sql);
		} catch (PDOException $e) {
			$result = false;
		}
		return $result;
	}

	public function __realExec($sql, $returnMode = null)
	{
		try {
			$affectedRows = $this->conn->exec($sql);
		} catch (PDOException $e) {
			$affectedRows = false;
		}
		$result = $affectedRows !== false;
		$this->lastAffectedRows = (int)$affectedRows;

		return array($result, (int)$affectedRows);
	}

	public function nextRow($fetchMode = null, $result = null)
	{
		if (!isset($fetchMode)) {
			$fetchMode = self::FETCH_ASSOC;
		}

		switch ($fetchMode) {
			case self::FETCH_ARRAY:
				$fetchMode = PDO::FETCH_NUM;
				break;
			case self::FETCH_ASSOC:
				$fetchMode = PDO::FETCH_ASSOC;
				break;
			case self::FETCH_OBJECT:
				$fetchMode = PDO::FETCH_OBJ;
				break;
		}

		if ($result) {
			return $result->fetch($fetchMode);
		}
		return $this->lastResult->fetch($fetchMode);
	}

	public function quotestring($param)
	{
		if ($param === null) {
			return 'NULL';
		}
		return $this->conn->quote($param);
	}

	public function lastInsertId()
	{
		return $this->conn->lastInsertId();
	}

	public function affectedRows()
	{
		return $this->lastAffectedRows;
	}

	public function lastError()
	{
		$err = $this->conn->errorInfo();
		return array($err[1], $err[2]);
	}

	public function __realStart()
	{
		return $this->conn->beginTransaction();
	}

	public function __realCommit()
	{
		return $this->conn->commit();
	}

	public function __realRollback()
	{
		return $this->conn->rollback();
	}
}
