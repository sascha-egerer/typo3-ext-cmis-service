<?php
namespace Dkd\CmisService\Queue;

use Dkd\CmisService\Execution\Result;
use Dkd\CmisService\Tests\Fixtures\Task\DummyTask;
use TYPO3\CMS\Core\Tests\UnitTestCase;

/**
 * Class SimpleWorkerTest
 *
 * @package Dkd\CmisService\Queue
 */
class SimpleWorkerTest extends UnitTestCase {

	/**
	 * Unit test
	 *
	 * @test
	 * @return void
	 */
	public function executeTaskReturnsExecutionResultObject() {
		$result = new Result();
		$task = $this->getMock('Dkd\\CmisService\\Tests\\Fixtures\\Task\\DummyTask', array('resolveExecutionObject'));
		$execution = $this->getMock('Dkd\\CmisService\\Tests\\Fixtures\\Execution\\DummyExecution', array('execute'));
		$task->expects($this->once())->method('resolveExecutionObject')->will($this->returnValue($execution));
		$execution->expects($this->once())->method('execute')->with($task)->will($this->returnValue($result));
		$worker = new SimpleWorker();
		$worker->execute($task);
	}

}
