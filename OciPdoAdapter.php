<?php

namespace bobsbg\oci2pdo;

use \yii\db\Exception;

/**
 * При липса на връзка с БД
 * CDbNoConnectionException
 */
class CDbNoConnectionException extends Exception {}

/**
 * OciPdoAdapter class to simulate PDO with OCI
 * @author atonkin
 *
 */
class OciPdoAdapter {

	/**
	 * OCI prefetch rows
	 * @var int
	 */
	const ATTR_PREFETCH_ROWS = 100;

	/**
	 * Database handler
	 * @var resource
	 */
	private $_dbh = false;

	/**
	 * OCI error
	 * @var mixed
	 */
	private $_error = false;

	/**
	 * Whether currently in a transaction
	 * @var boolean
	 */
	private $_inTransaction = false;

	/**
	 * OCI commit mode
	 * @var int
	 */
	private $_commitMode = null;

	/**
	 * Readonly driver options
	 * @var array
	 */
	private $_readonlyAttributes = array(
		PDO::ATTR_DRIVER_NAME => 'oci',
		PDO::ATTR_CLIENT_VERSION => '', //oci_client_version()
		PDO::ATTR_SERVER_VERSION => '' //oci_server_version()
	);

	/**
	 * Default driver options
	 * @var array
	 */
	private $_defaultAttributes = array(
		PDO::ATTR_CASE => PDO::CASE_UPPER,
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		//PDO::ATTR_TIMEOUT => 30,
		PDO::ATTR_AUTOCOMMIT => false,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
		PDO::ATTR_PERSISTENT => false,
	);

	/**
	 * Driver options
	 * @var array
	 */
	private $_attributes = array();

	/**
	 * Parsed DSN
	 * @var array
	 */
	private static $_parsedDsn = array();

	/**
	 * Constructor
	 * Creates a PDO instance representing a connection to a database
	 * @link http://www.php.net/manual/en/pdo.construct.php
	 * @param dsn
	 * @param username[optional]
	 * @param passwd[optional]
	 * @param array options[optional]
	 */
	public function __construct($dsn, $username = null, $passwd = null, $options = array()) {
		// Check OCI extension
		if (!extension_loaded('oci8')) {
			throw new Exception('oci8 extension is not loaded');
		}

		// Init attributes
		$this->getAttributes();

		// Check driver
		self::parseDsn($dsn);
		if(self::$_parsedDsn['driverName'] != $this->getAttribute(PDO::ATTR_DRIVER_NAME)) {
			throw new Exception('You must use oci driver');
		}

		// Set attributes
		$this->setAttributes($options);

		// Connect
		$this->initConnection($username, $passwd);
	}

	/**
	 * Begins a transaction (turns off autocommit mode)
	 * @return boolean
	 */
	public function beginTransaction() {
		if ($this->inTransaction()) {
			throw new Exception('Transaction already is active');
		}
		$this->_inTransaction = true;
		$this->_commitMode = (PHP_VERSION_ID >= 503020) ? OCI_NO_AUTO_COMMIT : OCI_DEFAULT;

		return true;
	}

	/**
	 * Commits all statements issued during a transaction and ends the transaction
	 * @return boolean
	 */
	public function commit() {
		if (!$this->inTransaction()) {
			throw new Exception('There is no active transaction');
		}

		$res = @oci_commit($this->_dbh);
		$this->checkError($res);

		$this->_inTransaction = false;
		$this->_commitMode = null;

		return $res;
	}

	/**
	 * Returns the error code associated with the last operation
	 * @return mixed
	 */
	public function errorCode() {
		$errorInfo = $this->errorInfo();
		return $errorInfo[0];
	}

	/**
	 * Returns extended error information for the last operation on the database
	 * @return array
	 */
	public function errorInfo() {
		$error = $this->getError();
		if($error !== false) {
			return array('HY000', $error['code'], $error['message']);
		}
		return array(PDO::ERR_NONE, null, null);
	}

	/**
	 * Executes an SQL statement and returns the number of affected rows
	 * @param string $statement
	 * @return int The number of rows affected
	 */
	public function exec($statement) {
		$ociPdoStatement = $this->query($statement);
		$count = $ociPdoStatement->rowCount();
		$ociPdoStatement->closeCursor();

		return $count;
	}

	/**
	 * Retrieve a database connection attribute
	 * @link http://www.php.net/manual/en/pdo.getattribute.php
	 * @param attribute int <p>
	 * One of the PDO::ATTR_* constants. The constants that
	 * apply to database connections are as follows:
	 * PDO::ATTR_AUTOCOMMIT
	 * PDO::ATTR_CASE
	 * PDO::ATTR_CLIENT_VERSION
	 * PDO::ATTR_CONNECTION_STATUS
	 * PDO::ATTR_DRIVER_NAME
	 * PDO::ATTR_ERRMODE
	 * PDO::ATTR_ORACLE_NULLS
	 * PDO::ATTR_PERSISTENT
	 * PDO::ATTR_PREFETCH
	 * PDO::ATTR_SERVER_INFO
	 * PDO::ATTR_SERVER_VERSION
	 * PDO::ATTR_TIMEOUT
	 * </p>
	 * @return mixed A successful call returns the value of the requested PDO attribute.
	 * An unsuccessful call returns null.
	 */
	public function getAttribute($attribute) {
		$attributes = $this->getAttributes();
		if (isset($attributes[$attribute])) {
			return $attributes[$attribute];
		}

		return null;
	}

	/**
	 * Return available drivers
	 * @return array
	 */
	public static function getAvailableDrivers() {
		return array('oci');
	}

	/**
	 * Checks if inside a transaction
	 * @return boolean
	 */
	public function inTransaction() {
		return $this->_inTransaction;
	}

	/**
	 * (non-PHPdoc)
	 * @see PDO::lastInsertId()
	 * @param string $name Name of Oracle sequence object
	 * @return int
	 */
	public function lastInsertId($name) {
		throw new Exception('lastInsertId($name) method is not implemented');
	}

	/**
	 * Prepares a statement for execution and returns a statement object
	 * @param string $statement
	 * @param array $driver_options
	 * @return OciPdoStatementAdapter
	 */
	public function prepare($statement, array $driver_options = array()) {
		$stmt = @oci_parse($this->_dbh, $statement);
		$this->checkError($stmt);

		$ociPdoStatement = new OciPdoStatementAdapter($this, $stmt, $driver_options + $this->getAttributes());
		$ociPdoStatement->queryString = $statement;

		return $ociPdoStatement;
	}

	/**
	 * (non-PHPdoc)
	 * @see PDO::query()
	 */
	public function query($statement) {
		$ociPdoStatement = $this->prepare($statement);
		$ociPdoStatement->execute();

		return $ociPdoStatement;
	}

	/**
	 * (non-PHPdoc)
	 * @see PDO::quote()
	 */
	public function quote($string, $parameter_type = PDO::PARAM_STR) {
		if ($parameter_type != PDO::PARAM_STR) {
			throw new Exception('Use prepare() and bind parameters');
		}
		else {
			return "'" . str_replace("'", "''", $string) . "'";
		}
	}

	/**
	 * Rolls back a transaction
	 * @return boolean
	 */
	public function rollback() {
		if (!$this->inTransaction()) {
			throw new Exception('There is no active transaction');
		}

		$res = @oci_rollback($this->_dbh);
		$this->checkError($res);

		$this->_inTransaction = false;
		$this->_commitMode = null;

		return $res;
	}

	/**
	 * Set an attribute
	 * @link http://www.php.net/manual/en/pdo.setattribute.php
	 * @param attribute int
	 * @param value mixed
	 * @return bool Returns true on success or false on failure.
	 */
	public function setAttribute($attribute, $value) {
		if (!array_key_exists($attribute, $this->_readonlyAttributes)) {
			$this->_attributes[$attribute] = $value;
			return true;
		}

		return false;
	}

	/**
	 * Destructor
	 */
	public function __destruct() {
		// Close connection and free resources
		if (is_resource($this->_dbh)) {
			$res = @oci_close($this->_dbh);
			$this->checkError($res);
		}
	}

	/**
	 * Check if $res === false and raise error
	 * @param mixed $res
	 */
	protected function checkError($res) {
		if ($res === false) {
			$this->raiseError();
		}
	}

	/**
	 * Close connection and free resources
	 * @return boolean
	 */
	public function closeConnection() {
		if (is_resource($this->_dbh)) {
			$res = @oci_close($this->_dbh);
			$this->checkError($res);
		}
		else {
			$res = true;
		}

		return $res;
	}

	/**
	 * Inits connection
	 */
	protected function initConnection($username = null, $passwd = null) {
		if (is_null(self::$_parsedDsn['charset'])) {
			self::$_parsedDsn['charset'] = 'UTF8';
		}

		$session_mode = OCI_DEFAULT;
		if ($username === '/' && empty($passwd)) {
			$session_mode = OCI_CRED_EXT;
		}

		if ($this->getAttribute(PDO::ATTR_PERSISTENT)) {
			$this->_dbh = @oci_pconnect($username, $passwd, self::$_parsedDsn['dbname'], self::$_parsedDsn['charset'], $session_mode);
		}
		else {
			$this->_dbh = @oci_new_connect ($username, $passwd, self::$_parsedDsn['dbname'], self::$_parsedDsn['charset'], $session_mode);
		}

		if (YII_DEBUG) {
			$this->checkError($this->_dbh);
		}
		else {
			if ($this->_dbh === false) {
				throw new CDbNoConnectionException('No DB');
			}
		}
	}

	/**
	 * Retrieve a database connection attribute
	 */
	protected function getAttributes() {
		if (empty($this->_attributes)) {
			foreach ($this->_readonlyAttributes as $attribute => $value) {
				$this->_attributes[$attribute] = $value;
			}

			foreach ($this->_defaultAttributes as $attribute => $value) {
				if(!array_key_exists($attribute, $this->_attributes)) {
					$this->_attributes[$attribute] = $value;
				}
			}
		}

		return $this->_attributes;
	}

	/**
	 * Get commit mode according to PDO::ATTR_AUTOCOMMIT
	 */
	public function getCommitMode() {
		if (is_null($this->_commitMode)) {
			if ($this->getAttribute(PDO::ATTR_AUTOCOMMIT)) {
				return OCI_COMMIT_ON_SUCCESS;
			}
			else {
				return ((PHP_VERSION_ID >= 503020) ? OCI_NO_AUTO_COMMIT : OCI_DEFAULT);
			}
		}

		return $this->_commitMode;
	}

	/**
	 * Get OCI connection
	 */
	public function getOciConnection() {
		return $this->_dbh;
	}

	/**
	 * Retrieve OCI error of the connection
	 */
	protected function getError() {
		if ($this->_error === false) {
			if (is_resource($this->_dbh)) {
				$this->_error = @oci_error($this->_dbh);
			}
			else {
				$this->_error = @oci_error();
			}
		}

		return $this->_error;
	}

	/**
	 * Parses a DSN string according to the rules in the PHP manual
	 *
	 * See also the PDO_User::parseDSN method in pecl/pdo_user. This method
	 * mimics the functionality provided by that method.
	 *
	 * @param string $dsn
	 * @return array
	 * @link http://www.php.net/manual/en/pdo.construct.php
	 */
	private static function parseDsn($dsn) {
		if (empty(self::$_parsedDsn)) {
			if (strpos($dsn, ':') !== false) {
				$driver = substr($dsn, 0, strpos($dsn, ':'));
				$vars = substr($dsn, strpos($dsn, ':') + 1);

				if ($driver == 'uri') {
					return self::parseDsn(file_get_contents($vars));
				} else {
					self::$_parsedDsn = array();
					self::$_parsedDsn['driverName'] = $driver;
					foreach (explode(';', $vars) as $var) {
						$param = explode('=', $var, 2); //limiting explode to 2 to enable full connection strings
						self::$_parsedDsn[$param[0]] = $param[1];
					}
					//return self::$parsedDSN;
				}
			} else if (strlen(trim($dsn)) > 0) {
				// The DSN passed in must be an alias set in php.ini
				return self::parseDsn(ini_get("pdo.dsn.{$dsn}"));
			}
		}

		return self::$_parsedDsn;
	}

	/**
	 * If there is an error writes to log or triggers error or throws an exception
	 * @throws Exception
	 */
	public function raiseError() {
		$error = $this->getError();

		if ($error === false) {
			return;
		}

		$this->_error = false;
		if ($error['offset'] == 0) {
			$message = sprintf('%s', $error['message']);
		}
		else {
			$message = sprintf('%s in %s at %s', $error['message'], $error['sqltext'], $error['offset']);
		}
		switch($this->getAttribute(PDO::ATTR_ERRMODE)) {
			case PDO::ERRMODE_SILENT:
				$message = sprintf('(%s) Error ' . $message, date('Y-m-d H:i:s'));
				error_log($message);
				break;
			case PDO::ERRMODE_WARNING:
				$message = 'Error ' . $message;
				$message = htmlentities($message, ENT_QUOTES);
				trigger_error($message, E_USER_ERROR);
				break;
			case PDO::ERRMODE_EXCEPTION:
			default:
				throw new Exception($message, $error['code']);
		}
	}

	/**
	 * Set attributes
	 * @param options array
	 */
	protected function setAttributes($options = array()) {
		if (is_array($options)) {
			foreach ($options as $attribute => $value) {
				$this->setAttribute($attribute, $value);
			}
		}
	}

}

?>
