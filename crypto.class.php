<?php
/**
 * Encryption methods
 *
 * Contains methods for:
 * - encoding and decoding GPG encrypted files
 * - managing two-way AES encryption of string values
 * - one-way string hashing
 *
 * :WARNING:
 * 	All interfaces to this class deal with binary string data, which may contain
 * 	null bytes and other unreadable characters. You will typically have to convert
 * 	these values with base64_encode() or bin2hex() before passing to other APIs.
 * 	The reverse can be accomplished with base64_decode() or hex2bin(), though in the
 * 	latter case you should use Crypto::hex2bin() to work around PHP versions which do
 * 	not support this method natively.
 *
 * @package	pWebFramework
 * @author	Sam Pospischil <pospi@spadgos.com>
 * @since	24/7/12
 * @requires mcrypt to be installed for two-way AES encryption methods
 * @requires Gnu GPG to be installed for file-based GPG encryption methods
 * @requires at least a blowfish hashing implementation for hashing methods, but preferrably SHA-512.
 * @requires processlogger.class.php if logging is enabled
 */
class Crypto
{
	const CRYPT_ALPHABET = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';	// alphabet generated by calls to crypt()

	public static $GPG_PATH = '/usr/bin/gpg';
	public static $GPG_DATADIR = null;	// defaults to ~/.gnupg

	private static $logger;				// ProcessLogger instance used for logging
	private static $debugLog = false;	// if true, output extra info to log. If 2, output even more GPG information from all available sources.

	/**
	 * Detect the paths to executables required by this class
	 */
	public static function detectGPGPath()
	{
		$path = trim(`which gpg`);
		if ($path) {
			self::$GPG_PATH = $path;
		} else {
			self::log("Unable to determine GPG path (is GPG installed?)");
		}
	}

	/**
	 * Enable error logging via a ProcessLogger instance.
	 * @param  ProcessLogger $log logger instance to perform error & debug logging through
	 */
	public static function enableLogging(ProcessLogger $log, $debug = false)
	{
		self::$debugLog = $debug;
		self::$logger = $log;
	}

	private static function log($line, $debug = false)
	{
		if ($debug && !self::$debugLog || ($debug && (int)$debug > (int)self::$debugLog)) {
			return false;
		}
		if (self::$logger) {
			self::$logger[] = $line;
		} else {
			trigger_error($line, E_USER_WARNING);
		}
	}

	//--------------------------------------------------------------------------
	// Two-way GPG encryption

	/**
	 * Encrypt a string for a recipient in your GPG keyring
	 * @param  string $string         string to encrypt
	 * @param  string $recipientEmail GPG key uid
	 * @param  string $outputFile     file to save the result to, if any
	 * @return the encrypted binary data
	 */
	public static function encryptString($string, $recipientEmail, $outputFile = null)
	{
		$recipientEmail = escapeshellarg($recipientEmail);
		$outputFile = isset($outputFile) ? escapeshellarg($outputFile) : false;

		if ($outputFile) {
			$cmd = self::$GPG_PATH . " --always-trust --yes" . (self::$GPG_DATADIR ? ' --homedir ' . escapeshellarg(self::$GPG_DATADIR) : '') . " -e -r $recipientEmail -o $outputFile";
			self::log("RUNNING ENCRYPTION: " . $cmd, true);

			return self::runExternalProcess($cmd, $string);
		}

		$cmd = self::$GPG_PATH . " --always-trust --yes" . (self::$GPG_DATADIR ? ' --homedir ' . escapeshellarg(self::$GPG_DATADIR) : '') . " -e -r $recipientEmail";
		self::log("RUNNING ENCRYPTION: " . $cmd, true);

		return self::runExternalProcess($cmd, $string, true);
	}

	/**
	 * Encrypt a file
	 * @param  string $file           file to encrypt
	 * @param  string $recipientEmail GPG key uid
	 * @param  bool   $targetFilename if false, will output as the source filename suffixed with '.gpg'. If true, replaces the original file. Otherwise output to this file.
	 * @return the filename of the resulting encrypted file
	 */
	public static function encryptFile($file, $recipientEmail, $targetFilename = false)
	{
		$recipientEmail = escapeshellarg($recipientEmail);
		$replaceOriginal = $targetFilename === true;

		$cmd = self::$GPG_PATH . " --always-trust --yes " . (self::$GPG_DATADIR ? ' --homedir ' . escapeshellarg(self::$GPG_DATADIR) : '') . " -e -r $recipientEmail" . (!$replaceOriginal && $targetFilename ? " -o $targetFilename" : '') . " " . escapeshellarg($file);
		self::log("RUNNING ENCRYPTION: " . $cmd, true);

		if (self::runExternalProcess($cmd) === 0) {
			if ($replaceOriginal) {
				unlink($file);
				rename($file . '.gpg', $file);
				return $file;
			}
			return $file . '.gpg';
		}
		return false;
	}

	/**
	 * Decrypt a file encrypted using a GPG key
	 * @param  string $file            file to decrypt
	 * @param  string $keyPassphrase   passphrase for the GPG key
	 * @param  string $unencryptedFile destination file to write unencrypted contents to. If ommitted, the unencrypted data is returned.
	 * @return the decrypted file contents if $unencryptedFile is ommitted, otherwise a bool to indicate the success of the command.
	 */
	public static function decryptFile($file, $keyPassphrase = null, $unencryptedFile = null)
	{
		$file = escapeshellarg($file);
		$unencryptedFile = isset($unencryptedFile) ? escapeshellarg($unencryptedFile) : false;

		if ($keyPassphrase) {
			$cmd = self::$GPG_PATH . " --always-trust --yes --batch --passphrase-fd 0" . (self::$GPG_DATADIR ? ' --homedir ' . escapeshellarg(self::$GPG_DATADIR) : '') . ($unencryptedFile ? " -o $unencryptedFile" : '') . " -d $file";
		} else {
			$cmd = self::$GPG_PATH . " --always-trust --yes --batch" . (self::$GPG_DATADIR ? ' --homedir ' . escapeshellarg(self::$GPG_DATADIR) : '') . ($unencryptedFile ? " -o $unencryptedFile" : '') . " -d $file";
		}

		self::log("RUNNING DECRYPTION: " . $cmd, true);

		$res = self::runExternalProcess($cmd, $keyPassphrase, $unencryptedFile ? false : true);

		return $unencryptedFile ? $res === 0 : $res;
	}

	//--------------------------------------------------------------------------
	//	Two-way AES encryption

	/**
	 * Encrypt a string using 256-bit AES encryption
	 * @param  string $text          text to encrypt
	 * @param  string $encryptionKey (salted) key for the encryption
	 * @return the encrypted string in binary format, with input seed value prepended
	 */
	public static function encryptAES($text, $encryptionKey)
	{
		$td = mcrypt_module_open(MCRYPT_RIJNDAEL_256, '', MCRYPT_MODE_CBC, '');
		$iv = mcrypt_create_iv(mcrypt_enc_get_iv_size($td), MCRYPT_RAND);

		$encryptionKey = substr($encryptionKey, 0, mcrypt_enc_get_key_size($td));

		mcrypt_generic_init($td, $encryptionKey, $iv);

		$encrypted_data = mcrypt_generic($td, $text);

		mcrypt_generic_deinit($td);
		mcrypt_module_close($td);

		return $iv . $encrypted_data;
	}

	/**
	 * Decrypts a string encrypted using AES 256-bit encryption with the same key
	 * @param  string $encryptedString encrypted string returned from encryptAES()
	 * @param  string $encryptionKey   (salted) encryption key for decoding the string
	 * @return the decrypted string
	 */
	public static function decryptAES($encryptedString, $encryptionKey)
	{
		$td = mcrypt_module_open(MCRYPT_RIJNDAEL_256, '', MCRYPT_MODE_CBC, '');
		$ivSize = mcrypt_enc_get_iv_size($td);

		$iv = substr($encryptedString, 0, $ivSize);
		$encryptedString = substr($encryptedString, $ivSize);

		$encryptionKey = substr($encryptionKey, 0, mcrypt_enc_get_key_size($td));

		mcrypt_generic_init($td, $encryptionKey, $iv);

		$decrypted_data = mdecrypt_generic($td, $encryptedString);

		mcrypt_generic_deinit($td);
		mcrypt_module_close($td);

		return $decrypted_data;
	}

	//--------------------------------------------------------------------------
	//	One-way hashing, using SHA-512 (preferred), SHA-256 or Blowfish

	/**
	 * Hash a string with a provided salt
	 * @param  string $string string to hash
	 * @param  string $salt   salt to hash with.
	 * @return hashed string in binary
	 */
	public static function hash($string, $salt)
	{
		if (defined('CRYPT_SHA512') && CRYPT_SHA512) {
			$salt = '$6$rounds=5000$' . $salt . '$';
		} else if (defined('CRYPT_SHA256') && CRYPT_SHA256) {
			$salt = '$5$rounds=5000$' . $salt . '$';
		} else if (defined('CRYPT_BLOWFISH') && CRYPT_BLOWFISH) {
			$salt = '$2a$08$' . preg_replace('/[^a-zA-Z0-9]/', '-', $salt) . '$';
		}

		$result = crypt($string, $salt);
		$result = substr($result, strrpos($result, '$') + 1);

		return self::base64_decode_crypt($result);
	}

	/**
	 * Decodes a base64 string generated by crypt() into a raw binary string.
	 * We implement this ourselves as crypt's base64 alphabet differs from PHP's.
	 *
	 * @param  string $str base64-encoded crypt() hash
	 * @return binary string
	 */
	private static function base64_decode_crypt($str)
	{
		// set up the array to feed numerical data using characters as keys
		$alpha = array_flip(str_split(self::CRYPT_ALPHABET));
		// split the input into single-character (6 bit) chunks
		$bitArray = str_split($str);
		$decodedStr = '';
		foreach ($bitArray as &$bits) {
			if ($bits == '$') { // $ indicates the end of the string, to stop processing here
				break;
			}
			if (!isset($alpha[$bits])) { // if we encounter a character not in the alphabet
				return false;            // then break execution, the string is invalid
			}
			// decbin will only return significant digits, so use sprintf to pad to 6 bits
			$decodedStr .= sprintf('%06s', decbin($alpha[$bits]));
		}
		// there can be up to 6 unused bits at the end of a string, so discard them
		$decodedStr = substr($decodedStr, 0, strlen($decodedStr) - (strlen($decodedStr) % 8));
		$byteArray = str_split($decodedStr, 8);
		foreach ($byteArray as &$byte) {
			$byte = chr(bindec($byte));
		}
		return join($byteArray);
	}

	//--------------------------------------------------------------------------

	public static function hex2bin($str)
	{
		if (function_exists('hex2bin')) {
			return hex2bin($str);
		}
		return pack("H*" , $str);
	}

	/**
	 * execute a command and return exit code or output.
	 * Suppress all output from the command, but log any errors or output from it according to log level.
	 */
	private static function runExternalProcess($cmd, $input = null, $returnOutput = false)
	{
		$descriptorspec = array(
			0 => array("pipe", "r"), // stdin
			1 => array("pipe", "w"), // stdout
			2 => array("pipe", "w"), // stderr
		);

		$process = proc_open($cmd, $descriptorspec, $pipes, getcwd());

		if (is_resource($process)) {
			if (isset($input)) {
				fwrite($pipes[0], $input);
			}
			fclose($pipes[0]);

			$output = stream_get_contents($pipes[1]);
			$errors = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);

			$exitStatus = proc_close($process);
		}

		if ($exitStatus !== 0) {
			self::log("Command error (exit status {$exitStatus})");
			if (self::$logger) self::$logger->indent();
			self::log("Error output was:\n{$errors}", true);
			self::log("Status output was:\n{$output}", 2);
			if (self::$logger) self::$logger->unindent();
		}

		return $returnOutput ? $output : $exitStatus;
	}
}
