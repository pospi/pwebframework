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
 * @package pWebFramework
 * @author  Sam Pospischil <pospi@spadgos.com>
 * @since	29/1/2012
 */

require_once('processlogger.class.php');
require_once('http_proxy.class.php');
require_once('css_selector.class.php');

class WebWalker
{
	private $log;
	private $currentPage;

	public $passCookies = true;		// if true, pass cookies from set-cookie along with requests
	private $sendHeaders = null;	// Headers object storing cookie data if $passCookies is true

	public $currentURL;

	private $storedVars = array();		// arbitrary data storage for use in callbacks

	// caching variables
	private $cacheDir = false;
	private $cacheFileTemplate;
	private $requestCount = 0;

	private $useCache = false;	// read from cache
	const CACHEFILE_PAD_LEN = 6;	// pad length for cached request numbers in filenames ('000006' etc). Determines overall maximum cache size.

	public function __construct(ProcessLogger $logger = null)
	{
		$this->log = $logger;
	}

	/**
	 * Walk a map of pages based on data within the DOM
	 *
	 * @param  array $dataMap map of data to crawl. This array may contain the following elements:
	 *      'url'		: url to load.
	 *      'method'	: method for the request. Defaults to GET.
	 *		'targets'	: mapping of CSS selectors matching elements in the dom to callbacks to
	 *					  run against each matched element in the set. If the key is 0 or an empty string, the target applies to the entire page.
	 *      'data'		: array of data to send with the request when using POST method
	 *      'validate'	: A callback function for validating the input page HTML. This method receives the LibXML error and HTML string as parameters.
	 *      			  If a page is determined to be invalid, you should throw an MalformedPageException to indicate to WebWalker to skip that page.
	 *	For example:
	 *		'url' => '...',
	 *		'targets' => array(
	 *			'some css expression' => function($walker, $node) {
	 *				// you can interrogate each matched node in turn within these calbacks,
	 *				// fire further requests, perform actions, store variables for child requests,
	 *				// whatever.
	 *			},
	 *		)
	 *
	 * :TODO: make use of callback return values
	 */
	public function walk($dataMap)
	{
		@set_time_limit(0);

		// get the url to read
		$url = $dataMap['url'];
		$method = isset($dataMap['method']) ? $dataMap['method'] : null;
		$data = isset($dataMap['data']) ? $dataMap['data'] : array();

		// read the page HTML
		list($page, $headers) = $this->getPage($url, $method, $data);

		// initialise the current page so that callbacks can select from it if necessary
		$newPage = new SelectorDOM($page, isset($dataMap['validate']) ? $dataMap['validate'] : null);
		if ($newPage->dom === false) {
			return;
		}
		$this->currentPage = $newPage;
		$this->currentURL = $url;

		// check the response for cookies and store to the cookie cache if found
		if ($this->passCookies && isset($headers) && $headers->hasSetCookies()) {
			if (!$this->sendHeaders) {
				$this->sendHeaders = new Headers();
			}
			$cookieHeaders = $headers->createCookieResponseHeaders();
			$this->sendHeaders->merge($cookieHeaders);
		}

		$this->indentLog();

		// find all the target elements and process them in turn
		foreach ($dataMap['targets'] as $selector => $callback) {
			// if the selector applies to no specific element, run it
			if (!$selector) {
				$returnVal = call_user_func($callback, $this, $this->currentPage->dom);
				continue;
			}

			$domNodes = $this->select($selector);

			// go through all matched DOM nodes and process the callback against them
			$domResults = array();
			foreach ($domNodes as $i => $domEl) {
				$returnVal = call_user_func($callback, $this, $domEl);
				if ($returnVal === false) {
					// chain broken, abort
					break;
				}
				$domResults[$i] = $returnVal;
			}
		}

		$this->unindentLog();
	}

	/**
	 * Clears all persistent cookies being sent with this WebWalker's requests
	 */
	public function clearCookies()
	{
		$this->sendHeaders = null;
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
		$elements = $xpath->evaluate(SelectorDOM::selector_to_xpath($css));
    	return $asArray ? SelectorDOM::elements_to_array($elements) : $elements;
	}

	/**
	 * Retrieve the inner text content of a node
	 * @param  DOMElement $node node to get text for
	 * @return string
	 */
	public function innerText($node)
	{
		return isset($node->nodeValue) ? trim($node->nodeValue) : null;
	}

	//----------------------------------------------------------------------------------
	//	Remote access

	/**
	 * Downloads a URL to a local file, retrying up to 5 times if failed
	 * @param  [string] $url        URL to download data from
	 * @param  [string] $destFolder destination folder to download the file to
	 * @param  [bool]	$replace	set to false to skip downloading the file if it already exists
	 */
	public function download($url, $destFolder, $replace = true)
	{
		$destPath = rtrim($destFolder, ' /') .'/'. basename($url);

		if (!$replace && file_exists($destPath)) {
			$this->log("Skip downloading file $url - already present");
			return;
		}

		$this->log("Downloading file $url");
		$this->indentLog();

		$retries = 0;
		while ($retries < 5) {
			$data = @file_get_contents($url);
			if (false !== $data) {
				file_put_contents($destPath, $data);
				break;
			}
			$retries++;
			$this->log("Download failed. Retrying ($retries)...");
		}
		$this->unindentLog();
	}

	//----------------------------------------------------------------------------------
	//	Caching

	/**
	 * Passing a directory path to this method will cause all files encountered
	 * to be saved here for later use.
	 * @param  mixed  $toDir    directory to write to, or FALSE to disable page caching
	 * @param  string $template	filename prefix template, 1st specifier is url, 2nd is request number wildcard.
	 *                          All files are suffixed by date('ymd-His') and the extension HTML.
	 */
	public function cachePages($toDir = false, $template = 'request-%2$s_%1$s_')
	{
		$this->cacheDir = $toDir;
		$this->cacheFileTemplate = $template;
	}

	/**
	 * Enable reading of locally cached files instead of remote files, if present on disk already
	 * @param  boolean $read whether or not to enable caching
	 */
	public function readFromCache($read = true)
	{
		$this->useCache = $read;
	}

	//----------------------------------------------------------------------------------
	//	Internals

	private function getPage($url, $method = null, $data = array())
	{
		++$this->requestCount;

		// check for a cached request file
		if ($this->useCache && $this->cacheDir) {
			$caches = glob(rtrim($this->cacheDir, ' /') . '/' . sprintf($this->cacheFileTemplate, '*', str_pad($this->requestCount, self::CACHEFILE_PAD_LEN, '0', STR_PAD_LEFT)) . '*.html');

			if (count($caches) == 1) {
				$this->log("Reading cached page for $url");
				return array(file_get_contents($caches[0]), new Headers(@file_get_contents($caches[0] . '.headers')));
			}
		}

		// make the request if not cached
		$this->log("Requesting page: $url");

		$request = HTTPProxy::getProxy($url);
		$time = microtime(true);

		if (strtolower($method) == 'post') {
			$document = $request->postData($data, $this->sendHeaders);
		} else {
			$document = $request->getDocument($this->sendHeaders);
		}

		if ($this->cacheDir) {
			$cachePath = rtrim($this->cacheDir, ' /') . '/'
					. sprintf($this->cacheFileTemplate,
						preg_replace('&[^a-zA-Z0-9@\._\-=+\(\)\[\]]&', '_', str_replace(array('http://', 'https://'), '', $url)),
						str_pad($this->requestCount, self::CACHEFILE_PAD_LEN, '0', STR_PAD_LEFT)
					) . date('ymd-His') . '.html';

			// write response page & headers to cache
			file_put_contents($cachePath, $document);
			file_put_contents($cachePath . '.headers', $request->headers->toString());
		}

		$this->log("Request completed in " . ProcessLogger::since($time) . "s");

		return array($document, $request->headers);
	}

	//----------------------------------------------------------------------------------
	//	Logging & error handling

	public function error($msg)
	{
		if (pwebframework::$crawlerExceptionClass) {
			throw new pwebframework::$crawlerExceptionClass($msg);
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
