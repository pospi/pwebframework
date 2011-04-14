<?php
 /*===============================================================================
	pWebFramework - cURL proxy
	----------------------------------------------------------------------------
	Handles HTTP requests using cURL
	----------------------------------------------------------------------------
	@author		Sam Pospischil <pospi@spadgos.com>
  ===============================================================================*/

class ProxyCURL extends HTTPProxy
{
	private $curl = null;
	
	public function get($headers = null)
	{
		$this->importHeaders($headers);
		curl_setopt($this->curl, CURLOPT_HTTPGET, true);
		curl_setopt($this->curl, CURLOPT_POST, false);
		
		return $this->makeRequest();
	}
	
	public function post($data, $headers = null)
	{
		$this->importHeaders($headers);
		curl_setopt($this->curl, CURLOPT_HTTPGET, false);
		curl_setopt($this->curl, CURLOPT_POST, true);
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, Request::getQueryString($data));
		
		return $this->makeRequest();
	}
	
	public function head($headers = null)
	{
		$this->importHeaders($headers);
		curl_setopt($this->curl, CURLOPT_HTTPGET, true);
		curl_setopt($this->curl, CURLOPT_POST, false);
		curl_setopt($this->curl, CURLOPT_NOBODY, true);
		
		return $this->makeRequest();
	}
	
	public function setHTTPProxy($uri, $user, $password)
	{
		curl_setopt($this->curl, CURLOPT_PROXY, $uri);
		curl_setopt($this->curl, CURLOPT_PROXYUSERPWD, "$user:$password");
		//curl_setopt($this->curl, CURLOPT_HTTPPROXYTUNNEL, true);
		//curl_setopt($this->curl, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
		//curl_setopt($this->curl, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
	}
	
	public function setUri($uri)
	{
		if ($this->curl) {
			curl_setopt($this->curl, CURLOPT_URL, $uri);
		} else {
			$this->curl = curl_init($uri);
		}
		parent::setUri($uri);
	}

	public function getError()
	{
		return curl_error($this->curl);
	}
	
	//==========================================================================
	
	private function makeRequest($postMethod = false)
	{
		$this->prepareCurl($postMethod);
		
		$response = curl_exec($this->curl);
		// we do not close the cURL resource so that we can interrogate it later
		
		return $response;
	}
	
	private function prepareCurl($post = false)
	{
		curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->curl, CURLOPT_AUTOREFERER, true);
		curl_setopt($this->curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($this->curl, CURLOPT_MAXREDIRS, 10);
		curl_setopt($this->curl, CURLOPT_HEADER, true);
		curl_setopt($this->curl, CURLOPT_CONNECTTIMEOUT, $this->timeout);

		//curl_setopt($this->curl, CURLOPT_STDERR, fopen(dirname(__FILE__) . '/curl.log', 'w'));
		//curl_setopt($this->curl, CURLOPT_VERBOSE, true);
	}
	
	private function importHeaders($headers)
	{
		if (!$headers) return;
		
		$headers = explode("\n", $headers->toString());
		foreach ($headers as $i => $header) {
			if (empty($header)) {
				unset($headers[$i]);
			}
		}
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, $headers);
	}
}
?>
