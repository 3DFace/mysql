<?php

namespace dface\Mysql;

use dface\sql\placeholders\Formatter;
use dface\sql\placeholders\FormatterException;
use dface\sql\placeholders\Node;
use dface\sql\placeholders\Parser;
use dface\sql\placeholders\ParserException;
use dface\sql\placeholders\PlainNode;

class MysqliConnection
{

	private Parser $parser;
	private Formatter $formatter;
	private \mysqli $link;
	private array $parsed = [];
	private ?\Exception $in_transaction = null;

	public function __construct(\mysqli $link, Parser $parser, Formatter $formatter)
	{
		$this->link = $link;
		$this->parser = $parser;
		$this->formatter = $formatter;
	}

	public function close() : void
	{
		$this->link->close();
	}

	/**
	 * @param string $isolationLevel
	 *
	 * @throws MysqlException
	 * @throws FormatterException
	 * @throws ParserException
	 */
	public function setIsolationLevel(string $isolationLevel) : void
	{
		$this->query('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolationLevel);
	}

	/**
	 * Begins transaction
	 *
	 * @return self
	 * @throws MysqlException
	 */
	public function begin() : MysqliConnection
	{
		if ($this->in_transaction !== null) {
			throw new MysqlException('Transaction is already started at: '.$this->in_transaction->getTraceAsString());
		}
		$this->in_transaction = new \Exception();
		$this->link->autocommit(false);
		return $this;
	}

	/**
	 * Commits transaction
	 * @return self
	 *
	 * @throws MysqlException
	 */
	public function commit() : MysqliConnection
	{
		if ($this->in_transaction === null) {
			throw new MysqlException('No active transaction to commit');
		}
		$this->link->commit();
		$this->link->autocommit(true);
		$this->in_transaction = null;
		return $this;
	}

	/**
	 * Rollbacks transaction
	 * @return self
	 *
	 * @throws MysqlException
	 */
	public function rollback() : MysqliConnection
	{
		if ($this->in_transaction === null) {
			throw new MysqlException('No active transaction to rollback');
		}
		$this->link->rollback();
		$this->link->autocommit(true);
		$this->in_transaction = null;
		return $this;
	}

	/**
	 * Parses a string with placeholders for later use for query building
	 * @param string $statement
	 * @return Node
	 *
	 * @throws ParserException
	 */
	public function prepare(string $statement) : Node
	{
		if (\strpos($statement, '{') !== false) {
			return $this->parse($statement);
		}
		return new PlainNode($statement);
	}

	/**
	 * @param string $statement
	 * @return mixed
	 *
	 * @throws ParserException
	 */
	private function parse(string $statement)
	{
		if (!isset($this->parsed[$statement])) {
			if (\count($this->parsed) > 100) {
				\array_shift($this->parsed);
			}
			$this->parsed[$statement] = $this->parser->parse($statement);
		}
		return $this->parsed[$statement];
	}

	/**
	 * Apply arguments to statement with placeholders to build complete query
	 * @param string|Node $statement
	 * @param $params
	 * @return PlainNode
	 *
	 * @throws ParserException|FormatterException
	 */
	public function build($statement, ...$params) : PlainNode
	{
		if (!($statement instanceof Node)) {
			if (strpos($statement, '{') !== false) {
				$statement = $this->prepare($statement);
			} else {
				$statement = new PlainNode($statement);
			}
		}
		return $this->formatter->format($statement, $params, [$this->link, 'real_escape_string']);
	}

	/**
	 * @param string|Node $statement
	 * @param $params
	 * @return bool|\mysqli_result
	 *
	 * @throws MySqlException|FormatterException|ParserException
	 */
	public function query($statement, ...$params)
	{
		return $this->queryOpt($statement, MYSQLI_STORE_RESULT, ... $params);
	}

	/**
	 * @param string|Node $statement
	 * @param int $options
	 * @param $params
	 * @return bool|\mysqli_result
	 *
	 * @throws MySqlException|FormatterException|ParserException
	 */
	public function queryOpt($statement, int $options, ...$params)
	{
		if (!($statement instanceof PlainNode)) {
			$statement = $this->build($statement, ...$params);
		}
		$statement = $statement->__toString();
		$this->preventDdlInTransaction($statement);
		$res = $this->link->query($statement, $options);
		if ($res === false) {
			throw $this->createException($this->link->error, $this->link->errno);
		}
		return $res;
	}

	/**
	 * @param string|Node $statement
	 * @param $params
	 *
	 * @throws MySqlException|FormatterException|ParserException
	 */
	public function multiUpdate($statement, ...$params) : void
	{
		if (!($statement instanceof PlainNode)) {
			$statement = $this->build($statement, ...$params);
		}
		$statement = $statement->__toString();
		$this->preventDdlInTransaction($statement);
		if (!$this->link->multi_query($statement)) {
			throw $this->createException($this->link->error, $this->link->errno);
		}
		do {
			if ($res = $this->link->use_result()) {
				$res->free();
			}
		} while ($this->link->more_results() && $this->link->next_result());
	}

	/**
	 * @param string|Node $statement
	 * @param $params
	 * @return IteratorResult
	 *
	 * @throws MySqlException|FormatterException|ParserException
	 */
	public function select($statement, ...$params) : IteratorResult
	{
		$res = $this->query($statement, ...$params);
		return new IteratorResult($this->gen($res));
	}

	/**
	 * @param string|Node $statement
	 * @param int $options
	 * @param $params
	 * @return IteratorResult
	 *
	 * @throws MySqlException|FormatterException|ParserException
	 */
	public function selectOpt($statement, int $options, ...$params) : IteratorResult
	{
		$res = $this->queryOpt($statement, $options, ...$params);
		return new IteratorResult($this->gen($res));
	}

	private function gen(\mysqli_result $res) : \Generator
	{
		while ($arr = $res->fetch_assoc()) {
			yield $arr;
		}
		$res->close();
	}

	/**
	 * @param string|Node $statement
	 * @param $params
	 * @return int
	 *
	 * @throws MySqlException|FormatterException|ParserException
	 */
	public function update($statement, ...$params) : int
	{
		$this->query($statement, ...$params);
		return $this->link->affected_rows;
	}

	/**
	 * @param string|Node $statement
	 * @param $params
	 * @return int
	 *
	 * @throws MySqlException|FormatterException|ParserException
	 */
	public function insert($statement, ...$params) : int
	{
		$this->query($statement, ...$params);
		return $this->link->insert_id;
	}

	/**
	 * @param string $message
	 * @param int $code
	 *
	 * @return MysqlException
	 */
	private function createException(string $message, int $code) : MysqlException
	{
		if (\preg_match("/Duplicate entry '(.+)' for key '(.+)'/", $message, $m)) {
			return new DuplicateEntryException($m[2], $m[1], $message, $code);
		}
		return new MysqlException($message, $code);
	}

	/**
	 * @param string $statement
	 *
	 * @throws MysqlException
	 */
	private function preventDdlInTransaction(string $statement) : void
	{
		if ($this->in_transaction !== null && \preg_match('/^\s*(create|drop|alter)/i', $statement)) {
			throw new MysqlException('DDL inside transaction');
		}
	}

}
