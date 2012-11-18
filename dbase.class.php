<?php
/**
 * DBase
 *
 * A database abstraction layer allowing the use of many common database client APIs.
 *
 * @package 	pWebFramework
 * @author 		Sam Pospischil <pospi@spadgos.com>
 * @since		15/8/2012
 * @requires	PDO (PHP Data Objects), mysqli extension or mysql extension
 * @requires	ProcessLogger if logging is to be enabled
 */

class DBaseDisconnectedException extends Exception {}

interface IDBase
{
	public function __connect($user, $pass, $dbName, $host = 'localhost', $port = null);
	public function __realQuery($sql);
	public function __realExec($sql, $returnType = null);
	public function __realStart();
	public function __realCommit();
	public function __realRollback();

	/**
	 * Reads the next row from the previous query() result. Use $this->lastResult within
	 * this method to access the raw result returned from the query.
	 * @param  const $fetchMode return mode for the query, defaults to FETCH_ASSOC.
	 * @param  mixed $result	connection resource for pulling the next row from, if different to $this->lastResult
	 * @return mixed
	 */
	public function nextRow($fetchMode = null, $result = null);

	/**
	 * Escape a string for a query using the underlying escape mechanism of the database driver.
	 */
	public function quotestring($param);

	/**
	 * Return the insert ID from the previous INSERT query run on an autoincrementing table index
	 * @return int
	 */
	public function lastInsertId();

	/**
	 * Return the number of affected rows from the previous DELETE, UPDATE, REPLACE or INSERT query
	 * @return int
	 */
	public function affectedRows();

	/**
	 * Returns an array of error information on the last operation executed, with the error code
	 * at index 0 and message at index 1.
	 */
	public function lastError();
}

abstract class DBase implements IDBase
{
	// supported database connection APIs
	const CONN_AUTO = 0;
	const CONN_RAW = 'mysql';
	const CONN_PDO = 'pdo';
	const CONN_SQLI = 'mysqli';

	// return mode constants for exec()
	const RM_SUCCESS = 0;
	const RM_AFFECTED_ROWS = 1;
	const RM_INSERT_ID = 2;

	// row fetch mode constants
	const FETCH_ARRAY = 0;
	const FETCH_ASSOC = 1;
	const FETCH_OBJECT = 2;

	// database type for PDO. You can override this in external code if you need to support other database engines.
	public $DB_TYPE = 'mysql';

	// underlying connection object & method of connection
	protected $conn;
	protected $connParams;

	protected $inTransaction = false;	// transaction flag

	protected $logger;			// ProcessLogger instance used for logging
	protected $logging = false;
	protected $debugLog = false;	// if true, output extra info to log

	protected $lastResult;		// last raw query result.

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
	 *
	 * @return an instance of the DBase class most appropriate to your PHP installation.
	 */
	public static function Create()
	{
		$na = func_num_args();

		// determine connection type
		if ($na > 1) {
			@list($user, $pass, $dbName, $host, $port, $connectionType) = func_get_args();
		} else {
			$conn = func_get_arg(0);
			$connectionType = self::CONN_AUTO;

			if ($conn instanceof PDO) {
				$connectionType = self::CONN_PDO;
			} else if ($conn instanceof mysqli) {
				$connectionType = self::CONN_SQLI;
			} else if (is_resource($conn) && get_resource_type($conn) == 'mysql link') {
				$connectionType = self::CONN_RAW;
			}
		}

		// determine best DB client if we should autodetect
		if (!isset($connectionType) || $connectionType === self::CONN_AUTO) {
			if (class_exists('PDO')) {
				$connectionType = self::CONN_PDO;
			} else if (function_exists('mysqli_connect')) {
				$connectionType = self::CONN_SQLI;
			} else if (function_exists('mysql_connect')) {
				$connectionType = self::CONN_RAW;
			}
		}

		// load & return the instance
		$class = 'DBase_' . $connectionType;

		if (!class_exists($class)) {
			require_once(pwebframework::$PWF_PATH . 'dbase_' . $connectionType . '.class.php');
		}

		if ($na > 1) {
			return new $class($user, $pass, $dbName, $host, $port);
		}
		return new $class($conn);
	}

	/**
	 * @see DBase::Create()
	 */
	public function __construct()
	{
		$na = func_num_args();

		if ($na > 1) {
			// new connection parameters
			@list($user, $pass, $dbName, $host, $port) = func_get_args();
			$this->connect($user, $pass, $dbName, $host, $port);
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
	 */
	public function connect($user, $pass, $dbName, $host = 'localhost', $port = null)
	{
		$this->__connect($user, $pass, $dbName, $host, $port);

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
	 * @param mixed $conn an existing connection object for this database type
	 */
	public function setConnection($conn)
	{
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
			$this->connect($user, $pass, $dbName, $host, $port);
			return true;
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

		$result = $this->__realQuery($sql);

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

		$this->lastResult = $result;
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
		$startTime = microtime(true);

		list($result, $affectedRows) = $this->__realExec($sql, $returnMode);

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
		if (!method_exists($this, 'quote' . $quoteType)) {
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

		$this->__realStart();

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

		$this->__realRollback();

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

		$this->__realCommit();

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
		return isset($this->conn->$param) ? $this->conn->$param : null;
	}
}
