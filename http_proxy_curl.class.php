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
	
	public function get($headers = array())
	{
		$this->importHeaders($headers);
		curl_setopt($this->curl, CURLOPT_HTTPGET, true);
		curl_setopt($this->curl, CURLOPT_POST, false);
		
		return $this->makeRequest();
	}
	
	public function post($data, $headers = array())
	{
		$this->importHeaders($headers);
		curl_setopt($this->curl, CURLOPT_HTTPGET, false);
		curl_setopt($this->curl, CURLOPT_POST, true);
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, Headers::toString($data));
		
		return $this->makeRequest();
	}
	
	public function head($headers = array())
	{
		$this->importHeaders($headers);
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
		$this->curl = curl_init($uri);
		parent::setUri($uri);
	}
	
	//==========================================================================
	
	private function makeRequest($postMethod = false)
	{
		$this->prepareCurl($postMethod);
		
		$response = curl_exec($this->curl);
		curl_close($this->curl);				// :TODO: persistent connections?
		
		return $response;
	}
	
	private function prepareCurl($post = false)
	{
		curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->curl, CURLOPT_AUTOREFERER, true);
		curl_setopt($this->curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($this->curl, CURLOPT_MAXREDIRS, 10);
		curl_setopt($this->curl, CURLOPT_HEADER, true);
	}
	
	private function importHeaders($headers)
	{
		$headers = explode("\n", Headers::toString($headers));
		foreach ($headers as $i => $header) {
			if (empty($header)) {
				unset($headers[$i]);
			}
		}
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, $headers);
	}
}
?>
