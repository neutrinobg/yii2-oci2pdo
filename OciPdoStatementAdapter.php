<?php

namespace bobsbg\oci2pdo;

use \yii\db\Exception;

/**
 * OciPdoStatementAdapter class to simulate PDOStatement with OCI
 * @author atonkin
 *
 */
class OciPdoStatementAdapter implements \Iterator {

	/**
	 * OCI error
	 * @var mixed
	 */
	private $_error = false;

	/**
	 * OCI array of LOBs
	 * @var array
	 */
	private $_lobs = array();

	/**
	 * OCI LOB columns nums
	 * @var array
	 */
	private $_lobColumnsNum = array();

	/**
	 * OCI LOB columns names
	 * @var array
	 */
	private $_lobColumnsName = array();

	/**
	 * OciPdoStatementAdapter::$_lobs count
	 * @var int
	 */
	private $_lobsCount = 0;

	/**
	 * Cursor (statement handle) in the current statement
	 * @var resource
	 */
	private $_cursor = null;

	/**
	 * Statement driver options
	 * @var array
	 */
	private $_attributes = array();

	/**
	 * Used in setFetchMode
	 * @var int
	 */
	private $_fetchModeColNo = 0;

	/**
	 * Used in setFetchMode
	 * @var string
	 */
	private $_fetchModeClassName = 'stdClass';

	/**
	 * Used in setFetchMode
	 * @var array
	 */
	private $_fetchModeClassNameCtorArgs = array();

	/**
	 * Used in setFetchMode
	 * @var mixed
	 */
	private $_fetchModeObject = null;

	/**
	 * OciPdoAdapter driver instance
	 * @var OciPdoAdapter
	 */
	protected $ociPdoAdapter;

	/**
	 * Statement handler
	 * @var resource
	 */
	protected $stmt;

	/**
	 * Query string
	 * @var string
	 */
	public $queryString;

	/**
	 * Constructor
	 * @param OciPdoAdapter $pdoOci8 The Oci8PDO object for this statement
	 * @param resource $stmt Statement handle created with oci_parse()
	 * @param array $options Options for the statement handle
	 * @return void
	 */
	public function __construct(OciPdoAdapter $ociPdoAdapter, $stmt, $driverOptions = array()) {
		if (strtolower(@get_resource_type($stmt)) != 'oci8 statement') {
			throw new Exception(sprintf('Expected resource type for %s parameter is oci8 statement. %s received instead', $stmt, get_resource_type($stmt)));
		}

		$this->ociPdoAdapter = $ociPdoAdapter;
		$this->stmt = $stmt;
		$this->setAttributes($driverOptions);
	}

	/**
	 * Binds a column to a PHP variable
	 * @param mixed $column The number of the column or name of the column
	 * @param mixed $param The PHP variable to which the column should be bound
	 * @param int $type
	 * @param int $maxLength
	 * @param mixed $options
	 * @return bool
	 */
	public function bindColumn($column, &$param, $type = PDO::PARAM_STR, $maxlen = -1, $driverdata = null) {
		$type = $this->removeBitFlag($$type, PDO::PARAM_INPUT_OUTPUT);
		$ociParamType = $this->pdo2OciParamConst($type);
		// LOBs
		if ($lob_desc = $this->oci_lob_desc($ociParamType)) {
			$this->_lobs[$this->_lobsCount]['type'] = $ociParamType;
			$this->_lobs[$this->_lobsCount]['lob'] = @oci_new_descriptor($this->ociPdoAdapter->getOciConnection(), $lob_desc);
			$res = $this->_lobs[$this->_lobsCount]['lob'];
			$this->checkError($res);
			$res = @oci_define_by_name($this->stmt, $column, $this->_lobs[$this->_lobsCount]['lob'], $ociParamType);
			$this->checkError($res);
			$this->_lobs[$this->_lobsCount]['var'] = $param;
			$this->_lobs[$this->_lobsCount]['input'] = false;

			$this->_lobsCount++;
		}
		else {
			$res = @oci_define_by_name($this->stmt, $column, $param, $ociParamType);
			$this->checkError($res);
		}

		return $res;
	}

	/**
	 * Binds a parameter to the specified variable name
	 * @param string $parameter
	 * @param mixed $variable
	 * @param int $data_type
	 * @param int $length
	 * @param array $driver_options
	 * @return bool
	 */
	public function bindParam($parameter, &$variable, $data_type = PDO::PARAM_STR, $length = 4000, $driver_options = null) {
		if (strpos($parameter, ':') === false) {
			$parameter = ':' . $parameter;
		}

		if (stripos($this->queryString, $parameter) === false) {
			return true;
		}

		$isOutputParameter = $this->checkBitFlag($data_type, PDO::PARAM_INPUT_OUTPUT);
		$data_type = $this->removeBitFlag($data_type, PDO::PARAM_INPUT_OUTPUT);
		$ociParamType = $this->pdo2OciParamConst($data_type);

		if ($ociParamType === SQLT_CHR) {
			$variable = (string) $variable;
		}

		if (is_array($variable)) {
			// TODO Не съм сигурен, дали ще се използва някога
			$res = @oci_bind_array_by_name($this->stmt, $parameter, $variable, count($variable), $length, $ociParamType);
			$this->checkError($res);
		}
		else {
			// Cursor
			if ($ociParamType == OCI_B_CURSOR) {
				$statementType = @oci_statement_type($this->stmt);
				$this->checkError($statementType);
				if(!in_array($statementType, array('BEGIN', 'DECLARE'))) {
					throw new Exception('Bind cursor only in BEGIN or DECLARE statement');
				}
				$this->_cursor = @oci_new_cursor($this->ociPdoAdapter->getOciConnection());
				$res = $this->_cursor;
				$this->checkError($res);
				$res = @oci_bind_by_name($this->stmt, $parameter, $this->_cursor, -1, $ociParamType);
				$this->checkError($res);
			}
			// LOBs
			elseif ($lob_desc = $this->oci_lob_desc($ociParamType)) {
				$this->_lobs[$this->_lobsCount]['type'] = $ociParamType;
				$this->_lobs[$this->_lobsCount]['lob'] = @oci_new_descriptor($this->ociPdoAdapter->getOciConnection(), $lob_desc);
				$res = $this->_lobs[$this->_lobsCount]['lob'];
				$this->checkError($res);
				$res = @oci_bind_by_name($this->stmt, $parameter, $this->_lobs[$this->_lobsCount]['lob'], -1, $ociParamType);
				$this->checkError($res);
				if (! $isOutputParameter) {
 					if (is_resource($variable) && get_resource_type($variable) === 'stream') {
 						$this->_lobs[$this->_lobsCount]['var'] = '';
 						$res = @$this->_lobs[$this->_lobsCount]['lob']->writeTemporary($this->_lobs[$this->_lobsCount]['var'], (($ociParamType == SQLT_BLOB) ? OCI_TEMP_BLOB : OCI_TEMP_CLOB));
						$this->checkError($res);
						$buffer = 8192;
						while (! feof($variable)) {
							$res = @$this->_lobs[$this->_lobsCount]['lob']->write(fread($variable, $buffer));
							$this->checkError($res);
							$res = @$this->_lobs[$this->_lobsCount]['lob']->flush();
							$this->checkError($res);
						}
					}
					else {
						$variable = (string) $variable;
						$this->_lobs[$this->_lobsCount]['var'] = &$variable;
						$res = @$this->_lobs[$this->_lobsCount]['lob']->writeTemporary($this->_lobs[$this->_lobsCount]['var'], (($ociParamType == SQLT_BLOB) ? OCI_TEMP_BLOB : OCI_TEMP_CLOB));
						$this->checkError($res);
						$res = @$this->_lobs[$this->_lobsCount]['lob']->flush();
						$this->checkError($res);
					}
				}
				else {
					$this->_lobs[$this->_lobsCount]['var'] = &$variable;
				}
				$this->_lobs[$this->_lobsCount]['input'] = ! $isOutputParameter;

				$this->_lobsCount++;
			}
			// Other
			else {
				$res = @oci_bind_by_name($this->stmt, $parameter, $variable, $length, $ociParamType);
				$this->checkError($res);
			}
		}

		return $res;
	}

	/**
	 * Binds a value to a parameter
	 * @param string $parameter
	 * @param mixed $value
	 * @param int $dataType
	 * @return bool
	 */
	public function bindValue($parameter, $value, $data_type = PDO::PARAM_STR) {
		return $this->bindParam($parameter, $value, $data_type);
	}

	/**
	 * Closes the cursor, enabling the statement to be executed again.
	 * @return bool
	 */
	public function closeCursor() {
		if (is_resource($this->stmt)) {
			// Free statement
			$res = @oci_free_statement($this->stmt);
			$this->checkError($res);
		}
		else {
			$res = true;
		}

		// Free LOBs if any
		if (!empty($this->_lobs)) {
			foreach ($this->_lobs as $key => &$value) {
				$res = $value['lob']->free();
				$this->checkError($res);
			}
			$this->_lobs = array();
			$this->_lobsCount = 0;

			$res = true;
		}

		return $res;
	}

	/**
	 * Returns the number of columns in the result set
	 * @return int
	 */
	public function columnCount() {
		$res = @oci_num_fields($this->stmt);
		$this->checkError($res);

		return $res;
	}

	/**
	 * Dumps the informations contained by a prepared statement directly on the output
	 * @throws Exception
	 */
	public function debugDumpParams() {
		throw new Exception('You cannot debug with debugDumpParams()');
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
	 * Executes a prepared statement
	 * @param array $inputParams
	 * @return bool
	 */
	public function execute(array $input_parameters = array()) {
		foreach ($input_parameters as $parameter => &$variable) {
			$this->bindParam($parameter, $variable);
		}

		$res = @oci_execute($this->stmt, $this->ociPdoAdapter->getCommitMode());
		$this->checkError($res);

		for ($i = 0; $i < $this->columnCount(); $i++) {
			$cm = $this->getColumnMeta($i);
			if (in_array($cm['native_type'], array('BLOB', 'CLOB'))) {
				array_push($this->_lobColumnsNum, $i);
				array_push($this->_lobColumnsName, $cm['name']);
			}
		}

		if (!empty($this->_lobs)) {
			foreach ($this->_lobs as &$value) {
				if ($value['input']) {
					$res = @$value['lob']->free();
					$this->checkError($res);
				}
				else {
					$value['var'] = $this->lobToStream($value['lob']);
				}
			}
			$this->_lobs = array();
			$this->_lobsCount = 0;

			$res = true;
		}

		if (!is_null($this->_cursor)) {
			$this->closeCursor();
			$this->stmt = $this->_cursor;
			$this->_cursor = null;
			$this->execute();
		}

		return $res;
	}

	/**
	 * Fetches the next row from a result set
	 * @param int $fetch_style
	 * @param int $cursor_orientation
	 * @param int $cursor_offset
	 * @return mixed
	 */
	public function fetch($fetch_style = null, $cursor_orientation = PDO::FETCH_ORI_NEXT, $cursor_offset = 0) {
		if ($cursor_orientation != PDO::FETCH_ORI_NEXT) {
			throw new Exception('Only PDO::FETCH_ORI_NEXT cursor orientation is allowed');
		}

		if ($cursor_offset != 0) {
			throw new Exception('Only 0 cursor offset is allowed');
		}

		if (is_null($fetch_style)) {
			$fetch_style = $this->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE);
		}

		// TODO Iteration
		switch($fetch_style) {
			case PDO::FETCH_ASSOC:
				//$res = @oci_fetch_assoc($this->stmt);
				$res = @oci_fetch_array($this->stmt, OCI_ASSOC + OCI_RETURN_NULLS/* + OCI_RETURN_LOBS*/);
				if ($res !== false) {
					foreach ($this->_lobColumnsName as $key) {
						$res[$key] = $this->lobToStream($res[$key]);
					}
				}
				break;
			case PDO::FETCH_NUM:
				//$res = @oci_fetch_row($this->stmt);
				$res = @oci_fetch_array($this->stmt, OCI_NUM + OCI_RETURN_NULLS/* + OCI_RETURN_LOBS*/);
				if ($res !== false) {
					foreach ($this->_lobColumnsNum as $key) {
						$res[$key] = $this->lobToStream($res[$key]);
					}
				}
				break;
			case PDO::FETCH_BOTH:
				$res = @oci_fetch_array($this->stmt, OCI_BOTH + OCI_RETURN_NULLS/* + OCI_RETURN_LOBS*/);
				if ($res !== false) {
					foreach ($this->_lobColumnsNum as $key) {
						$res[$key] = $this->lobToStream($res[$key]);
					}
					foreach ($this->_lobColumnsName as $key) {
						$res[$key] = $this->lobToStream($res[$key]);
					}
				}
				break;
			case PDO::FETCH_COLUMN:
				$res = $this->fetchColumn($this->_fetchModeColNo);
				break;
			case PDO::FETCH_OBJ:
				$res = @oci_fetch_object($this->stmt);
				if ($res !== false) {
					foreach ($this->_lobColumnsName as $name) {
						$res->$name = $this->lobToStream($res->$name);
					}
				}
				break;
			case PDO::FETCH_INTO:
				$obj = @oci_fetch_object($this->stmt);
				// Check for error
				$this->checkError($obj);
				if ($obj !== false) {
					foreach ($this->_lobColumnsName as $name) {
						$res->$name = $this->lobToStream($res->$name);
					}
					$res = $this->populateObject($obj, $this->_fetchModeObject);
				}
				else {
					$res = false;
				}
				break;
			case PDO::FETCH_CLASS:
				$obj = @oci_fetch_object($this->stmt);
				// Check for error
				$this->checkError($obj);
				if ($obj !== false) {
					foreach ($this->_lobColumnsName as $name) {
						$res->$name = $this->lobToStream($res->$name);
					}
					$res = $this->populateObject($obj, $this->_fetchModeClassName, $this->_fetchModeClassNameCtorArgs);
				}
				else {
					$res = false;
				}
				break;
			case $this->checkBitFlag($fetch_style, PDO::FETCH_CLASSTYPE):
				// TODO
				// If fetch_style includes PDO::FETCH_CLASSTYPE (e.g. PDO::FETCH_CLASS | PDO::FETCH_CLASSTYPE)
				// then the name of the class is determined from a value of the first column.
				//break;
			default:
				throw new Exception(sprintf('Fetch style %s is not implemented in fetch', $fetch_style));
		}

		// Check for error
		$this->checkError($res);

		return $res;
	}

	/**
	 * Returns an array containing all of the result set rows
	 * @param int $fetch_style
	 * @param mixed $fetch_argument
	 * @param array $ctor_args
	 * @return mixed
	 */
	public function fetchAll($fetch_style = null, $fetch_argument = 0, array $ctor_args = array()) {
		if (is_null($fetch_style)) {
			$fetch_style = $this->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE);
		}

		$result = array();
		switch($fetch_style) {
			case PDO::FETCH_ASSOC:
				$res = @oci_fetch_all($this->stmt, $result, 0, -1, OCI_FETCHSTATEMENT_BY_ROW + OCI_ASSOC + OCI_RETURN_NULLS);
				if ((($res > 0) || ($res !== false)) && !empty($this->_lobColumnsName)) {
					foreach ($result as &$row) {
						foreach ($this->_lobColumnsName as $key) {
							$row[$key] = $this->lobToStream($row[$key]);
						}
					}
				}
				break;
			case PDO::FETCH_NUM:
				$res = @oci_fetch_all($this->stmt, $result, 0, -1, OCI_FETCHSTATEMENT_BY_ROW + OCI_NUM + OCI_RETURN_NULLS);
				if ((($res > 0) || ($res !== false)) && !empty($this->_lobColumnsName)) {
					foreach ($result as &$row) {
						foreach ($this->_lobColumnsNum as $key) {
							$row[$key] = $this->lobToStream($row[$key]);
						}
					}
				}
				break;
			case PDO::FETCH_COLUMN:
				$arr = array();
				$res = @oci_fetch_all($this->stmt, $arr, 0, -1, OCI_FETCHSTATEMENT_BY_COLUMN + OCI_NUM + OCI_RETURN_NULLS);
				if ((($res > 0) || ($res !== false)) && !empty($this->_lobColumnsName)) {
					if (in_array($fetch_argument, $this->_lobColumnsNum)) {
						foreach ($arr as &$row) {
							$row[$fetch_argument] = $this->lobToStream($row[$fetch_argument]);
						}
					}
				}
				$result = $arr[$fetch_argument];
				break;
			case PDO::FETCH_OBJ:
				$res = true;
				while(false !== ($obj = $this->fetch($fetch_style))) {
					$result[] = $obj;
				}
				break;
			case PDO::FETCH_FUNC:
				$fetch_style = PDO::FETCH_NUM;
				if (is_callable($fetch_argument)) {
					$res = true;
					while(false !== ($row = $this->fetch($fetch_style))) {
						$result[] = call_user_func_array($fetch_argument, $row);
					}
				}
				else {
					throw new Exception(sprintf('$fetch_argument %s is not callable', $fetch_argument));
				}
				break;
			case PDO::FETCH_CLASS:
				$fetch_style = PDO::FETCH_OBJ;
				$res = true;
				while(false !== ($obj = $this->fetch($fetch_style))) {
					$result[] = $this->populateObject($obj, $fetch_argument, $ctor_args);
				}
				break;
			default:
				throw new Exception(sprintf('Fetch style %s is not implemented in fetchAll', $fetch_style));
		}

		// Check for error
		$this->checkError($res);

		$this->closeCursor();

		return $result;
	}

	/**
	 * Returns a single column from the next row of a result set
	 * @param int $colNumber
	 * @return string
	 */
	public function fetchColumn($column_number = 0) {
		$res = @oci_fetch_array($this->stmt, OCI_NUM + OCI_RETURN_NULLS);
		$this->checkError($res);

		if ($res === false) {
			return false;
		}
		elseif (!isset($res[$column_number])) {
			return false;
		}
		else {
			if (in_array($column_number, $this->_lobColumnsNum)) {
				$res[$column_number] = $this->lobToStream($res[$column_number]);
			}
			return $res[$column_number];
		}
	}

	/**
	 * Fetches the next row and returns it as an object
	 * @param string $className
	 * @param array $ctor_args
	 * @return mixed
	 */
	public function fetchObject($class_name = 'stdClass', array $ctor_args = array()) {
		$obj = $this->fetch(PDO::FETCH_OBJ);
		if($class_name == 'stdClass') {
			$res = $obj;
		}
		else {
			$res = $this->populateObject($obj, $class_name, $ctor_args);
		}

		return $res;
	}

	/**
	 * Retrieve a statement handle attribute
	 * @return mixed
	 */
	public function getAttribute($attribute) {
		if (isset($this->_attributes[$attribute])) {
			return $this->_attributes[$attribute];
		}

		return null;
	}

	/**
	 * Returns metadata for a column in a result set
	 * The array returned by this function is patterned after that
	 * returned by PDO::getColumnMeta(). It includes the following
	 * elements:
	 * 	native_type
	 * 	driver:decl_type
	 * 	flags
	 * 	name
	 * 	table
	 * 	len
	 * 	precision
	 * 	pdo_type
	 * @param int $column Zero-based column index
	 * @return array
	 */
	public function getColumnMeta($column) {
		// Columns in oci8 are 1-based; add 1 if it's a number
		if (is_numeric($column)) {
			$column++;
		}

		$meta = array();
		$meta['native_type'] = @oci_field_type($this->stmt, $column);
		$this->checkError($meta['native_type']);

		$meta['driver:decl_type'] = @oci_field_type_raw($this->stmt, $column);
		$this->checkError($meta['driver:decl_type']);

		$meta['flags'] = array();
		$meta['name'] = @oci_field_name($this->stmt, $column);
		$this->checkError($meta['name']);

		$meta['table'] = null;
		$meta['len'] = @oci_field_size($this->stmt, $column);
		$this->checkError($meta['len']);

		$meta['precision'] = @oci_field_precision($this->stmt, $column);
		$this->checkError($meta['precision']);

		$meta['pdo_type'] = null;

		return $meta;
	}

	/**
	 * Advances to the next rowset in a multi-rowset statement handle
	 * @return bool
	 */
	public function nextRowset() {
		throw new Exception('nextRowset() method is not implemented');
		//return true;
	}

	/**
	 * Returns the number of rows affected by the last executed statement
	 * @return int
	 */
	public function rowCount() {
		$res = @oci_num_rows($this->stmt);
		$this->checkError($res);

		return $res;
	}

	/**
	 * Sets an attribute on the statement handle
	 * @param int $attribute
	 * @param mixed $value
	 * @return bool
	 */
	public function setAttribute($attribute, $value) {
		$this->_attributes[$attribute] = $value;

		return true;
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

	/**
	 * (non-PHPdoc)
	 * @see PDOStatement::setFetchMode()
	 */
	public function setFetchMode() {
		$funcArgs = func_get_args();
		switch(func_num_args()) {
			case 0:
				throw new Exception('The fetch mode must be one of the PDO::FETCH_* constants');
			case 2:
				if (is_int($funcArgs[1])) {
					$this->_fetchModeColNo = $funcArgs[1];
				}
				elseif (is_object($funcArgs[1])) {
					$this->_fetchModeObject = $funcArgs[1];
				}
				else {
					$this->_fetchModeClassName = $funcArgs[1];
				}
				break;
			case 3:
				if (!is_array($funcArgs[2])) {
					throw new Exception('$ctorargs parameter must be array');
				}
				$this->_fetchModeClassName = $funcArgs[1];
				$this->_fetchModeClassNameCtorArgs = $funcArgs[2];
				break;
			default:

		}
		$this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, $funcArgs[0]);
	}


	/**
	 * Destructor
	 */
	public function __destruct() {
		// Free OCI stetement resources
		$res = $this->closeCursor();
		$this->checkError($res);
	}

	/**
	 * Check bit flag
	 * @param int $bitFlags
	 * @param int $flag
	 * @return boolean
	 */
	protected function checkBitFlag($bitFlags, $flag) {
		return ($flag === ($bitFlags & $flag));
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
	 * Retrieve OCI error of the statement
	 */
	protected function getError() {
		if ($this->_error === false) {
			if (is_resource($this->stmt)) {
				$this->_error = @oci_error($this->stmt);
			}
			else {
				$this->_error = @oci_error();
			}
		}

		return $this->_error;
	}

	/**
	 * Define the LOB descriptor type for the given type
	 * @param int $type LOB data type
	 * @return int|false Descriptor
	 */
	public function oci_lob_desc($type) {
		switch ($type) {
			case OCI_B_BFILE: $result = OCI_D_FILE; break;
			case OCI_B_CFILEE: $result = OCI_D_FILE; break;
			case OCI_B_CLOB:
			case SQLT_CLOB:
			case OCI_B_BLOB:
			case SQLT_BLOB: $result = OCI_D_LOB; break;
			case OCI_B_ROWID: $result = OCI_D_ROWID; break;
			default: $result = false; break;
		}

		return $result;
	}

	/**
	 * Convert string or OCI LOB to stream
	 * @param string|OCI-Lob $lob
	 * @return resource
	 */
	protected function lobToStream(&$lob) {
		if (is_null($lob)) {
			return null;
		}
		if (is_object($lob) && get_class($lob) == 'OCI-Lob') {
			return fopen('ocipdolob://', 'r', false, OciPdoLobStreamWrapper::getContext($lob));
		}
		else {
			return String2Stream::create($lob);
		}
	}

	/**
	 * Map PDO PARAM_* constants to OCI constants
	 * @param int $constant
	 * @return int
	 */
	public function pdo2OciParamConst($constant) {
		switch($constant) {
			case SQLT_CLOB:
			case OCI_B_CLOB:
				$result = SQLT_CLOB;
				break;
			case SQLT_BLOB:
			case OCI_B_BLOB:
			case PDO::PARAM_LOB:
				$result = SQLT_BLOB;
				break;
			case OCI_B_CURSOR:
				$result = OCI_B_CURSOR;
				break;
			default:
				$result = SQLT_CHR;
				break;
		}

		return $result;
	}

	/**
	 * Populate target object properties from source object
	 * If $targetObject is a class name then the method first instantiates it
	 * @param stdClass $sourceObject
	 * @param mixed $targetObject
	 * @param array $targetObjectCtorArgs
	 * @return object
	 */
	protected function populateObject($sourceObject, $targetObject, array $targetObjectCtorArgs = array()) {
		if (is_object($targetObject)) {
			$resultObject = $targetObject;
		}
		else {
			$ref = new ReflectionClass($targetObject);
			$resultObject = $ref->newInstanceArgs($targetObjectCtorArgs);
		}

		$ref = new ReflectionClass($sourceObject);
		foreach ($ref->getProperties() as $property) {
			$name=$property->getName();
			$resultObject->$name = $sourceObject->$name;
		}

		return $resultObject;
	}

	/**
	 * If there is an error writes to log or triggers error or throws an exception
	 * @throws Exception
	 */
	public function raiseError() {
		$this->ociPdoAdapter->raiseError();

		$error = $this->getError();

		if ($error === false) {
			return;
		}

		$this->_error = false;
		if ($error['offset'] == 0) {
			$message = sprintf('%s in %s', $error['message'], $this->queryString);
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
	 * Removes bit flag
	 * @param int $bitFlags
	 * @param int $flag
	 * @return int
	 */
	protected function removeBitFlag($bitFlags, $flag) {
		return $bitFlags &= ~$flag;
	}

	public function current() {
		throw new Exception('current() method is not implemented');
	}
	public function key() {
		throw new Exception('key() method is not implemented');
	}
	public function next() {
		throw new Exception('next() method is not implemented');
	}
	public function rewind() {
		throw new Exception('rewind() method is not implemented');
	}
	public function valid() {
		throw new Exception('valid() method is not implemented');
	}

}

?>
