<?php
namespace Dkd\CmisService\Execution\Cmis;

use Dkd\CmisService\Execution\AbstractExecution;
use Dkd\CmisService\Execution\ExecutionInterface;

/**
 * Class IndexExecution
 */
class IndexExecution extends AbstractExecution implements ExecutionInterface {

	/**
	 * Index a record, creating a document in the index.
	 *
	 * @return Result
	 */
	public function execute() {
		$this->result = $this->createResultObject();
		return $this->result;
	}

}
