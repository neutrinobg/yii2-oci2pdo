<?php
/**
 * @see OciPdoAdapter
 */

namespace bobsbg\oci2pdo;

use \yii\db\Connection;

class OciDbConnection extends Connection {
	public $pdoClass = '\bobsbg\oci2pdo\OciPdoAdapter';
}

?>
