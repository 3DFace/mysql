<?php
/* author: Ponomarev Denis <ponomarev@gmail.com> */

namespace dface\Mysql;

class DuplicateEntryException extends MysqlException {

	/** @var string */
	private $key;
	/** @var string */
	private $entry;

	public function __construct(string $key, string $entry, string $message, $code = 0) {
		parent::__construct($message, $code);
		$this->key = $key;
		$this->entry = $entry;
	}

	function getKey() {
		return $this->key;
	}

	function getEntry() {
		return $this->entry;
	}

}
