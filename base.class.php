<?php
/**
 * pWebFramework shared class
 *
 * Only contains global configuration vars
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
}
