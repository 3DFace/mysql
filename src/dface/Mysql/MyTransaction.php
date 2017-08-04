<?php
/* author: Ponomarev Denis <ponomarev@gmail.com> */

namespace dface\Mysql;

class MyTransaction {

	/** @var MysqliConnection */
	private $dbi;
	/** @var callable */
	private $callable;

	public function __construct(MysqliConnection $dbi, $callable) {
		$this->dbi = $dbi;
		$this->callable = $callable;
	}

	/**
	 * @param array $params
	 * @return mixed
	 * @throws \Throwable
	 */
	public function run(...$params){
		$this->dbi->begin();
		try{
			$fn = $this->callable;
			$result = $fn(...$params);
			$this->dbi->commit();
			return $result;
		}catch(\Throwable $e){
			$this->dbi->rollback();
			throw $e;
		}
	}

	/**
	 * @param int $retry_count
	 * @param int $retry_delay
	 * @param array $params
	 * @return mixed
	 * @throws \Throwable
	 */
	public function runRetry($retry_count = 0, $retry_delay = 0, ... $params) {
		$fn = $this->callable;
		$result = null;
		for($i = -1; $i < $retry_count; $i++){
			$this->dbi->begin();
			try{
				$result = $fn(...$params);
				$this->dbi->commit();
				break;
			}catch(\Throwable $e){
				$this->dbi->rollback();
				if($i < $retry_count - 1){
					if($retry_delay > 0){
						sleep($retry_delay);
					}
				}else{
					throw $e;
				}
			}
		}
		return $result;
	}

}
