<?php
 /*===============================================================================
	pWebFramework
	----------------------------------------------------------------------------
	A lightweight, low-level web framework for rapid & robust application development.

	Just comment out the bits you don't need!
	----------------------------------------------------------------------------
	@author		Sam Pospischil <pospi@spadgos.com>
	@date		2010-10-28
  ===============================================================================*/

  	// framework namespace variables, required by all modules
 	require_once('base.class.php');

 	// HTTP request & response handling
	require_once('request.class.php');
	require_once('response.class.php');
	require_once('session.class.php');

	// remote requests (serverside AJAX)
	require_once('http_proxy.class.php');

	// page templating
	require_once('quicktemplate.class.php');

	// application configuration management
	require_once('config.class.php');

	// script logging
	require_once('processlogger.class.php');

	// cryptographic functions
	require_once('crypto.class.php');

	// css & callback based web crawler class
	require_once('web_walker.class.php');
