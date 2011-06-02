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
	This behaviour can be disabled with
	----------------------------------------------------------------------------
	@author		Sam Pospischil <pospi@spadgos.com>
  ===============================================================================*/

require_once('headers.class.php');

interface IHTTPProxy
{
	public function __construct($url);

	// GET/POST/HEAD http method wrappers
	//	$headers is an object of type Headers
	//	Each should return the result of the query, or FALSE on failure
	public function get($headers = null);

	//	$data is an array of data to POST
	public function post($data, $headers = null);

	public function head($headers = null);

	// :TODO: put, delete

	// send a Response object
	public function sendResponse($response, $method = 'GET');

	// set an intermediate proxy for the connection
	public function setHTTPProxy($uri, $user, $password);

	// retrieve any error messages
	public function getError();

	// sets whether or not to follow HTTP redirects
	public function followRedirects($follow = true);
}

//==============================================================================

abstract class HTTPProxy implements IHTTPProxy
{
	protected $uri;
	protected $timeout = 30;
	protected $followRedirs = true;

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
}
?>
