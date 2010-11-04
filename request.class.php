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

	//==================================================================================================================
	//		Request interrogation

	/**
	 * Find a parameter variable for the request. Looks in $_GET under normal
	 * conditions and in $_SERVER['argv'] when running under CLI.
	 */
	public static function get($key, $expect = EXPECT_RAW, $allowable = null, $default = null)
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
	public static function post($key, $expect = EXPECT_RAW, $allowable = null, $default = null)
	{
		return Request::sanitise($_POST, $key, $expect, $allowable, $default);
	}

	/**
	 * And again, for Cookies
	 */
	public static function cookie($key, $expect = EXPECT_RAW, $allowable = null, $default = null)
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

	//==========================================================================

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
				$var = is_array($var) ? $var : null;
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

		if (is_array($allowable) && !in_array($var, $allowable)) {
			return $default;
		}
		return $var;
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
		} else if (isset($_SERVER['argv'])) {
			Request::$REQUEST_MODE = Request::RM_CLI;
		} else {
			Request::$REQUEST_MODE = Request::RM_HTTP;
		}
	}

	//==================================================================================================================
	//		Util

	// :WARNING: if request arrays happen to be *identical*, origin data source may be misinterpreted
	private static function sanitisationError(&$from, $msg, $critical = true)
	{
		$place = (($from === $_GET || isset($_SERVER['argv']) && $from === $_SERVER['argv']) ? 'GET' : ($from === $_POST ? 'POST' : ($from === $_COOKIE ? 'COOKIE' : 'REFERENCED')));
		trigger_error(str_replace('%SOURCE%', $place, $msg), ($critical ? E_USER_ERROR : E_USER_WARNING));
	}
}

?>
