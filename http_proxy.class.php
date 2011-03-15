<?php
 /*===============================================================================
	pWebFramework - HTTP Proxy interface
	----------------------------------------------------------------------------
	Provides a general interface for managing a request over an HTTP connection.
	
	To create a proxy handler, use the HTTPProxy::getProxy() factory method.
	This will load an appropriate subclass of HTTPProxy, given the restrictions
	present in your current server environment.
	
	These objects are fairly stateless, only the target URI for the request is
	stored. The returned response data is the return value from each of the HTTP
	method wrapper functions.
	----------------------------------------------------------------------------
	@author		Sam Pospischil <pospi@spadgos.com>
  ===============================================================================*/

require_once('headers.class.php');

interface IHTTPProxy
{
	public function __construct($url);
	
	// GET/POST/HEAD http method wrappers
	//	Each should return the result of the query, or FALSE on failure
	public function get($headers = array());
	
	public function post($data, $headers = array());
	
	public function head($headers = array());
	
	// :TODO: put, delete
	
	// send a Response object
	public function sendResponse($response, $method = 'GET');
	
	// set an intermediate proxy for the connection
	public function setHTTPProxy($uri, $user, $password);
}

//==============================================================================

abstract class HTTPProxy implements IHTTPProxy
{
	protected $uri;
	
	public function __construct($uri)
	{
		$this->setUri($uri);
	}
	
	public static function getProxy($url)
	{
		if (function_exists('curl_init')) {
			require_once('http_proxy_curl.class.php');
			return new ProxyCURL($url);
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
	}
}
?>
