<?php
 /*===============================================================================
	pWebFramework - response handler class
	----------------------------------------------------------------------------
	An abstracted response class for handling nonspecific output functionality.

	Provides:
		- Header storage & manipulation
			Nothing	is affected until the response is actually sent, leaving
			headers clean for the duration of script.

		- Output handling
			Very generic output handling by way of decodable blocks. To create
			complex output logic, output blocks should be implemented as
			string-convertible objects, with specific data formatting for request
			mode types handled internally. Additionally, output blocks may be
			nested in subarrays and manipulated as related sets.

	All block-related functions accept index paramters of any of the forms:
		array key (int or string)	- array index in output blocks. first-level depth only.
		array(k1, k2, k3, ...kN)	- array of indices into child block arrays
		"k1.k2.k3.k4"				- period-separated block index
	----------------------------------------------------------------------------
	@author		Sam Pospischil <pospi@spadgos.com>
	@date		2010-07-08
  ===============================================================================*/

require_once('headers.class.php');

class Response
{
	// response types (feel free to add others)
	const RESP_HTML		= 0;
	const RESP_JSON		= 1;
	const RESP_PLAINTEXT= 2;

	private	$headers = null;
	private $outputBlocks = array();

	public function __construct()
	{
		$this->headers = new Headers();
	}

	//==========================================================================
	//		Output handling

	/**
	 * Add some generic output block to the response.
	 *
	 * @param	mixed	$block	the output block to add. This may be:
	 * 							- any string to echo as-is
	 * 							- an object to be decoded via its __toString() method
	 * 							- a nested array of the above to be decoded together
	 * @param	mixed	$name	array index to file this newly added block under
	 * @param	mixed	$before	index in existing output blocks to add at, or near.
	 * 							- if the index exists, block is inserted there and all following are shifted up
	 * 							- if index isn't found, FALSE is returned
	 *
	 * @return	true on success, false if an error occurred
	 */
	public function addBlock($block, $name = null, $before = null)
	{
		if ($before === null) {
			if (isset($name)) {
				$this->outputBlocks[$name] = $block;
			} else {
				$this->outputBlocks[] = $block;
			}
		} else if ($name !== null) {
			return $this->injectBlock($block, $before, $name, true);
		} else {
			return false;
		}
		return true;
	}

	// same as above, only it inserts after the target
	public function addBlockAfter($block, $name, $after)
	{
		return $this->injectBlock($block, $after, $name, false);
	}

	/**
	 * Sets an output block by index.
	 * This function will not recurse down & automatically create nonexistent block levels,
	 * if you wish to add nested ones you must explicitly add/set them.
	 *
	 * @param	mixed	$idx	array index to set, or
	 * 							array of indexes to recurse down through output blocks and set, or
	 * 							string of period-separated indexes (eg. "1.4.2.0")
	 * @param	mixed	$value	value to set the target block element to
	 */
	public function setBlock($idx, $value)
	{
		$parentAndIdx = &$this->getBlockParentAndIndex($idx, false);

		if ($parentAndIdx !== false) {
			$parentAndIdx[0][$parentAndIdx[1]] = $value;
			return true;
		}
		return false;
	}

	public function setAllBlocks($array)
	{
		$this->outputBlocks = $array;
	}

	// Send this entire response, then exit the script
	public function send()
	{
		$this->sendHeaders();
		echo $this->getOutput();
		exit;
	}

	/**
	 * Compute and retrieve our output by decoding output blocks.
	 *
	 * @see Response::addBlock()
	 */
	public function getOutput($blocks = null)
	{
		$output = "";

		if ($blocks === null) {
			$blocks = $this->outputBlocks;
		}

		foreach ($blocks as $block) {
			if (is_object($block) && method_exists($block, '__toString')) {
				$output .= $block->__toString();
			} else if (is_array($block)) {
				$output .= $this->getOutput($block);
			} else {
				$output .= $block;
			}
			$output .= "\n";
		}

		return $output;
	}

	private function injectBlock($block, $nearIdx, $name = null, $before = false)
	{
		$parentAndIdx = &$this->getBlockParentAndIndex($nearIdx, true);

		$keys = array_keys($parentAndIdx[0]);
		$values = array_values($parentAndIdx[0]);

		$start = array_search($parentAndIdx[1], $keys, true);
		if ($start === false) {
			return false;
		}

		if (!$before) {
			$start += 1;
		}

		array_splice($keys, $start, 0, $name);
		array_splice($values, $start, 0, $block);

		$parentAndIdx[0] = array_combine($keys, $values);

		return true;
	}

	/**
	 * Given an index into some subelement of the output blocks, returns an array of
	 * a reference to the parent container array element and final index into that array.
	 *
	 * @param	mixed	$idx	array index to get the block for, or
	 * 							array of indexes to recurse down through output blocks and retrieve, or
	 * 							string of period-separated indexes (eg. "1.4.2.0")
	 * @param	bool	$returnParent	if true, the array containing the target is returned. otherwise the element itself
	 */
	private function &getBlockParentAndIndex($idx, $returnParent = false)
	{
		if (is_string($idx)) {
			$idx = explode('.', $idx);
		} else if (is_int($idx)) {
			$idx = array($idx);
		}

		$current = &$this->outputBlocks;
		$counter = 0;
		foreach ($idx as $goto) {
			++$counter;
			if ($counter == sizeof($idx)) {
				$return = array(&$current, $idx[sizeof($idx) - 1]);
				return $return;		// last index
			} else if (isset($current[$goto]) && is_array($current[$goto])) {
				$current = &$current[$goto];
			} else {
				return false;		// index was not set
			}
		}

		return false;	// not found or no index passed
	}

	//==========================================================================
	//		HTTP header handling

	public function getHeaders()
	{
		return $this->headers;
	}

	public function setHeaders($headers)
	{
		$this->headers = $headers;
	}

	// set an HTTP header directly
	public function setHeader($key, $val)
	{
		$this->headers[$key] = $val;
	}
	public function addHeader($key, $val)
	{
		$this->headers->add($key, $val);
	}

	public function getHeader($key)
	{
		return $this->headers[$key];
	}

	public function sendHeaders($headers = null)
	{
		Response::checkHeaders();	// die if output started
		if (!isset($headers)) {
			$headers = $this->headers;
		}
		$headers->send();
	}

	// Call this to abort a script if headers have already been sent
	public static function checkHeaders()
	{
		$file = $line = '';
		if (headers_sent($file, $line)) {
			$msg = "Attempted to set headers, but already set by $file:$line";
			trigger_error($msg, E_USER_WARNING);
		}
	}

	//==========================================================================
	//	Header helpers

	// IMMEDIATELY redirects the remote agent to this URL, and aborts the current script
	public function redirect($uri)
	{
		$this->setHeader('Location', $uri);
		$this->send();
	}

	// sets a redirect but does not forward the browser straight away
	public function setRedirect($uri)
	{
		$this->setHeader('Location', $uri);
	}

	public function addJSONHeader()
	{
		$this->headers->addJSONHeader();
	}

	public function addXMLHeader()
	{
		$this->headers->addXMLHeader();
	}
}
