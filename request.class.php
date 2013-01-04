<?php
 /*===============================================================================
	pWebFramework - request handler class
	----------------------------------------------------------------------------
	Provides a global object Request which can be statically accessed to
	interrogate the remote agent responsible for initiating a connection. Such
	tasks include input sanitisation from $_GET, $_POST and others; request
	mode (http, ajax, cli, ...) etc
	----------------------------------------------------------------------------
	@package	pWebFramework
	@author		Sam Pospischil <pospi@spadgos.com>
	@since		22/4/2010
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
define('EXPECT_JSON',	7);		// expects any JSON, and parses given string into its full representation
define('EXPECT_JSON_OBJECT',	8);		// expects a JSON *object* (primitives will fail), and parses given string into its full representation
define('EXPECT_JSON_AS_ARRAY',	9);		// same as EXPECT_JSON, except that the data is returned as an array instead of an stdClass object

abstract class Request
{
	// request mode
	const RM_HTTP = 0;
	const RM_AJAX = 1;
	const RM_CLI  = 2;

	private	static $REQUEST_MODE = null;
	private static $QUERY_PARAMS = null;
	private static $HTTP_HEADERS = null;
	private static $LOCAL_IP = null;	// cache these, they involve a name lookup which is potentially slow
	private static $LOCAL_HOST = null;

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

	/**
	 * Read from any request variable, in the given order.
	 * This is similar to reading from $_REQUEST intead of $_GET, $_POST etc
	 * @param  [string]	$key		request variable to look for
	 * @param  [const]	$expect		(optional) valid datatype of the request data to read
	 *                              @see the constants declared at the head of this file
	 * @param  [mixed]	$default	default value for the variable if not present
	 * @param  [array]	$allowable	(optional) allowable values to validate the input against
	 * @param  [string]	$order 		(optional) order in which to search request variables. Defaults
	 *                              to 'gpc', which is the same as usually defined in php.ini's
	 *                              variables_order directive, excluding searching in $_SESSION.
	 * @param  [bool]   $required	(optional, default false) whether or not the variable must be present
	 * @return [mixed]
	 */
	public static function read($key, $expect = EXPECT_RAW, $default = null, $allowable = null, $order = 'gpc', $required = false)
	{
		while (isset($order{0}) && $c = $order{0}) {
			$order = substr($order, 1);
			switch ($c) {
				case 'g':
					$method = 'get';
					break;
				case 'p':
					$method = 'post';
					break;
				case 'c':
					$method = 'cookie';
					break;
			}
			if ($method) {
				$result = call_user_func(array('Request', $method), $key, $expect, $allowable, null);
				if ($result !== null) {
					return $result;
				}
			}
		}

		if ($required) {
			$dummy = null;
			Request::sanitisationError($dummy, "Required request variable '$key' not found in %SOURCE%");
		}
		return $default;
	}

	/**
	 * Simplified form of read() where the variable is required to be present
	 * @param  [string]	$key       request variable to look for
	 * @param  [const]	$expect    (optional) valid datatype of the request data to read
	 *                              @see the constants declared at the head of this file
	 * @param  [array]	$allowable (optional) allowable values to validate the input against
	 * @return [mixed]
	 */
	public static function need($key, $expect = EXPECT_RAW, $allowable = null)
	{
		return Request::read($key, $expect, null, $allowable, 'gpc', true);
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
		return is_scalar($arr) ? $arr : http_build_query($arr);
	}

	/**
	 * Generates a queryString using getQueryString(), and prepends the page
	 * URL specified.
	 *
	 * The URL may contain its own querystring, in which case parameters passed in (if there)
	 * will override those already encoded. If no params are passed, the encoded parameters will
	 * override those passed in to the request.
	 *
	 * Accepts 4 parameter formats:
	 * 	[]				<-- returns the current page and querystring (consider using getFullURI())
	 * 	[string]		<-- returns the current querystring pointed to a new page
	 * 	[array]			<-- returns the current page with passed in querystring
	 * 	[string, array]	<-- returns URL for the passed in page and querystring
	 */
	public static function getURLString()
	{

		$page = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';	// we *could* use getFullURI() to get this, but this method keeps links relative
		$params = array();
		$paramsPassed = false;
		$a = func_get_args();

		if (sizeof($a) == 1) {
			if (is_array($a[0])) {	// current page, querystring passed
				$params = $a[0];
				$paramsPassed = true;
			} else {				// different page, same querystring
				$page = $a[0];
			}
		} else if (sizeof($a) == 2) {	// both different
			list($page, $params) = $a;
			$paramsPassed = true;
		}

		// check page for existing querystring
		$qPos = strpos($page, '?');
		if ($qPos !== false) {
			$currQuery = substr($page, $qPos + 1);
			parse_str($currQuery, $currParams);
			$params = array_merge($currParams, $params);
			$page = substr($page, 0, $qPos);
		}

		// if no query params were passed in, take the request ones as our base
		if (!$paramsPassed) {
			Request::storeQueryParams();
			$params = array_merge(Request::$QUERY_PARAMS, $params);
		}
		$query = Request::getQueryString($params);

		return $query ? $page . '?' . Request::getQueryString($params) : $page;
	}

	/**
	 * Resolve two URIs against one another. The resulting URL will be the same as
	 * a browser navigating from $sourceUri to $destUri.
	 *
	 * @param  string $sourceUri source URI to resolve against. This must be an absolute URI. If the resource is a directory it must end in a trailing slash.
	 * @param  string $destUri   destination URI to navigate to
	 * @return the absolute URI determined by resolving $destUrl against $sourceUrl
	 */
	public static function resolveURIs($sourceUri, $destUri)
	{
		$url = parse_url($destUri);
		if (isset($url['scheme'])) {
			return $destUri;	// already absolute
		}

		$srcUrl = parse_url($sourceUri);

		$uriStart = "{$srcUrl['scheme']}://";
		if (isset($srcUrl['user']) || isset($srcUrl['pass'])) {
			$uriStart .= "{$srcUrl['user']}:{$srcUrl['pass']}@";
		}
		$uriStart .= $srcUrl['host'];

		if (isset($srcUrl['port'])) {
			$uriStart .= ":{$srcUrl['port']}";
		}

		if (strpos($destUri, '/') === 0) {
			return $uriStart . $destUri;	// server-relative
		}

		// must be file-relative. Determine base dir.
		$path = $srcUrl['path'];
		if (substr($path, -1) !== '/') {
			$parts = explode('/', $path);
			array_pop($parts);
			$path = implode('/', $parts);
		}

		// strip out CWDs & double slashes
		$finalUri = preg_replace('@(/\./)|(/+)@', '/', $path . '/' . $destUri);

		// strip out relative dirs
		$parts = explode('/', $finalUri);
		$dirs = array();
		$count = 1;
		foreach ($parts as $part) {
			if ($part === '') {
				continue;
			}
			if ($part === "..") {
				unset($dirs[$count - 1]);
			} else {
				$dirs[$count] = $part;
			}
			++$count;
		}

		return $uriStart . '/' . implode('/', $dirs);
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
	// this method will not work under CLI
	public static function getRemoteIP()
	{
		return isset($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : (
			isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null)
		);
	}

	// Return the local IP address of this server. Will work under CLI with PHP > 5.3.
	public static function getLocalIP()
	{
		if (isset(self::$LOCAL_IP)) {
			return self::$LOCAL_IP;
		}
		if (Request::getRequestMethod() == self::RM_CLI) {
			$ips = gethostbynamel(self::getHTTPHost());
			$ip = '127.0.0.1';
			foreach ($ips as $oip) {
				if ($oip != '127.0.0.1') {
					$ip = $oip;
					break;
				}
			}
			self::$LOCAL_IP = $ip;
		} else {
			self::$LOCAL_IP = $_SERVER['SERVER_ADDR'];
		}
		return self::$LOCAL_IP;
	}

	// retrieve the document root without relying on apache's environment var (which is obviously not present under IIS)
	// this method will not work under CLI
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

	// Return this server's hostname
	public static function getHTTPHost()
	{
		if (isset(self::$LOCAL_HOST)) {
			return self::$LOCAL_HOST;
		}
		if (Request::getRequestMethod() == self::RM_CLI) {
			self::$LOCAL_HOST = function_exists('gethostname') ? gethostname() : php_uname('n');
		} else {
			// some agents may send the port number as part of the hostname
			self::$LOCAL_HOST = preg_replace('/\:\d+$/', '', $_SERVER['HTTP_HOST']);
		}
		return self::$LOCAL_HOST;
	}

	// only works under apache
	public static function getFullURI($withQuery = true)
	{
		$start = empty($_SERVER['HTTPS']) ? 'http://' : 'https://';

		$host = self::getHTTPHost();

		return $start . $host . ($_SERVER['SERVER_PORT'] == 80 ? '' : ':' . $_SERVER['SERVER_PORT']) . ($withQuery ? $_SERVER['REQUEST_URI'] : substr($_SERVER['REQUEST_URI'], 0, strpos($_SERVER['REQUEST_URI'], '?')));
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
			if (!$matchAll && !preg_match($expect, $var, $matches)) {
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
				case EXPECT_JSON_OBJECT:
				case EXPECT_JSON_AS_ARRAY:
					if (is_string($var)) {
						$o = json_decode($var, $expect == EXPECT_JSON_AS_ARRAY);
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
						if ($expect == EXPECT_JSON_OBJECT && !is_object($o)) {
							$var = null;
						} else {
							$var = $o;
						}
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
		$msg = str_replace('%SOURCE%', $place, $msg);
		if ($critical && class_exists(pwebframework::$requestExceptionClass)) {
			throw new pwebframework::$requestExceptionClass($msg);
		}
		trigger_error($msg, ($critical ? E_USER_ERROR : E_USER_WARNING));
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
