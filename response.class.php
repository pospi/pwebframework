<?php
 /*===============================================================================
	pWebFramework - response handler class
	----------------------------------------------------------------------------
	An abstract response class for handling nonspecific output functionality.
	This class should be subclassed to provide specific output formatting for
	each type of request mode (HTML, CLI, AJAX etc).
	----------------------------------------------------------------------------
	@author		Sam Pospischil <pospi@spadgos.com>
	@date		2010-07-08
  ===============================================================================*/

abstract class Response
{
	//==================================================================================================================
	//		HTTP header handling

	// Call this to abort a script if headers have already been sent
	public static function checkHeaders()
	{
		$file = $line = '';
		if (headers_sent($file, $line)) {
			trigger_error("Attempted to set headers, but already set by $file:$line");
		}
	}
}

?>
