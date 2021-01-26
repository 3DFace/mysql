<?php

namespace dface\Mysql;

class DuplicateEntryException extends MysqlException
{

	private string $key;
	private string $entry;

	public function __construct(string $key, string $entry, string $message, $code = 0)
	{
		parent::__construct($message, $code);
		$this->key = $key;
		$this->entry = $entry;
	}

	public function getKey() : string
	{
		return $this->key;
	}

	public function getEntry() : string
	{
		return $this->entry;
	}

}
