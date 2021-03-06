<?php

namespace dface\Mysql;

class IteratorResult implements \JsonSerializable, \IteratorAggregate
{

	private \Iterator $iterator;

	public function __construct(\Iterator $generator)
	{
		$this->iterator = $generator;
	}

	public function getIterator() : \Iterator
	{
		return $this->iterator;
	}

	public function getValue(?string $key_field = null)
	{
		$record = $this->getRecord();
		if (empty($record)) {
			return null;
		}
		return $key_field !== null ? $record[$key_field] : reset($record);
	}

	public function getRecord() : ?array
	{
		return $this->iterator->current();
	}

	public function asRecords() : array
	{
		return \iterator_to_array($this->iterator);
	}

	public function asRecordsValues() : array
	{
		$result = [];
		foreach ($this->iterator as $record) {
			$result[] = \array_values($record);
		}
		return $result;
	}

	public function walk(callable $callback, ...$params) : void
	{
		foreach ($this->iterator as $record) {
			if ($callback($record, ...$params) === false) {
				return;
			}
		}
	}

	public function asMap(...$key_fields) : array
	{
		$result = [];
		switch (\count($key_fields)) {
			case 0:
				if ($cur = $this->iterator->current()) {
					$detected_key = array_keys($cur)[0];
					foreach ($this->iterator as $record) {
						$key_val = $record[$detected_key];
						$result[$key_val] = $record;
					}
				}
				break;
			case 1:
				foreach ($this->iterator as $record) {
					$key_val = $record[$key_fields[0]];
					$result[$key_val] = $record;
				}
				break;
			default:
				$last = \array_pop($key_fields);
				foreach ($this->iterator as $record) {
					$target = &$result;
					foreach ($key_fields as $key_field) {
						$key_val = $record[$key_field];
						if (!isset($target[$key_val])) {
							$target[$key_val] = [];
						}
						$target = &$target[$key_val];
					}
					$target[$record[$last]] = $record;
				}
		}
		return $result;
	}

	public function asKeyValue(?string $key_field = null, ?string $value_field = null) : array
	{
		$result = [];
		if ($cur = $this->iterator->current()) {
			[$first_field, $second_field] = \array_keys($cur);
			$key_field = $key_field ?: $first_field;
			$value_field = $value_field ?: $second_field;
			foreach ($this->iterator as $record) {
				$key = $record[$key_field];
				$val = $record[$value_field];
				$result[$key] = $val;
			}
		}
		return $result;
	}

	public function asColumn(?string $value_field = null) : array
	{
		$result = [];
		if ($cur = $this->iterator->current()) {
			$value_field = $value_field ?: \array_keys($cur)[0];
			foreach ($this->iterator as $record) {
				$val = $record[$value_field];
				$result[] = $val;
			}
		}
		return $result;
	}

	public function jsonSerialize() : array
	{
		return $this->asRecords();
	}

}
