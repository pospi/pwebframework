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
	
	// aside from server variables prefixed with 'HTTP_', these will also be retrieved as HTTP header values
	private static $OTHER_HTTP_HEADERS = array('CONTENT_TYPE', 'CONTENT_LENGTH');
	private static $STATUS_CODES = array(
		100 => 'Continue',
		101 => 'Switching Protocols',		// 'Upgrade' field determines protocol to switch to
		200 => 'OK',
		201 => 'Created',					// 'Location' gives URI of new resource
		202 => 'Accepted',
		203 => 'Non-Authoritative Information',
		204 => 'No Content',
		205 => 'Reset Content',
		206 => 'Partial Content',			// 'Content-Range' & 'Content-Length' give content size & offset, 'Date', 'Content-Location'/'ETag'/'Last-Modified' matches cached data, 'Expires'/'Cache-Control' tells persistence
		300 => 'Multiple Choices',			// 'Location' gives preferred new URI
		301 => 'Moved Permanently',			// 'Location' gives new URI
		302 => 'Found',						// 'Location' gives temporary URI
		303 => 'See Other',					// 'Location' gives URI to redirect to
		304 => 'Not Modified',
		305 => 'Use Proxy',					// 'Location' gives URI of proxy which must be used
		//306 => '', unused
		307 => 'Temporary Redirect',		// 'Location' gives temporary URI
		400 => 'Bad Request',
		401 => 'Unauthorized',
		402 => 'Payment Required',
		403 => 'Forbidden',
		404 => 'Not Found',
		405 => 'Method Not Allowed',		// 'Allow' contains valid methods for the resource
		406 => 'Not Acceptable',
		407 => 'Proxy Authentication Required',	// 'Proxy-Authenticate' is the proxy challenge to respond to
		408 => 'Request Timeout',
		409 => 'Conflict',
		410 => 'Gone',
		411 => 'Length Required',
		412 => 'Precondition Failed',
		413 => 'Request Entity Too Large',	// 'Retry-After', if present, tells us this is temporary and to try again
		414 => 'Request-URI Too Long',
		415 => 'Unsupported Media Type',
		416 => 'Requested Range Not Satisfiable',	// 'Content-Range' gives the total length of the resource
		417 => 'Expectation Failed',
		500 => 'Internal Server Error',
		501 => 'Not Implemented',
		502 => 'Bad Gateway',
		503 => 'Service Unavailable',		// 'Retry-After', if present, tells us this is temporary and to try again
		504 => 'Gateway Timeout',
		505 => 'HTTP Version Not Supported',
	);

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
	
	/**
	 * Accepts headers in one of the following formats, and returns an array
	 * of header fields properly keyed on their field names. Element 0 in the
	 * returned array gives the HTTP status code.
	 *
	 * If multiple status code lines are encountered within a header block,
	 * the header array is reset when the last one is found, and the remainder
	 * of the headers are treated as the 'relevant' header block. Other repeats
	 * of header lines cause their values to become arrays rather than strings.
	 *
	 * The first empty line followed by a non-status HTTP header denotes the end
	 * of the header block, and will abort parsing.
	 * 
	 * @param	$headers	- header block, as string
	 * 						- array of individual (joined) header lines
	 * @param	$returnBody	- if true, return an array with headers at [0], body at [1]
	 * @return	array representing headers passed:
	 * 			- keys are header names (in lowercase)
	 * 			- values are header values:
	 * 				- as a string, when only 1 of these headers exists
	 * 				- as an array, when multiple headers exist
	 * 			- header blocks earlier in the request chain recurse upwards 
	 * 			  through each array's "__previousheader" value
	 */
	public static function parseHeaders($headers, $returnBody = false)
	{
		if (!is_array($headers)) {
			$headers = preg_split("(\r\n|\r|\n)", $headers);
		}
		
		$currentHeaderBlock = array();
		
		foreach ($headers as $i => $line) {
			if (empty($line)) {			// keep going. there may be another header block coming.
				$nextLine = next($headers);
				prev($headers);
				$nextIsStatus = preg_match('/^http\/(\d\.\d)\s+/i', $nextLine);
				unset($headers[$i]);
				if (!$nextIsStatus) {
					break;
				} else {
					continue;
				}
			}
			
			$parts = explode(":", $line, 2);
			if (!$parts[1]) {
				$statusCode = intval(preg_replace('/^http\/(\d|\.)+\s+/i', '', $parts[0]));
				
				// each time we find a new status header, stack the old block
				if (!sizeof($currentHeaderBlock)) {
					$currentHeaderBlock[] = $statusCode;
				} else {
					$previousHeaderBlock = $currentHeaderBlock;
					$currentHeaderBlock = array(
						0 => $statusCode,
						'__previousheader' => &$previousHeaderBlock
					);
				}
			} else {
				$idx = strtolower(trim($parts[0]));
				$val = ltrim($parts[1]);
				
				Request::addHeader($currentHeaderBlock, $idx, $val);
			}
			
			unset($headers[$i]);
		}
		
		return $returnBody ? array($currentHeaderBlock, implode("\n", $headers)) : $currentHeaderBlock;
	}
	
	// Accessor for above
	public static function parseDocument($str)
	{
		return Request::parseHeaders($str, true);
	}
	
	/**
	 * Sets a header's value, overriding any previously set
	 */
	public static function setHeader(&$headers, $k, $v)
	{
		$k = strtolower($k);
		if ($k == '__previousheader') {
			return false;
		} else if (!$k || $k == 0) {
			$headers[0] = $v;
		} else {
			$headers[$k] = $v;
		}
		return true;
	}
	
	/**
	 * Adds a value to a header, retaining any previously set.
	 * If $k is falsey, we are adding a new header block and so the
	 * current one should be pushed up in the chain.
	 */
	public static function addHeader(&$headers, $k, $v)
	{
		$k = strtolower($k);
		if ($k == '__previousheader') {
			return false;
		} else if (!$k || $k === '0') {
			$previousHeaderBlock = $headers;
			$headers = array(
				0 => $v,
				'__previousheader' => &$previousHeaderBlock
			);
		} else if (isset($headers[$k])) {
			if (!is_array($headers[$k])) {
				$headers[$k] = array($headers[$k]);
			}
			$headers[$k][] = $v;
		} else {
			$headers[$k] = $v;
		}
		return true;
	}
	
	/**
	 * Converts an array of header information (as generated by parseHeaders())
	 * into a string representation
	 */
	public static function getHeaderString($headerData)
	{
		$previous = isset($headerData['__previousheader']) ? $headerData['__previousheader'] : null;
		unset($headerData['__previousheader']);
		
		$string = Request::getStatusHeader($headerData[0]) . "\n";
		unset($headerData[0]);
		
		foreach ($headerData as $k => $v) {
			if (!is_array($v)) {
				$v = array($v);
			}
			foreach ($v as $val) {
				$string .= ucwords($k) . ": " . $val . "\n";
			}
		}
		
		if ($previous) {
			$string = Request::getHeaderString($previous) . $string;
		}
		
		return $string;
	}
	
	public static function getHeader($headerData, $key)
	{
		$key = strtolower($key);
		$val = isset($headerData[$key]) ? $headerData[$key] : false;
		return $val;
	}
	
	// return the full HTTP header string for a status code
	public static function getStatusHeader($code)
	{
		return 'HTTP/1.1 ' . $code . ' ' . Request::$STATUS_CODES[$code];
	}
	
	public static function isOKHeader($code)
	{
		return $code >= 200 && $code < 400 && $code != 305;
	}
	
	// this method works regardless of the server environment. Headers are stored with lowercase keys.
	private static function storeHeaders()
	{
		if (Request::$HTTP_HEADERS === null) {
			$headers = array();
			foreach ($_SERVER as $k => $v) {
				if (strpos($k, 'HTTP_') === 0 || in_array($k, Request::$OTHER_HTTP_HEADERS)) {
					$k = str_replace(array('HTTP_', '_'), array('', '-'), $k);
					$headers[strtolower($k)] = $v;
				}
			}
			Request::$HTTP_HEADERS = $headers;
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
			} else if (!isset($_SERVER['HTTP_USER_AGENT'])) {
				Request::$REQUEST_MODE = Request::RM_CLI;
			} else {
				Request::$REQUEST_MODE = Request::RM_HTTP;
			}
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
