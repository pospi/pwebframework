<?php
/**
 * pWebFramework shared class
 *
 * Only contains global configuration vars
 */
class pwebframework
{
	// Exception type to throw for internal framework errors.
	// If not set, PHP errors will be thrown instead of exceptions.
	// This has no effect on warnings, which are raised as usual.
	public static $exceptionClass = null;
}
?>
