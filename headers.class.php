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
		  through each array's "__previousheader" value
	----------------------------------------------------------------------------
	@author		Sam Pospischil <pospi@spadgos.com>
  ===============================================================================*/
 
class Headers implements ArrayAccess
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

	private $fields = array();
	
	//================================================================================================================

	/**
	 * @param	$headers	- header block, as string
	 * 						- array of individual (joined) header lines
	 */
	public function __construct($headers = null)
	{
		if ($headers) {
			$this->fields = $this->parse($headers);
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
		if ($k == '__previousheader') {
			return false;
		} else if (!$k || $k === '0') {
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
	public function override($k, $v, &$arr = null)
	{
		if (!isset($arr)) {
			$arr = &$this->fields;
		}

		$k = strtolower($k);
		if ($k == '__previousheader') {
			return false;
		} else if (!$k || $k === '0') {
			$arr[0] = $v;
		} else {
			$arr[$k] = $v;
		}
		if (isset($arr['__previousheader'])) {
			return $this->override($k, $v, $arr['__previousheader']);
		}
		return true;
	}
	
	/**
	 * Adds a value to a header, retaining any previously set.
	 * If $k is falsey, we are adding a new header block and so the
	 * current one should be pushed up in the chain.
	 */
	public function add($k, $v)
	{
		$k = strtolower($k);
		if ($k == '__previousheader') {
			return false;
		} else if (!$k || $k === '0') {
			$previousHeaderBlock = $this->fields;
			$this->fields = array(
				0 => $v,
				'__previousheader' => &$previousHeaderBlock
			);
		} else if (isset($this->fields[$k])) {
			if (!is_array($this->fields[$k])) {
				$this->fields[$k] = array($this->fields[$k]);
			}
			$this->fields[$k][] = $v;
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
		do {
			if (!$k || $k === '0') {
				unset($headers[0]);
			} else {
				unset($headers[$k]);
			}
		} while (isset($headers['__previousheader']) && $headers = &$headers['__previousheader']);

		return true;
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
				if (!sizeof($this->fields)) {
					$this->fields[] = $statusCode;
				} else {
					$this->fields = array(
						0 => $statusCode,
						'__previousheader' => $this->fields
					);
				}
			} else {
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
	
	//================================================================================================================
	
	/**
	 * Converts this set of headers (as generated by parse())
	 * into a string representation
	 */
	public function toString($headerData = null)
	{
		$string = '';

		if (!isset($headerData)) {
			$headerData = $this->fields;
		}

		$previous = isset($headerData['__previousheader']) ? $headerData['__previousheader'] : null;
		unset($headerData['__previousheader']);
		
		if (isset($headerData[0])) {
			$string = Headers::getStatusLine($headerData[0]) . "\n";
			unset($headerData[0]);
		}
		
		foreach ($headerData as $k => $v) {
			if (!is_array($v)) {
				$v = array($v);
			}
			foreach ($v as $val) {
				$string .= ucwords($k) . ": " . $val . "\n";
			}
		}
		
		if ($previous) {
			$string = $this->toString($previous) . $string;
		}
		
		return $string;
	}

	/**
	 * Send all stored headers to the remote agent
	 */
	public function send($headerBlock = null)
	{
		Response::checkHeaders();	// die if output started
		if (!isset($headerBlock)) {
			$headerBlock = $this->fields;
		}
		
		// send earlier headers first
		if (isset($headerBlock['__previousheader'])) {
			$prev = $headerBlock['__previousheader'];
			unset($headerBlock['__previousheader']);
			
			$this->send($prev);
		}
		
		if (isset($headerBlock[0])) {
			header(Headers::getStatusLine($headerBlock[0]), false);
			unset($headerBlock[0]);
		}
		
		foreach ($headerBlock as $k => $v) {
			if (!is_array($v)) {
				$v = array($v);
			}
			foreach ($v as $val) {
				header(ucwords($k) . ": " . $val, false);
			}
		}
	}

	//============================================================================================================
 
	public function setStatusCode($code)
	{
		return $this->fields[0] = intval($code);
	}
 
	public function getStatusCode()
	{
		return isset($this->fields[0]) ? $this->fields[0] : 0;
	}

	public function ok()
	{
		return isset($this->fields[0]) && Headers::isOk($this->fields[0]);
	}

	//============================================================================================================
	
	// return the full HTTP header string for a status code
	public static function getStatusLine($code)
	{
		return 'HTTP/1.1 ' . $code . ' ' . Headers::$STATUS_CODES[$code];
	}
	
	public static function isOk($code)
	{
		return $code >= 200 && $code < 400 && $code != 305;
	}
}
?>
