<?php
 /*===============================================================================
	pWebFramework - request handler class
	----------------------------------------------------------------------------
	Provides a global object Request which can be statically accessed to
	interrogate the remote agent responsible for initiating a connection. Such
	tasks include input sanitisation from $_GET, $_POST and others; request
	mode (http, ajax, cli, ...) etc
	----------------------------------------------------------------------------
	@author		Sam Pospischil <pospi@spadgos.com>
	@date		2010-04-22
  ===============================================================================*/

// Constants for request datatype casting.
// These are used a LOT, which is why they aren't namespaced inside the class. Too much typing.
define('EXPECT_RAW',	0);		// leave the value untouched
define('EXPECT_STRING',	1);		// cast to a string
define('EXPECT_INT',	2);		// interpret as integer
define('EXPECT_FLOAT',	3);		// interpret as float or integer
define('EXPECT_ID',		4);		// expect a database ID (an integer > 0)
define('EXPECT_BOOL',	5);		// interpret as boolean: false="no"|"false"|0, true="yes"|"true"|1..&infin;
define('EXPECT_ARRAY',	6);		// pull in an array directly
define('EXPECT_JSON',	7);		// expects a JSON *object* (primitives will fail), and parses given string into its full representation

abstract class Request
{
	// request mode
	const RM_HTTP = 0;
	const RM_AJAX = 1;
	const RM_CLI  = 2;

	private	static $REQUEST_MODE = null;
	private static $QUERY_PARAMS = null;

	//==================================================================================================================
	//		Request interrogation

	/**
	 * Find a parameter variable for the request. Looks in $_GET under normal
	 * conditions and in $_SERVER['argv'] when running under CLI.
	 */
	public static function get($key, $expect = EXPECT_RAW, $default = null, $allowable = null)
	{
		if (Request::getRequestMethod() == Request::RM_CLI) {
			return Request::sanitise($_SERVER['argv'], $key, $expect, $allowable, $default);
		} else {
			return Request::sanitise($_GET, $key, $expect, $allowable, $default);
		}
	}

	/**
	 * Same as above, for POSTs
	 */
	public static function post($key, $expect = EXPECT_RAW, $default = null, $allowable = null)
	{
		return Request::sanitise($_POST, $key, $expect, $allowable, $default);
	}

	/**
	 * And again, for Cookies
	 */
	public static function cookie($key, $expect = EXPECT_RAW, $default = null, $allowable = null)
	{
		return Request::sanitise($_COOKIE, $key, $expect, $allowable, $default);
	}

	/**
	 * Same as above get/post/cookie accessors, but the variable is *required*
	 */
	public static function requireGet($key, $expect = EXPECT_RAW, $allowable = null)
	{
		if (Request::getRequestMethod() == Request::RM_CLI) {
			return Request::sanitise($_SERVER['argv'], $key, $expect, $allowable, null, true);
		} else {
			return Request::sanitise($_GET, $key, $expect, $allowable, null, true);
		}
	}

	public static function requirePost($key, $expect = EXPECT_RAW, $allowable = null)
	{
		return Request::sanitise($_POST, $key, $expect, $allowable, null, true);
	}

	public static function requireCookie($key, $expect = EXPECT_RAW, $allowable = null)
	{
		return Request::sanitise($_COOKIE, $key, $expect, $allowable, null, true);
	}

	//==================================================================================================================
	//		QueryString handling
	
	// retrieve the current page query string as it exists (an array)
	public static function getQueryParams()
	{
		if (Request::$QUERY_PARAMS === null) {
			Request::storeQueryParams();
		}
		return Request::$QUERY_PARAMS;
	}
	
	/**
	 * Modify values in the current query string. Handy for changing where
	 * forms send to when you want to keep the current GET params intact.
	 * This also allows you to do this without touching the initial GET array
	 * sent to the server.
	 * Accepts two parameter formats:
	 * 	[string, mixed]				<-- modifies the PAGE query string (imported at request start), in place
	 * 	[array, string, mixed]		<-- modifies the query string specified by associative array passed in and returns it. Original isn't touched.
	 */
	public static function modifyQueryString()
	{
		$a = func_get_args();
		if (sizeof($a) == 3) {
			list($arr, $k, $v) = $a;
			/*$stack = debug_backtrace();		// this only seems to work if we use call-time pass-by-reference,
			if (isset($stack[0]["args"][0])) {	// so I'm going to go ahead and say it's unreliable (and causes warnings)
				$arr = &$stack[0]["args"][0];
			}*/
		} else {
			if (Request::$QUERY_PARAMS === null) {
				Request::storeQueryParams();
			}
			$arr = &Request::$QUERY_PARAMS;
			list($k, $v) = $a;
		}
		if ($v === null) {
			unset($arr[$k]);
		} else {
			$arr[$k] = $v;
		}
		return $arr;
	}
	
	/**
	 * retrieve the final (possibly modified) page query string, as a string
	 *
	 * Accepts two parameter formats:
	 * 	[]			<-- returns the current page GET variables as a query string
	 * 	[array]		<-- returns the query string specified by associative array
	 */
	public static function getQueryString()
	{
		$a = func_get_args();
		if (sizeof($a) == 1) {
			list($arr) = $a;
		} else {
			if (Request::$QUERY_PARAMS === null) {
				Request::storeQueryParams();
			}
			$arr = Request::$QUERY_PARAMS;
		}
		return http_build_query($arr);
	}
	
	private static function storeQueryParams()
	{
		Request::$QUERY_PARAMS = Request::getRequestMethod() == Request::RM_CLI ? $_SERVER['argv'] : $_GET;
	}

	//==================================================================================================================
	//		Request method determination

	public static function getRequestMethod()
	{
		if (Request::$REQUEST_MODE === null) {
			Request::determineRequestMethod();
		}
		return Request::$REQUEST_MODE;
	}

	private static function determineRequestMethod()
	{
		if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
			Request::$REQUEST_MODE = Request::RM_AJAX;
		} else if (!isset($_SERVER['HTTP_USER_AGENT'])) {
			Request::$REQUEST_MODE = Request::RM_CLI;
		} else {
			Request::$REQUEST_MODE = Request::RM_HTTP;
		}
	}

	//==================================================================================================================
	//		Utility methods & internals

	/**
	 * Reads a variable from some unsanitised request variable, sanitising and typecasting
	 * as necessary.
	 *
	 * @param	array	&$from		the array to pull the key out of (usually one of $_GET/$_POST/$_COOKIE)
	 * @param	string	$key		the name of the variable to retrieve
	 * @param	int		$expect		one of the EXPECT_* constants, used to safely interpret the data
	 * @param	array	$allowable	array of values which the parameter must be within
	 * @param	mixed	$default	default value to return when variable is not set or in allowable values array
	 * @param	bool	$required	if true, throw an error if this variable is not found
	 * @return	the variable or NULL if undefined and $required = false
	 */
	public static function sanitise(&$from, $key, $expect, $allowable = null, $default = null, $required = false)
	{
		if (!isset($from[$key])) {
			if ($required) {
				Request::sanitisationError($from, "Required request variable '$key' not found in %SOURCE%");
			}
			return $default;
		}
		$var = $from[$key];

		if (is_string($expect)) {		// expecting regular expression match, return default if no match or return subpattern array
			if (!preg_match($expect, $var, $matches)) {
				return $default;
			} else {
				return $matches;
			}
		} else {
			switch ($expect) {
				case EXPECT_STRING:
					$var = $var == '' || !is_scalar($var) ? null : strval($var);
					break;
				case EXPECT_INT:
					$var = is_numeric($var) ? intval($var) : null;
					break;
				case EXPECT_FLOAT:
					$var = is_numeric($var) ? floatval($var) : null;
					break;
				case EXPECT_ID:
					$i = intval($var);
					$var = is_numeric($var) && $var - $i == 0 && $i > 0 ? $i : null;
					break;
				case EXPECT_BOOL:
					if ($var == 'no' || $var == 'false' || $var == '0') {
						$var = false;
					} else if ($var == 'yes' || $var == 'true' || (is_numeric($var) && $var > 0)) {
						$var = true;
					} else {
						$var = null;
					}
					break;
				case EXPECT_ARRAY:
					$var = is_array($var) && !Request::arrayEmpty($var) ? $var : null;
					break;
				case EXPECT_JSON:
					if (is_string($var)) {
						$o = json_decode($var);
						if ($o === null) {
							switch (json_last_error()) {
								case JSON_ERROR_DEPTH:
									Request::sanitisationError($from, "Maximum stack depth exceeded parsing JSON variable '$key' from %SOURCE%", $required);
								case JSON_ERROR_CTRL_CHAR:
									Request::sanitisationError($from, "JSON control character error in %SOURCE% variable '$key'", $required);
								case JSON_ERROR_STATE_MISMATCH:
									Request::sanitisationError($from, "Malformed JSON encountered in %SOURCE% variable '$key'", $required);
								case JSON_ERROR_SYNTAX:
									Request::sanitisationError($from, "JSON syntax error in %SOURCE% variable '$key'", $required);
							}
						}
						$var = is_object($o) ? $o : null;
					} else {
						$var = null;
					}
					break;
			}
		}

		if (is_array($allowable) && !in_array($var, $allowable)) {
			return $default;
		}
		return $var;
	}

	// :WARNING: if request arrays happen to be *identical*, origin data source may be misinterpreted
	private static function sanitisationError(&$from, $msg, $critical = true)
	{
		$place = (($from === $_GET || isset($_SERVER['argv']) && $from === $_SERVER['argv']) ? 'GET' : ($from === $_POST ? 'POST' : ($from === $_COOKIE ? 'COOKIE' : 'REFERENCED')));
		trigger_error(str_replace('%SOURCE%', $place, $msg), ($critical ? E_USER_ERROR : E_USER_WARNING));
	}

	private static function arrayEmpty($arr)
	{
		foreach ($arr as $v) {
			if (!empty($v)) {
				return false;
			}
		}
		return true;
	}
}

?>
