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

require_once('headers.class.php');

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
	private static $HTTP_HEADERS = null;

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

	/**
	 * Rewrite a URL in one step, using the inner functions below.
	 *
	 * @param	array		$newParams		array of keys/values to override current queryString with
	 * @param	string		$newBase		new basepath for the URL (any URI without queryString is ok)
	 * @param	array		$oldParams		starting queryString keys/values. Can be used to override the current one
	 */
	public static function rewriteUrl($newParams, $newBase = null, $oldParams = null)
	{
		if (!$oldParams) {
			$oldParams = Request::getQueryParams();
		}
		if (!$newBase) {
			$newBase = $_SERVER['PHP_SELF'];
		}
		$newParams = Request::modifyQueryString($oldParams, $newParams);

		return Request::getURLString($newBase, $newParams);
	}

	// retrieve the current page query string as it exists (an array)
	public static function getQueryParams()
	{
		Request::storeQueryParams();
		return Request::$QUERY_PARAMS;
	}

	/**
	 * Modify values in the current query string. Handy for changing where
	 * forms send to when you want to keep the current GET params intact.
	 * This also allows you to do this without touching the initial GET array
	 * sent to the server.
	 * Accepts 4 parameter formats:
	 * 	[string, mixed]				<-- modifies the PAGE query string (imported at request start), in place
	 * 	[array, string, mixed]		<-- modifies the query string specified by associative array passed in and returns it. Original isn't touched.
	 * 	[array]						<-- modifies the PAGE query string (imported at request start) by setting all keys present in this array to their values
	 * 	[array, array]				<-- modifies the query string specified by first parameter with values in the second
	 */
	public static function modifyQueryString()
	{
		$a = func_get_args();
		if (sizeof($a) == 3) {
			$arr		= $a[0];
			$changes	= array($a[1] => $a[2]);
			/*$stack = debug_backtrace();		// this only seems to work if we use call-time pass-by-reference,
			if (isset($stack[0]["args"][0])) {	// so I'm going to go ahead and say it's unreliable (and causes warnings)
				$arr = &$stack[0]["args"][0];
			}*/
		} else if (sizeof($a) == 2) {
			if (is_string($a[0])) {
				Request::storeQueryParams();
				$arr		= &Request::$QUERY_PARAMS;
				$changes	= array($a[0] => $a[1]);
			} else {
				$arr		= $a[0];
				$changes	= $a[1];
			}
		} else {
			Request::storeQueryParams();
			$arr		= &Request::$QUERY_PARAMS;
			$changes	= $a[0];
		}

		foreach ($changes as $i => $j) {
			if ($j === null) {
				unset($arr[$i]);
			} else {
				$arr[$i] = $j;
			}
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
			Request::storeQueryParams();
			$arr = Request::$QUERY_PARAMS;
		}
		return http_build_query($arr);
	}

	/**
	 * Retrieves a queryString using getQueryString(), and prepends the page
	 * URL specified. If ommitted, the current page's url is used.
	 *
	 * Accepts 4 parameter formats:
	 * 	[]				<-- returns the current page and querystring
	 * 	[string]		<-- returns the current querystring pointed to a new page
	 * 	[array]			<-- returns the current page with passed in querystring
	 * 	[string, array]	<-- returns URL for the passed in page and querystring
	 */
	public static function getURLString()
	{
		$page = $_SERVER['PHP_SELF'];
		$params = null;
		$a = func_get_args();

		if (sizeof($a) == 1) {
			if (is_array($a[0])) {
				$params = $a[0];
			} else {
				$page = $a[0];
			}
		} else if (sizeof($a) == 2) {
			list($page, $params) = $a;
		}

		$query = Request::getQueryString($params);

		return $query ? $page . '?' . Request::getQueryString($params) : $page;
	}

	private static function storeQueryParams()
	{
		if (Request::$QUERY_PARAMS === null) {
			Request::$QUERY_PARAMS = Request::getRequestMethod() == Request::RM_CLI ? $_SERVER['argv'] : $_GET;
		}
	}

	//==================================================================================================================
	//		Header handling

	public static function getHeaders()
	{
		Request::storeHeaders();
		return Request::$HTTP_HEADERS;
	}

	// this method works regardless of the server environment. Headers are stored with lowercase keys, in the same
	// format as used by the Headers class.
	private static function storeHeaders()
	{
		if (Request::$HTTP_HEADERS === null) {
			Request::$HTTP_HEADERS = new Headers();

			foreach ($_SERVER as $k => $v) {
				if (strpos($k, 'HTTP_') === 0 || in_array($k, Headers::$OTHER_HTTP_HEADERS)) {
					$k = str_replace(array('HTTP_', '_'), array('', '-'), $k);
					Request::$HTTP_HEADERS[$k] = $v;
				}
			}
		}
	}

	//==================================================================================================================
	//		Request method determination

	public static function getRequestMethod()
	{
		Request::determineRequestMethod();
		return Request::$REQUEST_MODE;
	}

	private static function determineRequestMethod()
	{
		if (Request::$REQUEST_MODE === null) {
			if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
				Request::$REQUEST_MODE = Request::RM_AJAX;
			} else if (PHP_SAPI == 'cli' || (substr(PHP_SAPI, 0, 3) == 'cgi' && empty($_SERVER['REQUEST_URI']))) {
				Request::$REQUEST_MODE = Request::RM_CLI;
			} else {
				Request::$REQUEST_MODE = Request::RM_HTTP;
			}
		}
	}

	//==================================================================================================================
	//		Other useful stuff

	// Get remote IP address, and attempt to provide 'real' address when a proxy is involved
	public static function getRemoteIP()
	{
		return isset($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : (
			isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']
		);
	}

	// retrieve the document root without relying on apache's environment var (which is obviously not present under IIS)
	// :WARNING: this method will not work under CLI
	public static function getDocumentRoot()
	{
		if (isset($_SERVER['SCRIPT_FILENAME'])) {
			return str_replace( '\\', '/', substr($_SERVER['SCRIPT_FILENAME'], 0, 0 - strlen($_SERVER['PHP_SELF'])));
		};
		if(isset($_SERVER['PATH_TRANSLATED'])) {
			return str_replace( '\\', '/', substr(str_replace('\\\\', '\\', $_SERVER['PATH_TRANSLATED']), 0, 0 - strlen($_SERVER['PHP_SELF'])));
		};
		return $_SERVER['DOCUMENT_ROOT'];
	}

	//==================================================================================================================
	//		Utility methods & internals

	/**
	 * Reads a variable from some unsanitised request variable, sanitising and typecasting
	 * as necessary.
	 *
	 * @param	array	&$from		the array to pull the key out of (usually one of $_GET/$_POST/$_COOKIE)
	 * @param	string	$key		the name of the variable to retrieve
	 * @param	int		$expect		- one of the EXPECT_* constants, used to safely interpret the data, or..
	 * 								- a regex string to match the variable against. The extra modifier 'a'
	 * 								  toggles between preg_match() and preg_match_all(). Array of matches is
	 * 								  returned in either case - for preg_match each will be a subpattern, for
	 * 								  preg_match_all each will be an array of subpatterns.
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
			// look for our custom 'a' modifier which matches all
			$expect = preg_replace('/(\/\w*)a(\w*)$/', '$1$2', $expect, -1, $matchAll);
			if ($matchAll && !preg_match_all($expect, $var, $matches)) {
				return $default;
			}
			if (!$matchAll && preg_match($expect, $var, $matches)) {
				return $default;
			}
			return $matches;
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
					if ($var == 'off' || $var == 'no' || $var == 'false' || $var == '0') {
						$var = false;
					} else if ($var == 'on' || $var == 'yes' || $var == 'true' || (is_numeric($var) && $var > 0)) {
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

		if ($var === null || (is_array($allowable) && !in_array($var, $allowable))) {
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

	// :NOTE: we count arrays full of empty strings or NULL values as empty
	private static function arrayEmpty($arr)
	{
		foreach ($arr as $v) {
			if (!empty($v) || $v === "0" || $v === 0 || $v === false) {
				return false;
			}
		}
		return true;
	}
}

?>
