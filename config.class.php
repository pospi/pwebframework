<?php
/**
 * Configuration namespace class
 *
 * Just a wrapper for declaring configuration variables. To read from configuration,
 * call the name of the config variable you want to read against the Config class name.
 * Subproperties of deeper configuration arrays can be read by passing further indexes
 * as parameters.
 *
 * For example, to read the 'database' variable from the config array,
 * 		Config::database();
 * To read 'host' under a 'database' subarray,
 * 		Config::database('host');
 * You can also select random config elements like so
 * 		Config::database('connections', '*');
 *
 * To initialise a new config handler, simply pass a configuration array to Config::load().
 * You can also define your configuration as a standalone PHP file that returns an array,
 * and load it with Config::loadFile().
 *
 * :TODO: use non-static method of property loading when php < 5.3
 *
 * @author		Sam Pospischil <pospi@spadgos.com>
 */
class Config
{
	private static $conf;

	public static $PDOType			= 'mysql';		// database type for use with loadDatabaseConnection()

	/**
	 * Load a configuration file against this class
	 *
	 * @param  [string] $configFile Path to the configuration file to load.
	 *                              Configuration files are simply PHP files which return arrays.
	 */
	public static function loadFile($configFile)
	{
		$conf = require_once($configFile);
		if ($conf) {
			self::load($conf);
		} else {
			throw new pwebframework::$configExceptionClass("Configuration file incorrectly formatted, should return an array");
		}
	}

	/**
	 * Load a configuration array against this class
	 * @param  array $configArray configuration array to load
	 */
	public static function load($configArray)
	{
		self::$conf = $configArray;
	}

	/**
	 * Allows reading config variables by using their names as function names.
	 * Subproperties can be read by passing subkeys into the function call.
	 *
	 * @throws pwebframework::$configExceptionClass
	 * @param  [string] $method top-level property to read
	 * @param  [array]  $args   further property keys to read into
	 * @return [mixed]
	 */
	public static function __callStatic($method, $args)
	{
		if (isset(self::$conf[$method])) {
			$target = self::$conf[$method];
			if (count($args)) {
				$accessedKeys = array();
				while (null !== ($arg = array_shift($args))) {
					if (!is_scalar($arg)) {
						throw new pwebframework::$configExceptionClass("Cannot locate config variable - index " . count($accessedKeys) . " is non-scalar");
					}
					$arg = strtolower($arg);
					$accessedKeys[] = $arg;
					if ($arg == '*' && is_array($target)) {		// pick array element
						$target = $target[array_rand($target)];
					} else if (!isset($target[$arg])) {			// variable didnt exist
						throw new pwebframework::$configExceptionClass("Specified config variable '{$method}." . implode('.', $accessedKeys) . "' did not exist");
					} else {									// select subelement by name
						$target = $target[$arg];
					}
				}
				return $target;
			}
			return self::$conf[$method];
		}
		throw new pwebframework::$configExceptionClass("Specified config variable '$method' did not exist");
	}

	/**
	 * Helper for opening a PDO database connection pointed to by a configuration array.
	 * Database connection details are stored under array elements 'host', 'user', 'pass' and 'name'.
	 *
	 * This method accepts the same arguments as __callStatic(), and will throw an exception if
	 * the targeted config variable is not an array of valid database connection parameters.
	 *
	 * @throws pwebframework::$configExceptionClass
	 * @return PDO
	 */
	public static function loadDatabaseConnection()
	{
		$args = func_get_args();
		$method = array_shift($args);
		$params = self::__callStatic($method, $args);

		if (!isset($params['host']) || !isset($params['user']) || !isset($params['pass']) || !isset($params['name'])) {
			throw new pwebframework::$configExceptionClass("Configuration error - database parameters not correctly defined for $method");
		}

		$dbType = self::$PDOType;
		return new PDO("{$dbType}:dbname={$params['name']};host={$params['host']}", $params['user'], $params['pass']);
	}
}
