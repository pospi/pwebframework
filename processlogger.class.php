<?php
/**
 * ProcessLogger
 *
 * Class to handle log messages and error reporting in CLI scripts.
 * You may append log messages simply by using array index operators on the class:
 * 	$log = new ProcessLogger();
 * 	$log[] = "Log line.";
 *
 * For best results, you should specify a file to log to using outputToFile(). This
 * will then be used in place of an in-memory cache, which can eat up PHP's available
 * memory fairly quickly.
 *
 * It is also a good idea to register the __shutdown() function of this class as a
 * script shutdown function with sendOnTerminate(). This will automatically ensure
 * logs are saved no matter how the script itself terminates.
 *
 * :WARNING:
 * You MUST call setScriptStart() to set the start time of the process script
 * (in seconds - microtime(true) is perfect for this) if you want time stats
 * reported on shutdown.
 *
 * Similarly, the class contains error and excepting handling functions, which can
 * be registered to capture script errors and include them in log output by calling
 * captureErrors(). When both this error handler and the shutdown function are being
 * used, fatal errors are detected upon script shutdown and included as well.
 *
 * A standard use case could be as follows:
 *
 * 	$log = new ProcessLogger();
 *  $log->setScriptStart(microtime(true));
 *  $log->outputToFile('logfile.log');
 *  $log->captureErrors();
 *  $log->sendOnTerminate();
 *
 *  $log->outputErrorsToEmail('me@example.com', 'Script Error!');
 *
 *  $log[] = "Log line.";
 *
 * Copyright (c) 2010 Sam Pospischil <sam.pospischil@deta.qld.gov.au>
 *                    Web Services, Dept. of Education and Training
 */

class ProcessLogger implements ArrayAccess {

	private $lines = array();				// lines eats memory. So it's only used when not outputting to a file.

	private $scriptStart = 0;
	private $logFile = false;
	private $logFileName;

	private $emailRecipients = array();		// array of [address, subject, fromAddress, fromEnvelope, bOnlyErrors]

	private $useErrorHandler = false;
	private $ignoreErrorPaths = array();
	private $errorCount = 0;		// we can count errors to put a consistent variable at the end of logs to check on
	private $byteCount = 0;			// track number of bytes written in order to send correct log instead of whole appended file

	private $skipCleanExitStats = false;

	private $immediateOutput;

	private static $has_mbstring;	// mbstring extension installed (used in byte counting)
	private static $has_mb_shadow;	// @see http://php.net/manual/en/mbstring.overload.php

	// @param	$immediateOutput	if true, logs should be printed to the screen as they happen
	public function __construct($immediateOutput = true) {
		$this->immediateOutput = $immediateOutput;

		// init vars used for byte length determination on first use
		if (!isset(self::$has_mbstring)) {
			self::$has_mbstring = extension_loaded('mbstring');
			self::$has_mb_shadow = (int) ini_get('mbstring.func_overload');
		}
	}

	public function __destruct() {
		if ($this->logFile) {
			fclose($this->logFile);
		}
	}

	//======================================================================

	public static function since($timestamp) {
		return number_format(microtime(true) - $timestamp, 6);
	}

	//======================================================================
	//		Mutators / initial setup

	public function setScriptStart($seconds) {
		$this->scriptStart = $seconds;
	}

	public function outputToFile($file) {
		$this->logFileName = $file;
		$this->logFile = fopen($file, 'a+');
		if (!$this->logFile) {
			$this[] = "ERROR: Could not open log file $file";
		}

		// put any already set log lines into the file and clear out our memory cache
		if (sizeof($this->lines) > 0) {
			$content = implode("\n", $this->lines) . "\n";
			fwrite($this->logFile, $content);
			$this->byteCount = self::bytelen($content);
			$this->lines = array();
		}
	}

	public function outputToEmail($address, $subject, $from = null, $envelope = null) {
		$this->addEmail($address, $subject, $from, $envelope, false);
	}

	public function outputErrorsToEmail($address, $subject, $from = null, $envelope = null) {
		$this->addEmail($address, $subject, $from, $envelope, true);
		$this->captureErrors();
	}

	public function outputImmediately($output) {
		$this->immediateOutput = (bool)$output;
	}

	public function captureErrors() {
		if (!$this->useErrorHandler) {
			set_error_handler(array($this, '__error'));
			set_exception_handler(array($this, '__exception'));
			$this->useErrorHandler = true;
		}
	}

	public function sendOnTerminate() {
		register_shutdown_function(array($this, '__shutdown'));
	}

	public function skipCleanExitStats() {
		$this->skipCleanExitStats = true;
	}

	// Any errors detected in files that fall within paths set here will
	// not be logged. This is so you can have deprecated/non-strict code
	// in libraries and still use it without creating log spam.
	public function ignoreErrorsFrom($path) {
		$this->ignoreErrorPaths[] = $path;
	}

	//======================================================================

	public function sendAll() {
		if (sizeof($this->emailRecipients) > 0) {
			$this->sendToEmail();
		}
		if (!$this->immediateOutput) {
			$this->sendToOutput();		// send deferred output if the script has been setup that way
		}
	}

	public function sendToOutput() {
		echo $this->toString();
	}

	public function sendToEmail() {
		foreach ($this->emailRecipients as $mail) {
			if (!$mail[4] || ($mail[4] && $this->errorCount > 0)) {			// if this is a "send everything" address, or an errors only address and there are errors
				if (!mail(
					$mail[0],
					$mail[1],
					$this->toString(),
					'FROM:' . $mail[2],
					($mail[3] ? '-f' . $mail[3] : null)
				  )) {
					$this[] = "ERROR: Could not email migration log (to " . $this->sendAddress . ")";
				}
			}
		}
	}

	//======================================================================

	public function toString() {
		if ($this->logFile) {
			fseek($this->logFile, $this->byteCount * -1, SEEK_END);
			return fread($this->logFile, $this->byteCount);
		}
		return implode($this->lines, "\n") . "\n";
	}

	private function addEmail($address, $subject, $from = null, $envelope = null, $onlyErrors = false) {
		$this->emailRecipients[] = array($address, $subject, $from, $envelope, $onlyErrors);
	}

	//======================================================================

	public function __shutdown() {
		// check for a fatal error
		if ($this->useErrorHandler) {
			$error = error_get_last();
			if (in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_STRICT))) {
				$this->__error($error['type'], $error['message'], $error['file'], $error['line'], array(), false);
			}
		}

		if (!$this->skipCleanExitStats || $this->errorCount) {
			$this[] = "\n-- PROCESS COMPLETE --\n";
			$this[] = 'Peak memory usage: ' . number_format(memory_get_peak_usage(), 0, '.', ',') . " bytes";
			$this[] = 'End memory usage:  ' . number_format(memory_get_usage(), 0, '.', ',') . " bytes";

			if ($this->scriptStart) {
				$time = microtime(true) - $this->scriptStart;

				$this[] = 'Execution time:    ' . number_format($time, 6) . ' seconds';
			}

			if ($this->useErrorHandler && $this->errorCount) {
				$this[] = "Errors detected:   " . $this->errorCount;
			}

			if ($this->logFile) {
				$this[] = "Process log at:    $this->logFileName";
			}

			$this[] = "";	// blank line, to separate logger internal errors which may come from sendAll()
		}

		$this->sendAll();
	}

	// :NOTE: we don't do a backtrace for fatal errors sent from __shutdown(), as these
	//		  aren't actually sent through the error handler so we have no idea of the environment
	//		  at the time of failure.
	public function __error($errno, $errstr, $errfile = "", $errline = 0, $errcontext = array(), $doTrace = true) {
		if (!(error_reporting())) return;

		foreach ($this->ignoreErrorPaths as $path) {
			if (strpos($errfile, $path) === 0) return false;
		}

		$fatal = $this->logError($errno, $errstr, $errfile, $errline, ($doTrace ? debug_backtrace() : array()));

		return $fatal ? false : $this->immediateOutput;		// send the error back up to the builtin error handler, but only if we aren't outputting it immediately
	}

	public function __exception($e) {
		if (!(error_reporting())) return;

		foreach ($this->ignoreErrorPaths as $path) {
			if (strpos($e->getFile(), $path) === 0) return false;
		}

		$fatal = $this->logError(-1, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTrace());

		return $fatal ? false : $this->immediateOutput;
	}

	// @return bool indicating whether this was a fatal error
	private function logError($type, $msg, $file, $line, $trace) {
		$this->errorCount++;
		switch ($type) {
			case E_CORE_ERROR:
			case E_ERROR: $type = 'fatal'; break;
			case E_RECOVERABLE_ERROR: $type = 'recoverable'; break;
			case E_CORE_WARNING:
			case E_COMPILE_WARNING:
			case E_WARNING: $type = 'warning'; break;
			case E_NOTICE: $type = 'notice'; break;
			case E_PARSE: $type = 'parse'; break;
			case E_COMPILE_ERROR: $type = 'compile'; break;
			case E_USER_ERROR: $type = 'user fatal'; break;
			case E_USER_WARNING: $type = 'user warning'; break;
			case E_USER_NOTICE: $type = 'user notice'; break;
			case E_STRICT: $type = 'strict'; break;
			case E_USER_DEPRECATED:
			case E_DEPRECATED: $type = 'deprecated'; break;
			case -1: $type = 'exception'; break;
		}

		$this[] = "\nERROR<$type> at $file:$line\n  $msg\n  " . $this->readableTrace($trace);

		return strpos($type, 'fatal') !== false;
	}

	private function readableTrace($stack) {
		$lines = array();

		$i = 0;
		foreach ($stack as $hist => $data) {
			if (!empty($data['class']) && $data['class'] == 'ProcessLogger') {
				// discount this one from the total number of function calls, as it is a ProcessLogger internal call
				continue;
			}

			$line = str_replace(array(
					'%f', '%l', '%c', '%t', '%n', '%m'
				), array(
					$data['file'],
					$data['line'],
					(isset($data['class'])	? $data['class']: ''),
					(isset($data['type'])	? $data['type']	: ''),
					$data['function'],
					(isset($data['args'])	? ProcessLogger::implodeArgs($data['args']) : '')
				),
				str_pad(++$i, 2, '0', STR_PAD_LEFT) . " %f:%l : %c%t%n(%m)"
			);

			$lines[] = $line;
		}

		return implode("\n  ", $lines);
	}

	private static function implodeArgs($args, $initial = true) {
		if (is_array($args)) {
			$return = array();
			$i = 0;
			foreach ($args as $num => $val) {
				if (!$initial && $i++ == 3) {
					$return[] = '...';
					break;
				}
				$return[] = ProcessLogger::implodeArgs($val, false);
			}
			if ($initial) {
				return implode(', ', $return);
			} else {
				return '[' . implode(',', $return) . ']';
			}
		} else if (is_object($args)) {
			return '{' . get_class($args) . '}';
		} else if (is_bool($args)) {
			return $args ? 'true' : 'false';
		} else if (is_null($args)) {
			return 'NULL';
		} else if (is_resource($args)) {
			return '(' . get_resource_type($args) . ' resource)';
		} else if (is_string($args)) {
			if (strlen($args) > 20) {
				$args = substr($args, 0, 17) . '...';
			}
			return '"' . $args . '"';
		} else {
			return $args;
		}
	}

	/**
	 * Works around an issue where the multibyte string extension
	 * can be configured to shadow strlen(), and so strlen no longer
	 * returns simple byte length (which it historically does, but
	 * arguably shouldn't).
	 * @param  string $str string to get byte length of
	 * @return int
	 */
	private static function bytelen($str)
	{
		if (self::$has_mbstring && (self::$has_mb_shadow & 2)) {
			return mb_strlen($str, 'latin1');
		} else {
			return strlen($str);
		}
	}

	//======================================================================
	//		ArrayAccess implementation

	/**
	 * If an offset is given, this is used as the start of the line. So -
	 *		$log['error'] = 'OMG NOES'		gives the line
	 *		error: OMG NOES
	 */
    public function offsetSet($offset, $value) {
		$line = ($offset ? $offset . ': ' : '') . $value;

		if ($this->logFile) {
			fwrite($this->logFile, $line . "\n");
			$this->byteCount += self::bytelen($line . "\n");
		} else {
			$this->lines[] = $line;
		}

		if ($this->immediateOutput) {
			echo $line . "\n";
		}
	}

	// :NOTE: the following 3 functions are pretty pointless for this implementation so they don't check for file output mode
	public function offsetExists($offset) {
		return isset($this->lines[$offset]);
	}
	public function offsetUnset($offset) {
		unset($this->lines[$offset]);
	}
	public function offsetGet($offset) {
		return isset($this->lines[$offset]) ? $this->lines[$offset] : null;
	}

}
