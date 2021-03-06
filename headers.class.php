<?php
 /*===============================================================================
	pWebFramework - HTTP header handling class
	----------------------------------------------------------------------------
	Provides functionality for dealing with HTTP headers.
	This class is used internally by Request, Response and HTTPProxy objects to
	manipulate and interrogate header information easily and reliably.

	Headers are passed between objects as array data:
		- keys are header names (in lowercase)
		- values are header values:
			- as a string, when only 1 of these headers exists
			- as an array, when multiple headers exist
		- header blocks earlier in the request chain recurse upwards
		  through each array's "previousheader" member
	----------------------------------------------------------------------------
	@package	pWebFramework
	@author		Sam Pospischil <pospi@spadgos.com>
	@since		5/4/2011
  ===============================================================================*/

class Headers implements ArrayAccess, Iterator, Countable
{
	// aside from server variables prefixed with 'HTTP_', these will also be retrieved by Request as HTTP header values
	public static $OTHER_HTTP_HEADERS = array('CONTENT_TYPE', 'CONTENT_LENGTH');

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

	// callbacks used to handle merging of special-case headers. By default, adding a header will just output it twice.
	private static $MERGE_HELPERS = array(
		'cookie' => 'mergeCookieHeaders',
	);

	protected $fields = array();

	public $previousHeader = null;			// header blocks can be stacked, so that we can parse multiple redirects etc and show the request history

	//================================================================================================================

	/**
	 * @param	$headers	- header block, as string
	 * 						- array of individual (joined) header lines
	 */
	public function __construct($headers = null)
	{
		if (is_string($headers) || (is_array($headers) && array_keys($headers) === range(0, count($headers) - 1))) {
			$this->parse($headers);
		} else {
			$this->setFields($headers);
		}
	}

	//========================================================================

	public function offsetGet($key)
	{
		$key = strtolower($key);
		return isset($this->fields[$key]) ? $this->fields[$key] : false;
	}

	/**
	 * Sets a header's value within the current header block ONLY
	 */
	public function offsetSet($k, $v)
	{
		$k = strtolower($k);
		if (!$k || $k === '0') {
			$this->fields[0] = $v;
		} else {
			$this->fields[$k] = $v;
		}
		return true;
	}

	public function offsetExists($key)
	{
		return isset($this->fields[$key]);
	}

	public function offsetUnset($key)
	{
		unset($this->fields[$key]);
	}

	/**
	 * Sets a header's value, overriding any previously set as well as any
	 * in previous header blocks.
	 */
	public function override($k, $v)
	{
		$this[$k] = $v;

		if (isset($this->previousheader)) {
			return $this->previousheader->override($k, $v);
		}
		return true;
	}

	/**
	 * Adds a value to a header, retaining any previously set.
	 * If $k is falsey, we are adding a new header block and so the
	 * current one should be pushed up in the chain.
	 *
	 * This function will not override values already present in the current
	 * header block - it appends them into subarrays instead.
	 * Use $headerObj[$k] = $v to explicitly set values.
	 *
	 * This function will also not override values in previous header blocks,
	 * if that is desired use override() instead.
	 *
	 * :NOTE: This is the only function that directly modifies the header stack
	 */
	public function add($k, $v)
	{
		$k = strtolower($k);
		if (!$k || $k === '0') {
			// push current block up if we aren't empty
			if (sizeof($this->fields)) {
				$this->pushHeaders();
			}

			$this->fields[0] = $v;
		} else if (isset($this->fields[$k])) {
			// check for a special-case merge handler for this header field
			if (isset(self::$MERGE_HELPERS[$k])) {
				$this->fields[$k] = call_user_func(array($this, self::$MERGE_HELPERS[$k]), $this->fields[$k], $v);
			} else {
				// if there isn't one, we just add the header twice
				if (!is_array($this->fields[$k])) {
					$this->fields[$k] = array($this->fields[$k]);
				}
				$this->fields[$k][] = $v;
			}
		} else {
			$this->fields[$k] = $v;
		}

		return true;
	}

	/**
	 * Removes a header from ALL header blocks
	 */
	public function erase($k)
	{
		$headers = &$this->fields;

		$k = strtolower($k);

		if (!$k || $k === '0') {
			unset($headers[0]);
		} else {
			unset($headers[$k]);
		}
		if (isset($this->previousHeader)) {
			$this->previousHeader->erase($k);
		}

		return true;
	}

	/**
	 * Set all header fields using an associative array
	 * @param array $arr mapping of header names to values
	 */
	public function setFields($arr)
	{
		if (!is_array($arr)) {
			return false;
		}
		$validArr = array();
		foreach ($arr as $k => $v) {
			$validArr[strtolower($k)] = $v;
		}
		$this->fields = $validArr;
		return true;
	}

	/**
	 * Merge headers from another Headers object into our own.
	 * If the headers already exist, two of the same name will be output (:TODO: should this append?)
	 */
	public function merge(Headers $other)
	{
		foreach ($other as $k => $v) {
			$this->add($k, $v);
		}
	}

	//========================================================================
	//	Iterator implementation. Iterates first level header block only.

	public function rewind() {
		reset($this->fields);
	}

	public function current() {
		return current($this->fields);
	}

	public function key() {
		return key($this->fields);
	}

	public function next() {
		return next($this->fields);
	}

	public function valid() {
		return key($this->fields) !== null;
	}

	public function count() {
		return count($this->fields);
	}

	//================================================================================================================

	/**
	 * Parses a string or array of lines, decoding HTTP headers as we go.
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
	 * @param	$returnBody	- if true, return remaining body content after parsing
	 * @return	FALSE on failure, TRUE on success, body text string when $returnBody is enabled
	 */
	public function parse($headers, $returnBody = false)
	{
		if (!is_array($headers)) {
			$headers = preg_split("(\r\n|\r|\n)", $headers);
		}

		foreach ($headers as $i => $line) {
			if (empty($line)) {			// keep going. there may be another header block coming.
				$nextLine = next($headers);
				prev($headers);
				$nextIsStatus = preg_match('/(^http\/(\d\.\d)\s+)|(\s+http\/(\d\.\d)$)/i', $nextLine);
				unset($headers[$i]);
				if (!$nextIsStatus) {
					break;
				} else {
					continue;
				}
			}

			$parts = explode(":", $line, 2);
			if (!isset($parts[1]) || !$parts[1]) {
				$statusCode = preg_replace('/^http\/(\d\.\d)\s+/i', '', $parts[0], -1, $count);

				if ($count > 0) {
					// response header
					$this->add(0, intval($statusCode));
				} else {
					$code = preg_replace('/\s+http\/(\d\.\d)/i', '', $parts[0]);
					if (is_numeric($code)) {
						// request header
						$this->add(0, $code . ' HTTP/1.1');
					} else {
						// malformed header with no value
						$this->add(strtolower(trim($parts[0])), $parts[1]);
					}
				}
			} else {
				// properly formed, normal header
				$idx = strtolower(trim($parts[0]));
				$val = ltrim($parts[1]);

				$this->add($idx, $val);
			}

			unset($headers[$i]);
		}

		return $returnBody ? implode("\n", $headers) : true;
	}

	// Accessor for above, returns the document body if one is found
	public function parseDocument($str)
	{
		return $this->parse($str, true);
	}

	// push the current header block up into a previously encountered one, recursively
	public function pushHeaders()
	{
		if (isset($this->previousHeader)) {
			// we have a previous block
			$this->previousHeader->pushHeaders();	// so keep pushing up
		} else {
			// we are the last in the chain
			$this->previousHeader = new Headers();	// so make a new empty spot for our values
		}

		// set the previous block's values to ours
		$this->previousHeader->fields = $this->fields;

		// empty our values out
		$this->fields = array();
	}

	//================================================================================================================

	/**
	 * Converts this set of headers (as generated by parse())
	 * into a string representation
	 */
	public function toString($includePrevious = false)
	{
		$string = '';

		if ($includePrevious && isset($this->previousHeader)) {
			$string = $this->previousHeader->toString() . "\n";
		}

		if (isset($this->fields[0])) {
			if (is_numeric($this->fields[0])) {
				$string .= Headers::getStatusLine($this->fields[0]) . "\n";	// response headers
			} else {
				$string .= $this->fields[0] . "\n";							// request headers
			}
		}

		foreach ($this->fields as $k => $v) {
			if (!$k) {
				continue;		// already did status line
			}
			if (!is_array($v)) {
				$v = array($v);
			}

			// correctly case header keys
			// :TODO: what about 'X-XSS-Protection', etc?
			$capitalizeNext = true;
			for ($i = 0, $max = strlen($k); $i < $max; $i++) {
				if (strpos('-', $k[$i]) !== false) {
					$capitalizeNext = true;
				} else if ($capitalizeNext) {
					$capitalizeNext = false;
					$k[$i] = strtoupper($k[$i]);
				}
			}

			foreach ($v as $val) {
				$string .= $k . ": " . $val . "\n";
			}
		}

		return $string;
	}

	/**
	 * Send all stored headers to the remote agent.
	 * This will not send previous header blocks, only the current one.
	 */
	public function send($headerBlock = null)
	{
		if (!isset($headerBlock)) {
			// sending ourselves.
			$headerBlock = $this->fields;
		}

		if (isset($headerBlock[0])) {
			header(Headers::getStatusLine($headerBlock[0]), false);
			unset($headerBlock[0]);
		}

		foreach ($headerBlock as $k => $v) {
			if (!is_array($v)) {
				$v = array($v);
			}

			// get correct casing of header fields
			$k = explode('-', $k);
			$k = array_map('ucfirst', $k);
			$k = implode('-', $k);

			foreach ($v as $val) {
				header(ucwords($k) . ": " . $val, false);
			}
		}
	}

	//============================================================================================================

	public function setStatusCode($code)
	{
		$this->fields[0] = intval($code);
	}

	public function getStatusCode()
	{
		return isset($this->fields[0]) ? $this->fields[0] : 0;
	}

	public function addJSONHeader()
	{
		$this->fields['content-type'] = 'application/json';
	}

	public function addXMLHeader()
	{
		$this->fields['content-type'] = 'application/xml';
	}

	public function ok()
	{
		return isset($this->fields[0]) && Headers::isOk($this->fields[0]);
	}

	public function isRedirect()
	{
		return isset($this->fields[0]) && Headers::isRedirectCode($this->fields[0]);
	}

	//============================================================================================================

	public function setRequestPathAndMethod($path, $verb = "GET")
	{
		$this->fields[0] = strtoupper($verb) . ' ' . $path . ' HTTP/1.1';
	}

	public function getRequestPathAndMethod()
	{
		if (isset($this->fields[0]) && preg_match('/^([A-Z]+)\s+(.*)\s+http\/\d\.\d$/iU', $this->fields[0], $matches)) {
			return array($matches[2], $matches[1]);
		}
		return null;
	}

	//============================================================================================================

	// Determine if the remote server sent us any cookies
	public function hasSetCookies()
	{
		return !empty($this->fields['set-cookie']);
	}

	// Retrieve all SERVER cookies being sent to us
	public function getSetCookies()
	{
		if (!isset($this->fields['set-cookie'])) {
			return false;
		}
		$cookies = is_array($this->fields['set-cookie']) ? $this->fields['set-cookie'] : array($this->fields['set-cookie']);

		$cookieData = array();
		foreach ($cookies as $c) {
			$thisCookie = $this->parseServerCookie($c);
			if ($thisCookie) {
				$cookieData[$thisCookie['name']] = $thisCookie;
			}
		}
		return $cookieData;
	}

	// Retrive all CLIENT cookies to be sent
	public function getCookies()
	{
		if (!isset($this->fields['cookie'])) {
			return false;
		}
		$cookies = is_array($this->fields['cookie']) ? $this->fields['cookie'] : array($this->fields['cookie']);

		$cookieData = array();
		foreach ($cookies as $c) {
			$cookieData = array_merge($cookieData, $this->parseClientCookie($c));
		}
		return $cookieData;
	}

	// Converts cookies sent from a server into an outgoing cookie headers object to pass to the next request
	public function createCookieResponseHeaders()
	{
		$cookies = $this->getSetCookies();
		if (!$cookies) {
			return new Headers();	// nothing to pass on
		}

		$cArray = array();
		foreach ($cookies as $name => $cookie) {
			if (isset($cookie['expires']) && $cookie['expires'] < time()) {
				continue;	// skip expired cookies
			}
			$cArray[] = urlencode($name) . '=' . urlencode($cookie['value']);
		}

		$newH = new Headers();
		$newH['cookie'] = implode('; ', $cArray);

		return $newH;
	}

	/**
	 * Cookie header parsers.
	 * :TODO: allow parsing cookies containing ; or = characters inside double quotes
	 */
	private function parseServerCookie($cookieStr)
	{
		$csplit = explode(';', $cookieStr);
		$cdata = array();
		foreach ($csplit as $data) {
			$cinfo = explode('=', $data);
			$cSubVal = $cinfo[1];
			$cSubName = trim($cinfo[0]);
			if ($cSubName == 'expires') {
				$cSubVal = strtotime($cSubVal);
			} else if ($cSubName == 'secure') {
				$cSubVal = 'true';
			} else if ($cSubName == 'max-age') {
				$cSubName = 'expires';
				$cSubVal = time() + intval($cSubVal);
			}
			if (in_array($cSubName, array('domain', 'expires', 'max-age', 'path', 'port', 'secure', 'version', 'comment', 'commenturl', 'discard'))) {
				$cdata[$cSubName] = $cSubVal;
			} else {
				$cdata['name'] = $cSubName;
				$cdata['value'] = urldecode($cSubVal);
			}
		}
		return isset($cdata['name']) ? $cdata : false;
	}

	private function parseClientCookie($cookieStr)
	{
		$cookieData = array();
		$cParts = explode(';', $cookieStr);
		foreach ($cParts as $part) {
			$part = explode('=', $part);
			$cookieData[trim($part[0])] = trim($part[1]);
		}
		return $cookieData;
	}

	//============================================================================================================

	private function mergeCookieHeaders($currHeader, $newHeader)
	{
		$newCookies = array_merge($this->parseClientCookie($currHeader), $this->parseClientCookie($newHeader));
		return http_build_query($newCookies, '', '; ');
	}

	//============================================================================================================

	// return the full HTTP header string for a status code
	public static function getStatusLine($code)
	{
		return (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1') . ' ' . $code . ' ' . Headers::$STATUS_CODES[$code];
	}

	public static function isOk($code)
	{
		return $code >= 200 && $code < 400 && $code != 305;
	}

	public static function isRedirectCode($code)
	{
		return $code >= 300 && $code < 400 && $code != 305;
	}
}
