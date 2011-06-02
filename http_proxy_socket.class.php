<?php
 /*===============================================================================
	pWebFramework - raw socket proxy
	----------------------------------------------------------------------------
	Handles HTTP requests using raw PHP sockets
	----------------------------------------------------------------------------
	@author		Sam Pospischil <pospi@spadgos.com>
  ===============================================================================*/

class ProxySocket extends HTTPProxy
{
	private $conn = null;

	private $host;
	private $port = 80;
	private $path = '';

	private $lastError = '';

	//======================

	private $proxyHost = false;
	private $proxyPort = 8080;
	private $proxyHeaders = null;		// these are sent in addition to base headers

	public function get($headers = null)
	{
		return $this->sendRequest($headers);
	}

	public function post($data, $headers = null)
	{
		return $this->sendRequest($headers, "POST", Request::getQueryString($data));
	}

	public function head($headers = null)
	{
		return $this->sendRequest($headers, "HEAD");
	}

	public function setHTTPProxy($uri, $user, $password)
	{
		list($this->proxyHost, $this->proxyPort) = $this->parseUri($uri);

		if ($this->proxyHost) {
			$this->proxyHeaders = new Headers();
			$this->proxyHeaders['proxy-authorization'] = 'Basic ' . base64_encode($user . ':' . $password);
		}
	}

	public function setUri($uri)
	{
		list($this->host, $this->port, $this->path) = $this->parseUri($uri);
		if (!$this->host) {
			$this->lastError = "Badly formatted URI string";
			return false;
		}
		if ($this->conn) {
			fclose($this->conn);
			$this->conn = null;
		}

		return parent::setUri($uri);
	}

	public function getError()
	{
		return $this->lastError;
	}

	//==========================================================================

	private function makeConnection()
	{
		if ($this->conn) {
			fclose($this->conn);
			$this->conn = null;
		}
		if (!$this->proxyHost) {
			$this->conn = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
		} else {
			$this->conn = @fsockopen($this->proxyHost, $this->proxyPort, $errno, $errstr, $this->timeout);
		}
		if (!$this->conn) {
			$this->lastError = $errstr;
			return false;
		}
		return true;
	}

	private function sendRequest($headers, $verb = "GET", $contents = '', $prevHeaders = null)
	{
		$this->makeConnection();

		if ($this->proxyHost) {
			$headers['host'] = $this->host;
			$headers->setRequestPathAndMethod('http://' . $this->host . $this->path, $verb);
			foreach ($this->proxyHeaders as $hkey => $hval) {
				$headers[$hkey] = $hval;
			}
		} else {
			$headers->setRequestPathAndMethod($this->path, $verb);
		}

		if (!@fwrite($this->conn, $headers->toString() . "\r\n" . $contents)) {
			$this->lastError = "Could not send request data";
			return false;
		}

		$response = "";
		while (!feof($this->conn)) {
			$response .= fread($this->conn, 1024);
		}

		// check for a redirect
		if ($this->followRedirs) {
			$responseHeaders = new Headers($response);
			if ($responseHeaders->isRedirect()) {
				$this->setUri($responseHeaders['Location']);
				return $this->sendRequest($headers, $verb, $contents, $responseHeaders);
			}
		}

		return isset($prevHeaders) ? $prevHeaders->toString() . "\r\n" . $response : $response;
	}

	private function parseUri($uri)
	{
		$bits = parse_url($uri);
		if (!isset($bits['host'])) {
			return array(false, 0, '/');
		}
		return array($bits['host'], (isset($bits['port']) ? $bits['port'] : 80), (isset($bits['path']) ? $bits['path'] : '/'));
	}
}
?>
