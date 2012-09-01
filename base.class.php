<?php
/**
 * pWebFramework shared class
 *
 * Contains global configuration vars and the super-simple classloader system, which
 * just does nothing if classes are already loaded, or require()s the relevant file
 * from this directory otherwise.
 * Due to the structure of the framework, cross-dependencies for other framework classes
 * will be automatically require()d upon loading the class file of interest.
 *
 * @package pWebFramework
 * @author Sam Pospischil
 */
class pwebframework
{
	// Exception types to throw for internal framework errors.
	// Each will be used for all exceptions within the class that relates to it.
	// If not set, PHP errors will be thrown instead of exceptions.
	// This has no effect on warnings, which are raised as usual.
	public static $requestExceptionClass = null;
	public static $sessionExceptionClass = null;
	public static $configExceptionClass = null;
	public static $crawlerExceptionClass = null;
	public static $dbaseExceptionClass = null;

	public static $PWF_PATH;

	/**
	 * Class loader for framework classes, to avoid re-loading the same
	 * classes once already included elsewhere in the currently executing script.
	 * @param  string $className name of the class to load, as it appears in PHP
	 * @return TRUE if the class was already loaded, or FALSE if it needed to be.
	 *              If the class is not found, a fatal error will automatically be thrown by require().
	 */
	public function loadClass($className)
	{
		if (class_exists($className)) {
			return true;
		}

		$filename = strtolower($className . '.class.php');
		require_once(pwebframework::$PWF_PATH . $filename);

		return false;
	}
}

pwebframework::$PWF_PATH = dirname(__FILE__) . '/';
