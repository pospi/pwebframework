<?php
 /*===============================================================================
	pWebFramework - session management class
	----------------------------------------------------------------------------
	Creation of a session object initialises a session with the name provided.
	It also allows direct manipulation of the superglobal $_SESSION array, and
	session impersonation via manipulation of a session stack.

	Usage:
		$s = new Session('yoursitename');
		$s['foo'] = 'bar';			// $_SESSION['foo'] is now 'bar';

		$s->push(array('userid' => 622011));	// we are now acting as another session with 'userid' = 622011
		// do some stuff as if logged in as this user, assuming all authentication depends on this session parameter
		$s->pop();								// return to our previous session context

		$s->logout(true);			// ends the remote agent's session and deletes their session cookie
	----------------------------------------------------------------------------
	@author		Sam Pospischil <pospi@spadgos.com>
	@date		2010-03-18
  ===============================================================================*/

class Session implements ArrayAccess
{
	private $name;

	public function __construct($sessionName = '__PHPSESSID')
	{
		$this->name = $sessionName;

		session_name($this->name);
		session_start();
	}

	//=====================================================================================
	// Array accessor methods, allow the object to be used in place of $_SESSION

	public function offsetSet($offset, $value)
	{
		if (is_null($offset)) {
			trigger_error('Attempted to set session variable without passing a key', E_USER_ERROR);
		} else {
			$_SESSION[$offset] = $value;
		}
	}

	public function offsetExists($offset)
	{
		return isset($_SESSION[$offset]);
	}

	public function offsetUnset($offset)
	{
		unset($_SESSION[$offset]);
	}

	public function offsetGet($offset)
	{
		return isset($_SESSION[$offset]) ? $_SESSION[$offset] : null;
	}

	//=====================================================================================

	/**
	 * Calling this will commit $_SESSION so that it may be used by other scripts.
	 * This ordinarily happens at the end of script, but you may call it earlier if desired.
	 */
	public function finished()
	{
		session_write_close();
	}

	/**
	 * Pushes the current session higher up into memory, making room for a replacement session array.
	 * This is useful when you want to impersonate a different user, etc.
	 */
	public function push($newSessionArray = array())
	{
		$currentSession = $_SESSION;
		$_SESSION = $newSessionArray;
		$_SESSION['__parent_session'] = $currentSession;
	}

	/**
	 * If $session->push() has previously been called, calling pop() will reverse the operation.
	 * In other words, the previously archived session comes back down and becomes active.
	 *
	 * @return	true if there was a parent session and the operation completed, false otherwise
	 */
	public function pop()
	{
		if (isset($_SESSION['__parent_session'])) {
			$prevSession = $_SESSION['__parent_session'];
			$_SESSION = $prevSession;
			return true;
		}
		return false;
	}

	/**
	 * Ends the current browsing session, and erases all stored session data.
	 * You should call this before headers are sent if you want the browser cookie to
	 * be removed as well.
	 */
	public function logout($clearCookies = true)
	{
		if ($clearCookies && ini_get("session.use_cookies")) {
			Response::checkHeaders();
			$params = session_get_cookie_params();
			setcookie(session_name(), '', time() - 42000,
				$params["path"], $params["domain"],
				$params["secure"]
			);
		}
		session_unset();
		session_destroy();
	}

}

?>
