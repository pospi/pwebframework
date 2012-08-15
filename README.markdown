pWebFramework
=============


About
-----
*pWebFramework* is a small group of classes for handling common low-level web application tasks. This general-purpose platform provides a compact and robust baseline to start implementing projects of your own.

*pWebFramework*'s main design goals are loose coupling and a light code footprint. Classes provide low-level but syntax-light interfaces to common areas of function, and can all be subclassed with additional functionality of your own. The minimal dependencies that do exist can be seen by the indentation of the class hierarchy and accompanying descriptions below:


Features
--------
- Header manipulation (`headers.class.php`)
	- Simplified retrieval and encoding
	- Mutators for setting redirects, controlling caching and other common tasks
	- Request interrogation (`request.class.php`)
	    - Input sanitisation (from GET, POST, COOKIE etc)
	    - Request method determination (HTTP, AJAX, CLI)
	    - QueryString parsing & redirection
		- Socket requests (`http_proxy.class.php` and subclasses)
			- Abstract HTTP request interface and various implementations
			- Web Crawling (`web_walker.class.php`)
				- Crawler engine based on CSS and callbacks allows simple, flexibe and efficient reading of data from remote HTML & XML documents.
				- Request caching & local cache processing
				- Request logging to screen, file or email via `ProcessLogger` interface
	- Response handling (`response.class.php`)
	    - Heavily genericised output handling in the form of ordered blocks
- Database connectivity (`dbase.class.php`)
	- Abstraction layer utilising `PDO`, `mysqli` or `mysql_connect()` client libraries
	- Query logging to screen, file or email via `ProcessLogger` interface
- Session management (`session.class.php`)
    - Creation, destruction (login / logout)
    - Stacking (allows user impersonation, etc)
- Templating (`quicktemplate.class.php`)
	- Extremely fast and simple PHP-based templating class
- Log handling (`processlogger.class.php`)
	- Logging class suitable for use in critical backend scripts. Features lightweight syntax, builtin error trapping, error emailing and can output logs to file, email or stdout.
- Encryption & hashing (`crypto.class.php`)
	- Best-practise API for file & string encryption & one-way hashing
- Configuration management (`config.class.php`)
	- Effortless site configuration with syntax-light variable and database connection retrieval


Todo
----

#### Bugfixes ####
- fix use of exception class names when not declared

#### Improvements ####
- Add ProcessLogger flag to reverse time insertion from ->t()
- Allow setting ProcessLogger time format string
- Implement non-static interface for Config class for compatibility with PHP < 5.3
- Create simplified header mutator wrappers for common tasks (file download etc)
- More socket request implementations
- Database streaming mechanism for looping through large resultsets

#### Additions ####
- DB class to subclass PDO with additional functionality
- Thread management classes: Forker & Receiver.
	- Thread creation with I/O & STDERR stream handles for controlling & reading child threads
	- Thread pooling
	- Simplified thread locking mechanism (flock)

License
-------
This software is provided under an MIT open source license, read the 'LICENSE.txt' file for details.

Copyright &copy; 2010 Sam Pospischil (pospi at spadgos dot com)
