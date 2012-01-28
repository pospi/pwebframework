<?php
/**
 * WebWalker
 *
 * A callback-based web crawler for reading data from remote HTML pages.
 * In testing single threaded mode, takes about 2 hours to run 970K+
 * requests on a 512k DSL connection, and consumes about 7MB of ram at
 * maximum when performing some simple database writes on the data being read.
 *
 * :TODO: threading
 *
 * @depends PHP 5.3
 * @depends pWebFramework
 * @depends CSS Selector
 * @author  Sam Pospischil <pospi@spadgos.com>
 */
class WebWalker
{
	public static $ExceptionClass = null;	// set this to have the class throw exceptions instead of errors

	private $log;
	private $currentPage;
	public $currentURL;

	private $storedVars = array();		// arbitrary data storage for use in callbacks

	public function __construct(ProcessLogger $logger = null)
	{
		$this->log = $logger;
	}

	/**
	 * Walk a map of pages based on data within the DOM
	 * @param  array $dataMap map of data to crawl. This array takes the following format:
	 *      'url'		: url to load.
	 *		'targets'	: mapping of CSS selectors matching elements in the dom to arrays containing
	 *					  attributes or properties to run regexes against. These properties can then be
	 *					  referenced in the 'next' block.
	 *					  To specify attributes, prefix the property with an @ symbol. You can also use
	 *					  the keys 'name' for node name and 'text' for innerText string.
	 *					  This array may also contain the property 'required', which can be set explicitly
	 *					  false when this DOM element does not need to be found to complete the request.
	 *		'next'		: an array (or array of arrays to branch requests) formatted the same as the top
	 *					  level array (with url, targets and optional 'next' block of their own).
	 *					  Urls in subsequent arrays may substitute in values from the previous targets.
	 *					  The format for this is {[TARGET_IDX]#[ATTRIB_NAME]#[REGEX_SUBSTITUTION_STR]}
	 *	For example:
	 *		'url' => '...',
	 *		'targets' => array(
	 *			'some css expression' => array(
	 *				'[href]' => '@(\w|-|/)*(\d+)\?@U',	// pull some stuff out of an element's href attribute
	 *			),
	 *		),
	 *		'next' => array(
	 *			'url' => '{0#[href]#$2$1}'				// swap it around in the output
	 *			'targets' => ...
	 *		)
	 * @param  array $urlVars variables from interrogating the previous page's DOM
	 */
	public function walk($dataMap)
	{
		@set_time_limit(0);

		// get the url to read
		$url = $dataMap['url'];

		// read the page HTML
		$page = $this->getPage($url);

		// initialise the current page so that callbacks can select from it if necessary
		$this->currentURL = $url;
		$this->currentPage = new SelectorDOM($page);

		$targetIdx = 0;

		$this->indentLog();

		// find all the target elements and process them in turn
		foreach ($dataMap['targets'] as $selector => $callback) {
			$domNodes = $this->select($selector);

			// go through all matched DOM nodes and process the callback against them
			$domResults = array();
			foreach ($domNodes as $i => $domEl) {
				$returnVal = $callback($this, $domEl);
				if ($returnVal === false) {
					// chain broken, abort
					break;
				}
				$domResults[$i] = $returnVal;
			}

			$targetIdx++;
		}

		$this->unindentLog();
	}

	/**
	 * Store some variable into the WebWalker instance to allow
	 * retrieving it from a different page callback
	 * @param  string $key variable name
	 * @param  mixed  $val variable value for storage
	 */
	public function storeVar($key, $val)
	{
		$this->storedVars[$key] = $val;
	}
	public function getVar($key)
	{
		return isset($this->storedVars[$key]) ? $this->storedVars[$key] : null;
	}
	public function removeVar($key)
	{
		unset($this->storedVars[$key]);
	}

	/**
	 * Perform a CSS selector expression on the currently loaded document.
	 * Mainly useful inside walker callback functions.
	 * @param  string $css css expression to compute against the page
	 * @return array
	 */
	public function select($css, $asArray = false)
	{
		return $this->currentPage->select($css, $asArray);
	}

	/**
	 * Perform a CSS selector on a node. You must retrieve nodes as DOM objects (not arrays)
	 * for this to work.
	 */
	public function child($node, $css, $asArray = false)
	{
		$newDocument = new DOMDocument();
		$node = $newDocument->importNode($node, true);
		$newDocument->appendChild($node);
		$xpath = new DOMXpath($newDocument);
		$elements = $xpath->evaluate(selector_to_xpath($css));
    	return $asArray ? elements_to_array($elements) : $elements;
	}

	/**
	 * Retrieve the inner text content of a node
	 * @param  DOMElement $node node to get text for
	 * @return string
	 */
	public function innerText($node)
	{
		return trim($node->nodeValue);
	}

	//----------------------------------------------------------------------------------
	//	Internals

	private function getPage($url)
	{
		$this->log("Requesting page: $url");

		$request = HTTPProxy::getProxy($url);
		$time = microtime(true);
		$document = $request->getDocument();

		$this->log("Request completed in " . ProcessLogger::since($time) . "s");

		return $document;
	}

	//----------------------------------------------------------------------------------
	//	Logging & error handling

	public function error($msg)
	{
		if (self::$ExceptionClass) {
			throw new self::$ExceptionClass($msg);
		}
		trigger_error($msg, E_USER_ERROR);
	}

	private function log($line)
	{
		if ($this->log) {
			$this->log->t($line);
		}
	}

	private function indentLog()
	{
		if ($this->log) {
			$this->log->indent();
		}
	}

	private function unindentLog()
	{
		if ($this->log) {
			$this->log->unindent();
		}
	}
}
