<?php
 /*===============================================================================
	pWebFramework - HTTP Proxy interface
	----------------------------------------------------------------------------
	Provides a general interface for managing a request over an HTTP connection.

	To create a proxy handler, use the HTTPProxy::getProxy() factory method.
	This will load an appropriate subclass of HTTPProxy, given the restrictions
	present in your current server environment.

	These objects should maintain all internal state between request actions.
	The returned response data is the return value from each of the HTTP method
	wrapper functions.

	Child classes should follow redirects internally, mimicing cURL's
	CURLOPT_FOLLOWLOCATION option to follow any Location: headers. The returned
	request content should be in the same format as with cURL - namely, any
	redirects encountered should have their header block output before subsequent
	request data.
	This behaviour can be disabled with followRedirects(false)
	----------------------------------------------------------------------------
	@author		Sam Pospischil <pospi@spadgos.com>
  ===============================================================================*/

require_once('headers.class.php');
require_once('request.class.php');

interface IHTTPProxy
{
	public function __construct($url);

	/**
	 * GET/POST/HEAD http method wrappers
	 *	$headers is an object of type Headers
	 *	Each should return the result of the query, or FALSE on failure
	 *
	 * :NOTE: all these methods have a variants defined further down, which will
	 * decode headers into $this->headers and just return the document body
	 * @see getDocument(), postData(), putData(), putRawData() and readHeaders()
	 */
	public function get($headers = null);

	//	$data is an array of data to POST
	//	$files is an array of input names => file paths, or raw $_FILES array to upload with the request
	public function post($data, $headers = null, $files = array());

	public function head($headers = null);

	// $data is raw string data to send. You may wish to urlencode array data similarly to POSTing in some situations.
	public function put($rawData, $headers = null);

	// some DELETE requests may require you to send data - this is sent as a raw string
	public function delete($headers = null, $rawData = null);

	// send a Response object
	public function sendResponse($response, $method = 'GET');

	// set an intermediate proxy for the connection
	public function setHTTPProxy($uri, $user, $password);

	// retrieve any error messages
	public function getError();

	// did the last request connect successfully?
	public function wasConnectionOk();

	// did the last request complete successfully, or generate an HTTP error?
	public function wasResponseOk();

	// sets whether or not to follow HTTP redirects
	public function followRedirects($follow = true);

	// resets the proxy to a clean state, ready for a new request
	// not all implementations will need to extend this
	public function reset();
}

//==============================================================================

abstract class HTTPProxy implements IHTTPProxy
{
	protected $uri;
	protected $timeout = 30;
	protected $followRedirs = true;

	public $headers = null;		// Headers object of the previous request

	public function __construct($uri)
	{
		$this->setUri($uri);
	}

	public static function getProxy($url)
	{
		if (function_exists('curl_init')) {
			require_once('http_proxy_curl.class.php');
			return new ProxyCURL($url);
		} else if (function_exists('fsockopen')) {
			require_once('http_proxy_socket.class.php');
			return new ProxySocket($url);
		} else {
			return false;		// no proxy for you, sorry!
		}
	}

	// Send a response object over HTTP. Construct & send your own HTTP requests.
	public function sendResponse($response, $method = 'GET')
	{
		switch($method) {
			case 'POST':
				return $this->post($response->getOutput(), $response->getHeaders());
			case 'HEAD':
				return $this->head($response->getHeaders());
			default:
				return $this->get($response->getHeaders());
		}
	}

	public function setUri($uri)
	{
		$this->uri = $uri;
		return true;
	}

	public function setTimeout($secs)
	{
		$this->timeout = $secs;
	}

	public function followRedirects($follow = true)
	{
		$this->followRedirs = (bool)$follow;
	}

	// clean headers from previous result parsing
	public function reset()
	{
		$this->headers = null;
	}

	// detect connection errors. Usually, the absence of any response headers means we couldn't connect
	public function wasConnectionOk()
	{
		return isset($this->headers);
	}

	// detect HTTP error codes via the response headers object
	public function wasResponseOk()
	{
		return $this->headers->ok();
	}

	//==========================================================================
	// HTTP verb wrappers to automatically parse headers

	public function getDocument($headers = null)
	{
		$result = $this->get($headers);
		if ($result === false) {
			return false;
		}

		$this->headers = new Headers();
		return $this->headers->parseDocument($result);
	}

	public function postData($data, $headers = null, $files = array())
	{
		$result = $this->post($data, $headers, $files);
		if ($result === false) {
			return false;
		}

		$this->headers = new Headers();
		return $this->headers->parseDocument($result);
	}

	public function readHeaders($headers = null)
	{
		$result = $this->head($headers);
		if ($result === false) {
			return false;
		}

		$this->headers = new Headers();
		$this->headers->parseDocument($result);
		return $this->headers;
	}

	public function putData($data, $headers = null)
	{
		$result = $this->put($data, $headers);
		if ($result === false) {
			return false;
		}

		$this->headers = new Headers();
		return $this->headers->parseDocument($result);
	}

	public function deleteDocument($headers = null, $rawData = null)
	{
		$result = $this->delete($headers, $rawData);
		if ($result === false) {
			return false;
		}

		$this->headers = new Headers();
		return $this->headers->parseDocument($result);
	}
}
?>
