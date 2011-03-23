<?php
 /*===============================================================================
	pWebFramework - HTTP header handling class
	----------------------------------------------------------------------------
	Provides a library of functions for dealing with HTTP headers.
	This class is used by Request, Response and HTTPProxy objects to manipulate
	and interrogate header information easily and reliably.
	
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
 
class Headers
{
	const STATUS = 0;		// this needs a constant, only because the parameter is not obvious
							// to external code. It is a pointer to array index 0, used to set the HTTP
							// status code. Use it as in Headers::setHeader(Headers::STATUS, 404); and similar.
	
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
	
	//================================================================================================================
	
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
	 * @return	array representing headers passed
	 */
	public static function parse($headers, $returnBody = false)
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
				
				Headers::addHeader($currentHeaderBlock, $idx, $val);
			}
			
			unset($headers[$i]);
		}
		
		return $returnBody ? array($currentHeaderBlock, implode("\n", $headers)) : $currentHeaderBlock;
	}
	
	// Accessor for above
	public static function parseDocument($str)
	{
		return Headers::parse($str, true);
	}
	
	/**
	 * Converts an array of header information (as generated by parse())
	 * into a string representation
	 */
	public static function toString($headerData)
	{
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
			$string = Headers::toString($previous) . $string;
		}
		
		return $string;
	}
	
	public static function getHeader($headerData, $key)
	{
		$key = strtolower($key);
		$val = isset($headerData[$key]) ? $headerData[$key] : false;
		return $val;
	}
	
	/**
	 * Sets a header's value, overriding any previously set
	 */
	public static function setHeader(&$headers, $k, $v)
	{
		$k = strtolower($k);
		if ($k == '__previousheader') {
			return false;
		} else if (!$k || $k === '0') {
			$headers[0] = $v;
		} else {
			$headers[$k] = $v;
		}
		if (isset($headers['__previousheader'])) {
			return Header::setHeader($headers['__previousheader'], $k, $v);
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
	
	public static function eraseHeader(&$headers, $k)
	{
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
