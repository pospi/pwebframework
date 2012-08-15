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

	private $lastAffectedRows;	// last operation's affected rowcount, only used with PDO

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
		$this->log("Run query: {$sql}", true);

		// perform the query
		switch ($this->method) {
			case self::CONN_PDO:
				$this->lastAffectedRows = 0;
				$result = @$this->conn->query($sql);
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
			$this->log("Bad query: {$sql}");
			$errInfo = $this->lastError();
			$this->log("Error (code {$errInfo[0]}): {$errInfo[1]}", true);
			return false;
		}

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
		$this->log("Execute query: {$sql}", true);

		if (!isset($returnMode)) {
			$returnMode = self::RM_AFFECTED_ROWS;
		}

		// perform the query and set affected rows if the API returns that for us
		$affectedRows = null;
		switch ($this->method) {
			case self::CONN_PDO:
				$affectedRows = @$this->conn->exec($sql);
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
			$this->log("Bad query: {$sql}");
			$errInfo = $this->lastError();
			$this->log("Error (code {$errInfo[0]}): {$errInfo[1]}", true);
			return false;
		}

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
	// database state
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
				return array($err[0], $err[2]);
			case self::CONN_SQLI:
				return array($this->conn->errno, $this->conn->error);
			case self::CONN_RAW:
				return array(mysql_errno($this->conn), mysql_error($this->conn));
		}
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
