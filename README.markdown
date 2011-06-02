pWebFramework
=============


About
-----
*pWebFramework* is a small group of classes for handling common, basic web application tasks. This general-purpose platform provides a compact and robust baseline to start implementing projects of your own.


Features
--------
- Request interrogation
    - Input sanitisation (from GET, POST, COOKIE etc)
    - Request method determination (HTTP, AJAX, CLI)
    - QueryString parsing & redirection
- Response handling
    - Heavily genericised output handling in the form of ordered blocks
- Header manipulation
	- Simplified retrieval and encoding
	- Mutators for setting redirects, controlling caching and other common tasks
- Session management
    - Creation, destruction (login / logout)
    - Stacking (allows user impersonation, etc)
- Socket requests
	- Abstract HTTP request interface and various implementations
- Templating
	- Extremely fast and simple PHP-based templating class


Todo
----
- Simplified header mutators
- More socket request implementations


License
-------
This software is provided under an MIT open source license, read the 'LICENSE.txt' file for details.


Copyright
---------
Copyright (c) 2010 Sam Pospischil (pospi at spadgos dot com)
