<?php
/**
 * DBase
 *
 * A database abstraction layer allowing the use of many common database client APIs.
 *
 * This class is built for MySQL, but there's no reason most of it won't work with
 * other database engines.
 * You can change the static class variable $DB_TYPE to set a different database engine when
 * using PDO, but this will not work with mysqli or mysql, for obvious reasons.
 *
 * @package 	pWebFramework
 * @author 		Sam Pospischil <pospi@spadgos.com>
 * @since		15/8/2012
 * @requires	PDO (PHP Data Objects), PHP MySQLi library or MySQL library
 * @requires	ProcessLogger if logging is to be enabled
 */

class DBaseDisconnectedException extends Exception {}

class DBase
{
	// supported database connection APIs
	const CONN_AUTO = 0;
	const CONN_RAW = 1;
	const CONN_PDO = 2;
	const CONN_SQLI = 3;

	// return mode constants for exec()
	const RM_SUCCESS = 0;
	const RM_AFFECTED_ROWS = 1;
	const RM_INSERT_ID = 2;

	// database type for PDO. You can override this in external code if you need to support other database engines.
	public $DB_TYPE = 'mysql';

	// underlying connection object & method of connection
	private $conn;
	private $method = DBase::CONN_AUTO;
	private $connParams;

	private $lastAffectedRows;	// last operation's affected rowcount, only used with PDO

	private $inTransaction = false;	// transaction flag

	private $logger;			// ProcessLogger instance used for logging
	private $logging = false;
	private $debugLog = false;	// if true, output extra info to log

	//--------------------------------------------------------------------------
	// instantiation
	//--------------------------------------------------------------------------

	/**
	 * Creates and connects to a new database, or wraps an existing database connection.
	 *
	 * @param mixed  an existing PDO connection or mysql connection resource
	 *  OR
	 * @param string $user           username credential for connecting
	 * @param string $pass           database user password
	 * @param string $dbName         name of the database to use by default
	 * @param string $host           hostname for connection (default localhost)
	 * @param int    $port           port for connection (default is the default port for that database type)
	 * @param const  $connectionType type of connection to use. Defaults to autodetection based on installed extensions - preference is PDO, MySQLi and then MySQL.
	 */
	public function __construct()
	{
		$na = func_num_args();

		if ($na > 1) {
			// new connection parameters
			@list($user, $pass, $dbName, $host, $port, $connectionType) = func_get_args();
			$this->connect($user, $pass, $dbName, $host, $port, $connectionType);
		} else if ($na == 1) {
			// existing connection handle
			$this->setConnection(func_get_arg(0));
		}
		// otherwise, leave the object uninitialised and wait for setup by other external code
	}

	/**
	 * Creates a new database connection and assigns it as our own
	 * @param string $user           username credential for connecting
	 * @param string $pass           database user password
	 * @param string $dbName         name of the database to use by default
	 * @param string $host           hostname for connection (default localhost)
	 * @param int    $port           port for connection (default is the default port for that database type)
	 * @param const  $connectionType type of connection to use. Defaults to autodetection based on installed extensions - preference is PDO, MySQLi and then MySQL.
	 */
	public function connect($user, $pass, $dbName, $host = 'localhost', $port = null, $connectionMode = null)
	{
		if (!(isset($connectionMode) && $this->method == self::CONN_AUTO) || $connectionMode == self::CONN_AUTO) {
			if (class_exists('PDO')) {
				$connectionMode = self::CONN_PDO;
			} else if (function_exists('mysqli_connect')) {
				$connectionMode = self::CONN_SQLI;
			} else if (function_exists('mysql_connect')) {
				$connectionMode = self::CONN_RAW;
			} else {
				trigger_error("Unable to connect to database: no available storage engine", E_USER_ERROR);
			}
		}

		switch ($connectionMode) {
			case self::CONN_PDO:
				$this->conn = new PDO($this->DB_TYPE . ":dbname={$dbName};host={$host}", $user, $pass);
				break;
			case self::CONN_SQLI:
				$this->conn = mysqli_connect($host, $user, $pass, $dbName, $port);
				break;
			case self::CONN_RAW:
				$this->conn = mysql_connect($host . ($port ? ":$port" : ''), $user, $pass);
				mysql_select_db($dbName, $this->conn);
				break;
		}
		$this->method = $connectionMode;

		// store connection parameters to allow reconnecting
		$this->connParams = array(
			'user' => $user,
			'pass' => $pass,
			'dbName' => $dbName,
			'host' => $host,
			'port' => $port,
		);
	}

	/**
	 * Set the underlying database connection used by this instance
	 * @param mixed $conn an existing PDO object or MySQLi / MySQL connection resource
	 */
	public function setConnection($conn)
	{
		if ($conn instanceof PDO) {
			$this->method = self::CONN_PDO;
		} else if ($conn instanceof mysqli) {
			$this->method = self::CONN_SQLI;
		} else if (is_resource($conn) && get_resource_type($conn) == 'mysql link') {
			$this->method = self::CONN_RAW;
		} else {
			trigger_error("Unknown connection type for provided database connection handle", E_USER_ERROR);
		}
		$this->conn = $conn;
	}

	/**
	 * Reconnects to the database if possible
	 * @return true if reconnected
	 */
	public function reconnect()
	{
		if ($this->connParams) {
			// if connection params are stored, we can reconnect always
			extract($this->connParams);
			$this->connect($user, $pass, $dbName, $host, $port, $this->method);
			return true;
		} else if ($this->method = self::CONN_SQLI) {
			// when mysqli extension is used, we can try pinging to see if ping reconnection is enabled
			return $this->conn->ping();
		} else if ($this->method = self::CONN_RAW) {
			// when mysql extension is used, we can try pinging to see if ping reconnection is enabled
			return mysql_ping($this->conn);
		}
		return false;
	}

	//--------------------------------------------------------------------------
	// log interface
	//--------------------------------------------------------------------------

	/**
	 * Enable error logging via a ProcessLogger instance.
	 * @param  ProcessLogger $log logger instance to perform error & debug logging through
	 */
	public function enableLogging(ProcessLogger $log, $debug = false)
	{
		$this->logging = true;
		$this->debugLog = $debug;
		$this->logger = $log;
	}

	private function log($line, $debug = false)
	{
		if (!$this->logging || ($debug && !$this->debugLog)) {
			return;
		}
		if ($this->logger) {
			$this->logger[] = $line;
		} else {
			trigger_error($line, E_USER_WARNING);
		}
	}

	private function error($msg, $code = 0, $skipRetry = false)
	{
		// check for a disconnection error code and automatically reconnect
		if (!$skipRetry && ($code == 2006 || $code == 2013)) {
			throw new DBaseDisconnectedException("Database disconnected", $code);
		}

		// otherwise trigger the error in whichever way is configured
		if (isset(pwebframework::$dbaseExceptionClass)) {
			throw new pwebframework::$dbaseExceptionClass($msg, $code);
		}
		trigger_error($msg, E_USER_ERROR);
	}

	//--------------------------------------------------------------------------
	// low-level querying
	//--------------------------------------------------------------------------

	/**
	 * Performs a SELECT, SHOW, DESCRIBE or EXPLAIN database query and returns the raw query resource.
	 *
	 * @param  string $sql raw SQL for execution
	 * @return raw query result, depending on implementation:
	 *             PDO:		PDOStatement
	 *             mysqli:	mysqli_result
	 *             mysql:	result resource
	 *         FALSE if an error occurred in the query
	 */
	public function query($sql)
	{
		$this->log("Running query: {$sql}", true);

		// perform the query
		$startTime = microtime(true);
		switch ($this->method) {
			case self::CONN_PDO:
				$this->lastAffectedRows = 0;
				try {
					$result = $this->conn->query($sql);
				} catch (PDOException $e) {
					$result = false;
				}
				break;
			case self::CONN_SQLI:
				$result = @$this->conn->query($sql);
				break;
			case self::CONN_RAW:
				$result = @mysql_query($sql, $this->conn);
				break;
		}

		// check for an error
		if ($result === false) {
			$this->log("Bad query: {$sql}", true);
			$errInfo = $this->lastError();
			try {
				$this->error("Error (code {$errInfo[0]}): {$errInfo[1]}", $errInfo[0]);
			} catch (DBaseDisconnectedException $e) {
				if ($this->reconnect()) {
					return $this->query($sql);
				} else {
					$this->error("Error (code {$errInfo[0]}): {$errInfo[1]}", $errInfo[0], true);
				}
			}
			return false;
		}

		$this->log("Query done in " . number_format((microtime(true) - $startTime) * 1000, 3) . " msec.", true);

		return $result;
	}

	/**
	 * Performs a query which does not return results and returns either
	 * a flag to indicate success or the number of affected rows from the query
	 * if returnAffected = true.
	 * In both cases, FALSE is returned if the query failed to execute.
	 *
	 * @param  [string] $sql			sql to execute
	 * @param  [bool]	$returnMode		return method of the function (RM_SUCCESS, RM_INSERT_ID or RM_AFFECTED_ROWS). By default, affected rows are returned.
	 * @return bool/int depending on the value of $returnMode. FALSE is always returned in case of an error.
	 */
	public function exec($sql, $returnMode = null)
	{
		$this->log("Executing query: {$sql}", true);

		if (!isset($returnMode)) {
			$returnMode = self::RM_AFFECTED_ROWS;
		}

		// perform the query and set affected rows if the API returns that for us
		$affectedRows = null;
		$startTime = microtime(true);
		switch ($this->method) {
			case self::CONN_PDO:
				try {
					$affectedRows = $this->conn->exec($sql);
				} catch (PDOException $e) {
					$affectedRows = false;
				}
				$result = $affectedRows !== false;
				$this->lastAffectedRows = (int)$affectedRows;
				break;
			case self::CONN_SQLI:
				$result = @$this->conn->query($sql);
				$result = (bool)$result;
				break;
			case self::CONN_RAW:
				$result = @mysql_query($sql, $this->conn) !== false;
				break;
		}

		// if the query failed, that's it
		if (!$result) {
			$this->log("Bad query: {$sql}", true);
			$errInfo = $this->lastError();
			try {
				$this->error("Error (code {$errInfo[0]}): {$errInfo[1]}", $errInfo[0]);
			} catch (DBaseDisconnectedException $e) {
				if ($this->reconnect()) {
					return $this->exec($sql, $returnMode);
				} else {
					$this->error("Error (code {$errInfo[0]}): {$errInfo[1]}", $errInfo[0], true);
				}
			}
			return false;
		}

		$this->log("Query done in " . number_format((microtime(true) - $startTime) * 1000, 2) . " msec.", true);

		// return the type of result we're looking for
		switch ($returnMode) {
			case self::RM_INSERT_ID:
				return $this->lastInsertId();
			case self::RM_AFFECTED_ROWS:
				return isset($affectedRows) ? $affectedRows : $this->affectedRows();
			default:
				return $result;
		}
	}

	//--------------------------------------------------------------------------
	// escaping & sanitisation
	//--------------------------------------------------------------------------

	public function quote($param, $quoteType = 'string')
	{
		if (!function_exists(array($this, 'quote' . $quoteType))) {
			trigger_error("Unknown quote type: $quoteType", E_USER_ERROR);
		}
		return call_user_func(array($this, 'quote' . $quoteType), $param);
	}

	public function quoteall(Array $array, $quoteType = 'string')
	{
		foreach ($array as &$val) {
			$val = call_user_func(array($this, 'quote' . $quoteType), $val);
		}
		return $array;
	}

	public function quotestring($param)
	{
		if ($param === null) {
			return 'NULL';
		}
		switch ($this->method) {
			case self::CONN_PDO:
				return $this->conn->quote($param);
			case self::CONN_SQLI:
				return $this->conn->real_escape_string($param);
			case self::CONN_RAW:
				return mysql_real_escape_string($param, $this->conn);
		}
	}

	public function quoteint($param)
	{
		if ($param === null) {
			return 'NULL';
		}
		return intval($param);
	}

	public function quotehex($param)
	{
		if ($param === null) {
			return 'NULL';
		}
		$str = '0x' . $param;
		if (!is_numeric($str)) {
			// not a hex number
			trigger_error("quotehex: non-hex input ($param)", E_USER_WARNING);
			return '';
		}
		return $str;
	}

	public function quotebin($param)
	{
		if ($param === null) {
			return 'NULL';
		}
		return '0x' . bin2hex($param);
	}

	//--------------------------------------------------------------------------
	// database query state
	//--------------------------------------------------------------------------

	/**
	 * Return the insert ID from the previous INSERT query run on an autoincrementing table index
	 * @return int
	 */
	public function lastInsertId()
	{
		switch ($this->method) {
			case self::CONN_PDO:
				return $this->conn->lastInsertId();
			case self::CONN_SQLI:
				return $this->conn->insert_id;
			case self::CONN_RAW:
				return mysql_insert_id($this->conn);
		}
	}

	/**
	 * Return the number of affected rows from the previous DELETE, UPDATE, REPLACE or INSERT query
	 * @return int
	 */
	public function affectedRows()
	{
		switch ($this->method) {
			case self::CONN_PDO:
				return $this->lastAffectedRows;
			case self::CONN_SQLI:
				return $this->conn->affected_rows;
			case self::CONN_RAW:
				return mysql_affected_rows($this->conn);
		}
	}

	/**
	 * Returns an array of error information on the last operation executed, with the error code
	 * at index 0 and message at index 1.
	 */
	public function lastError()
	{
		switch ($this->method) {
			case self::CONN_PDO:
				$err = $this->conn->errorInfo();
				return array($err[1], $err[2]);
			case self::CONN_SQLI:
				return array($this->conn->errno, $this->conn->error);
			case self::CONN_RAW:
				return array(mysql_errno($this->conn), mysql_error($this->conn));
		}
	}

	//--------------------------------------------------------------------------
	// transactions
	//--------------------------------------------------------------------------

	// :NOTE: these methods do not work with non-transactional table types (MyISAM or ISAM)

	const ISOL_REPEATABLEREAD = "REPEATABLE READ";		// all SELECTs within transaction have state from the start of transaction
	const ISOL_READCOMMITTED = "READ COMMITTED";		// all SELECTs within transaction have state at that moment within it
	const ISOL_READUNCOMMITTED = "READ UNCOMMITTED";	// like TRANSACT_READCOMMITTED, but potentially missing later writes within the transaction. a 'dirty read'.
	const ISOL_SERIALIZABLE = "SERIALIZABLE";			// allows other transactions to read the rows being modified, but not update or delete them

	/**
	 * Begin a database transaction, if supported
	 * @param string $isolationLevel  the SQL transaction isolation level.
	 *                                Default depends on the storage engine - InnoDB uses READ COMMITTED, MySQL Cluster uses REPEATABLE READ.
	 * @return boolean indicating success of enabling the transaction
	 */
	public function startTransaction($isolationLevel = null)
	{
		if ($this->inTransaction) {
			return false;	// already running a transaction
		}

		if (isset($isolationLevel)) {
			$this->exec("SET TRANSACTION ISOLATION LEVEL $isolationLevel");
		}

		switch ($this->method) {
			case self::CONN_PDO:
				$ok = $this->conn->beginTransaction();
				break;
			case self::CONN_SQLI:
				$ok = $this->conn->autocommit(false);
				break;
			case self::CONN_RAW:
				$ok = false !== @mysql_query("BEGIN", $this->conn);
				break;
		}

		if ($ok) {
			$this->inTransaction = true;
		}
		return $ok;
	}

	public function abortTransaction()
	{
		if (!$this->inTransaction) {
			return false;	// not running a transaction
		}

		switch ($this->method) {
			case self::CONN_PDO:
			case self::CONN_SQLI:
				$ok = $this->conn->rollback();
				break;
			case self::CONN_RAW:
				$ok = false !== @mysql_query("ROLLBACK", $this->conn);
				break;
		}

		if ($ok) {
			$this->inTransaction = false;
		}
		return $ok;
	}

	public function commitTransaction()
	{
		if (!$this->inTransaction) {
			return false;	// not running a transaction
		}

		switch ($this->method) {
			case self::CONN_PDO:
				$ok = $this->conn->commit();
				break;
			case self::CONN_SQLI:
				$ok = $this->conn->commit();
				$this->conn->autocommit(true);
				break;
			case self::CONN_RAW:
				$ok = false !== @mysql_query("COMMIT", $this->conn);
				break;
		}

		if ($ok) {
			$this->inTransaction = false;
		}
		return $ok;
	}

	public function inTransaction()
	{
		return $this->inTransaction;
	}

	//--------------------------------------------------------------------------
	// compatibility layer
	//--------------------------------------------------------------------------

	/**
	 * API wrapper interface.
	 *
	 * Delegates unknown method calls to underlying database objects and passes the results back.
	 * This allows us to override and extend methods where necessary whilst maintaining the default API.
	 *
	 * When using PDO, you can use all standard PDO object methods:
	 * 	http://php.net/manual/en/class.pdo.php
	 *
	 * When using mysqli, you can use all methods of the mysqli object from the OO-style API:
	 * 	http://php.net/manual/en/class.mysqli.php
	 *
	 * When using mysql, you can call any of the mysql_* methods without the mysql_ prefix:
	 * 	http://php.net/manual/en/ref.mysql.php
	 * The mysql connection link will be passed as the last parameter to this function, so only connection-related methods
	 * are callable in this fashion. Also note that due to this behaviour, all other function parameters should be filled in.
	 *
	 * @param  string $method method called
	 * @param  array  $args   args passed
	 * @return mixed the result of the underlying function
	 */
	public function __call($method, $args)
	{
		if ($this->method == self::CONN_RAW) {
			array_push($args, $this->conn);
			return call_user_func_array('mysql_' . $method, $args);
		}
		return call_user_func_array(array($this->conn, $method), $args);
	}

	/**
	 * Same as above, for parameters
	 *
	 * When using PDO or mysqli, returns properties of the connection object.
	 * When using mysql, returns properties from mysql_info(): http://php.net/manual/en/function.mysql-info.php
	 */
	public function __get($param)
	{
		if ($this->method == self::CONN_RAW) {
			$info = mysql_info($this->conn);
			return isset($info[$param]) ? $info[$param] : null;
		}
		return isset($this->conn->$param) ? $this->conn->$param : null;
	}
}
