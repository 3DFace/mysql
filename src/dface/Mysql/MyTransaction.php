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
	 * @return mixed
	 * @throws \Throwable
	 */
	public function run(){
		$this->dbi->begin();
		try{
			$fn = $this->callable;
			$result = $fn();
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
	 * @return mixed
	 * @throws \Throwable
	 */
	public function runRetry($retry_count = 0, $retry_delay = 0) {
		$fn = $this->callable;
		$result = null;
		for($i = -1; $i < $retry_count; $i++){
			$this->dbi->begin();
			try{
				$result = $fn();
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