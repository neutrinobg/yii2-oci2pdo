<?php

namespace bobsbg\oci2pdo;

/**
 * Class to create stream from string
 * @author atonkin
 *
 */
class String2Stream {

	/**
	 *
	 * @param string $s The string
	 * @param boolean $temp Wheather to use php://temp or php://memory
	 * @return stream resource
	 */
	public static function create($s, $temp = false) {
		if ($temp) {
			$stream = fopen('php://temp', 'r+');
		}
		else {
			$stream = fopen('php://memory', 'r+');
		}

		fwrite($stream, $s);
		rewind($stream);

		return $stream;
	}

}

?>
