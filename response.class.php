<?php
 /*===============================================================================
	pWebFramework - response handler class
	----------------------------------------------------------------------------
	An abstracted response class for handling nonspecific output functionality.
	
	Allows setting and manipulating HTTP headers & page output blocks. Nothing
	is affected until the response is actually sent, leaving headers clean for
	the duration of script.
	
	To create more complex output logic, output blocks should be implemented as
	string-convertible objects, with specific data formatting for request mode
	types handled internally. Additionally, output blocks may be nested in
	subarrays and manipulated as related sets.
	----------------------------------------------------------------------------
	@author		Sam Pospischil <pospi@spadgos.com>
	@date		2010-07-08
  ===============================================================================*/

class Response
{
	// response types (feel free to add others)
	const RESP_HTML		= 0;
	const RESP_JSON		= 1;
	const RESP_PLAINTEXT= 2;
	
	private	$headers = array();
	private $outputBlocks = array();
	
	//==========================================================================
	//		Output handling
	
	/**
	 * Add some generic output block to the response.
	 *
	 * @param	mixed	$block	the output block to add. This may be:
	 * 							- any string to echo as-is
	 * 							- an object to be decoded via its __toString() method
	 * 							- a nested array of the above to be decoded together
	 * @param	int		$at		position within existing output blocks to add.
	 * 							if the index exists, block is inserted there and all following are shifted up
	 */
	public function addBlock($block, $at = -1)
	{
		array_splice($this->outputBlocks, $at, 0, $block);
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
	
	//==========================================================================
	//		HTTP header handling
	
	// set an HTTP header directly
	public function setHeader($key, $val)
	{
		$this->headers[$key] = $val;
	}
	
	public function getHeaders()
	{
		return $this->headers;
	}
	
	public function getHeader($key)
	{
		return isset($this->headers[$key]) ? $this->headers[$key] : null;
	}
	
	public function sendHeaders()
	{
		Response::checkHeaders();
		foreach ($this->headers as $name => $value) {
			header("$name: $value");
		}
	}

	// Call this to abort a script if headers have already been sent
	public static function checkHeaders()
	{
		$file = $line = '';
		if (headers_sent($file, $line)) {
			trigger_error("Attempted to set headers, but already set by $file:$line");
		}
	}
	
	//==========================================================================
	
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
}

?>
