<?php

namespace bobsbg\oci2pdo;

/**
 * OCI LOB stream wrapper class
 * @author atonkin
 *
 */
class OciPdoLobStreamWrapper {

	const WRAPPER_NAME = 'ocipdolob';

	/**
	 * Indicate if stream wrapper is registered
	 * @var boolean
	 */
	private static $_isRegistered = false;

	/**
	 * OCI LOB
	 * @var OCI-Lob
	 */
	private $_lob = null;

	/**
	 * The current context, or NULL if no context was passed to the caller function
	 * @var resource
	 */
	public $context = null;

	/**
	 * Destructor
	 * Free OCI LOB
	 */
	public function __destruct() {
		$this->_lob->free();
		$this->_lob = null;
	}

	/**
	 * Create stream context
	 * @param OCI-Lob $lob
	 * @return resource
	 */
	public static function getContext(&$lob) {
		if (!self::$_isRegistered) {
			stream_wrapper_register(self::WRAPPER_NAME, get_class());
			self::$_isRegistered = true;
		}

		return stream_context_create(array(self::WRAPPER_NAME => array('lob' => &$lob)));
	}

	/**
	 * Tests for end-of-file on a file pointer
	 * @return boolean
	 */
	public function stream_eof() {
		return $this->_lob->eof();
	}

	/**
	 * Prepare OCI LOB for stream
	 * @param string $path
	 * @param string $mode
	 * @param int $options
	 * @param string $opened_path
	 * @return boolean
	 */
	public function stream_open($path, $mode, $options, &$opened_path) {
		if (!preg_match('/^r[bt]?$/', $mode) || is_null($this->context)) {
			return false;
		}
		$opt = stream_context_get_options($this->context);
		if (!is_array($opt[self::WRAPPER_NAME]) || !isset($opt[self::WRAPPER_NAME]['lob'])) {
			return false;
		}
		if (!is_object($opt[self::WRAPPER_NAME]['lob']) && get_class($opt[self::WRAPPER_NAME]['lob']) != 'OCI-Lob') {
			return false;
		}

		$this->_lob = &$opt[self::WRAPPER_NAME]['lob'];

		$this->_lob->rewind();

		return true;
	}

	/**
	 * Read from stream
	 * @param int $count
	 * @return string
	 */
	public function stream_read($count) {
		if ($this->_lob->eof() || !$count) {
			return '';
		}

		// CLOB to return characters not bytes
		return $this->_lob->read($count / 2); // Fix we expect 2 byte char
	}

	/**
	 * Retrieve information about a resource
	 * @return array
	 */
	public function stream_stat() {
		return array(
			'size' => $this->_lob->size(),
		);
	}

	/**
	 * Retrieve the current position of a stream
	 * @return boolean
	 */
	public function stream_tell() {
		return $this->_lob->tell();
	}

}

?>
