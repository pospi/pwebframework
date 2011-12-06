<?php
 /*===============================================================================
	pWebFramework
	----------------------------------------------------------------------------
	A lightweight, low-level web framework for rapid & robust application development.

	Provides:
		- Request interrogation
		- Script input sanitisation
		- Session management
		- Response handling
		- Remote HTTP request engine
		- Simple & fast templating engine
	----------------------------------------------------------------------------
	@author		Sam Pospischil <pospi@spadgos.com>
	@date		2010-10-28
  ===============================================================================*/

 	require_once('base.class.php');		// framework namespace variables, required by all modules

	require_once('request.class.php');
	require_once('response.class.php');
	require_once('session.class.php');

	require_once('http_proxy.class.php');

	require_once('quicktemplate.class.php');
?>
