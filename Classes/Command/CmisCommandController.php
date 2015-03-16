<?php
namespace Dkd\CmisService\Command;

use Dkd\CmisService\Analysis\RecordAnalyzer;
use Dkd\CmisService\Analysis\TableConfigurationAnalyzer;
use Dkd\CmisService\Factory\CmisObjectFactory;
use Dkd\CmisService\Factory\ObjectFactory;
use Dkd\CmisService\Factory\QueueFactory;
use Dkd\CmisService\Factory\TaskFactory;
use Dkd\CmisService\Initialization;
use Dkd\CmisService\Task\TaskInterface;
use Dkd\CmisService\Queue\QueueInterface;
use Dkd\PhpCmis\CmisObject\CmisObjectInterface;
use Dkd\PhpCmis\Data\DocumentInterface;
use Dkd\PhpCmis\Data\FolderInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Extbase\Mvc\Controller\CommandController;
use TYPO3\CMS\Frontend\Page\PageRepository;

/**
 * CMIS Command Controller
 *
 * Main CLI interface for interacting with the
 * CMIS Service of this TYPO3 site.
 */
class CmisCommandController extends CommandController {

	const RESOURCE_CONFIGURATION = 'configuration';
	const RESOURCE_TREE = 'tree';

	/**
	 * @return void
	 */
	public function initializeObject() {
		$initializer = new Initialization();
		$initializer->start();
	}

	/**
	 * Dump resource data
	 *
	 * Dumps, as YAML, selected resource data. Supported
	 * resources are:
	 *
	 * - configuration
	 * - tree
	 *
	 * If no resource is specified, `configuration` is assumed.
	 *
	 * @param string $resource
	 * @param boolean $brief
	 * @return void
	 */
	public function dumpCommand($resource = self::RESOURCE_CONFIGURATION, $brief = TRUE) {
		$data = array();
		if (self::RESOURCE_CONFIGURATION === $resource) {
			$data = $this->getObjectFactory()->getConfiguration()->getDefinitions();
		} elseif (self::RESOURCE_TREE === $resource) {
			$rootFolder = $this->getCmisObjectFactory()->getSession()->getRootFolder();
			$data = $this->convertTreeBranchesToArrayValue($rootFolder->getChildren(), $brief);
		}
		$yaml = Yaml::dump($data, 99);
		$this->response->setContent($yaml);
		$this->response->send();
	}

	/**
	 * Recursive method to make a succint representation
	 * of a single branch and any children of that branch.
	 *
	 * @param CmisObjectInterface[] $object
	 * @param boolean $brief
	 * @return array|string
	 */
	protected function convertTreeBranchesToArrayValue(array $objects, $brief = TRUE) {
		$values = array();
		foreach ($objects as $object) {
			/** @var DocumentInterface|FolderInterface $object */
			$value = NULL;
			$type = $object->getProperty('cmis:baseTypeId')->getFirstValue();
			$date = $object->getCreationDate()->format('Y-m-d');
			if (TRUE === $brief) {
				$name = $object->getName() . ' (' . $object->getId() . ')';
			} else {
				$name = $type . ',' . $date . ' ' . $object->getName();
			}
			if (TRUE === $object instanceof FolderInterface) {
				$value = $this->convertTreeBranchesToArrayValue($object->getChildren(), $brief);
				if (array_fill(0, count($value), NULL) == array_values($value)) {
					// every value is NULL; flip value array so it becomes a list of names
					$value = array_keys($value);
				}
			} elseif (TRUE === $object instanceof DocumentInterface) {
				if (FALSE === $brief) {
					$value = array(
						'id' => $object->getId(),
						'typo3uuid' => $object->getProperty('typo3uuid'),
						'created' => $date . ' by ' . $object->getCreatedBy(),
						'modified' => $object->getLastModificationDate()->format('Y-m-d') . ' by ' . $object->getLastModifiedBy(),
						'type' => $type,
					);
				}
			} else {
				$value = get_class($object);
			}
			$values[$name] = $value;
		}
		return $values;
	}

	/**
	 * Truncate Queue
	 *
	 * Used when the queue should be completely flushed
	 * of all pending Tasks, regardless of status.
	 *
	 * @return void
	 */
	public function truncateQueueCommand() {
		$this->getQueue()->flush();
	}

	/**
	 * Generate Indexing Tasks
	 *
	 * Generates indexing Tasks for all monitored content.
	 * Indexing tasks are then processed by pickTask() or
	 * pickTasks($num). No actual interaction with CMIS
	 * is done by this command - the execution of indexing
	 * Tasks performs this check and if no updates are
	 * required, skips further processing and marks the
	 * Task as successfully completed.
	 *
	 * @param string $table Table to index, or empty for all tables.
	 * @return void
	 */
	public function generateIndexingTasksCommand($table = NULL) {
		if (NULL === $table) {
			$tableAnalyzer = $this->getTableConfigurationAnalyzer();
			$tables = $tableAnalyzer->getIndexableTableNames();
		} elseif (FALSE !== strpos($table, ',')) {
			$tables = explode(',', $table);
			$tables = array_map('trim', $tables);
		} else {
			$tables = array($table);
		}
		$this->createAndAddIndexingTasks($tables);
	}

	/**
	 * Generate all required indexing tasks for all tables
	 * in array $tables
	 *
	 * @param array $tables
	 * @return void
	 */
	protected function createAndAddIndexingTasks($tables) {
		$indexingTasks = array();
		$relationIndexingTasks = array();
		foreach ($tables as $table) {
			$records = $this->getAllEnabledRecordsFromTable($table);
			foreach ($records as $record) {
				$indexingTasks[] = $this->createRecordIndexingTask($table, $record);
				$relationIndexingTasks[] = $this->createRecordIndexingTask($table, $record, TRUE);
			}
		}
		$tasks = array_merge($indexingTasks, $relationIndexingTasks);
		$queue = $this->getQueue();
		$queue->addAll($tasks);
		$countTasks = count($indexingTasks);
		$countRelations = count($relationIndexingTasks);
		$messageText = 'Added %d %s task%s for table %s.';
		$message = sprintf($messageText, $countTasks, 'indexing', (1 !== $countTasks ? 's' : ''), $table) . PHP_EOL;
		$message .= sprintf($messageText, $countRelations, 'relation indexing', (1 !== $countRelations ? 's' : ''), $table);
		$this->response->setContent($message . PHP_EOL);
		$this->response->send();
	}

	/**
	 * @param string $table
	 * @param array $record
	 * @param boolean $includeRelations
	 * @return TaskInterface
	 */
	protected function createRecordIndexingTask($table, $record, $includeRelations = FALSE) {
		$taskFactory = $this->getTaskFactory();
		$recordAnalyzer = $this->getRecordAnalyzer($table, $record);
		$fields = $recordAnalyzer->getIndexableColumnNames();
		return $taskFactory->createRecordIndexingTask($table, $record['uid'], $fields, $includeRelations);
	}

	/**
	 * Pick and execute one (1) Task from the Queue
	 *
	 * Picks the next-in-line Task from the Queue and runs
	 * it, then exits.
	 *
	 * For multiple Tasks in one run, use pickTasks()
	 *
	 * @return void
	 */
	public function pickTaskCommand() {
		$this->pickTasksCommand(1);
	}

	/**
	 * Pick and execute one or more tasks from the Queue
	 *
	 * Pick the number of Tasks indicated in $tasks and run
	 * all of them in a single run.
	 *
	 * @param integer $tasks Number of tasks to pick and execute.
	 * @return void
	 */
	public function pickTasksCommand($tasks = 1) {
		$queue = $this->getQueue();
		while (0 <= --$tasks && ($task = $queue->pick())) {
			$result = $task->getWorker()->execute($task);
			$this->response->appendContent($result->getMessage() . PHP_EOL);
			$this->response->appendContent(var_export($result->getPayload(), TRUE) . PHP_EOL);
		}
		$this->response->send();
	}

	/**
	 * Reads the current queue status
	 *
	 * @return void
	 */
	public function statusCommand() {
		$queue = $this->getQueue();
		$count = $queue->count();
		$message = sprintf('%d job%s currently queued', $count, (1 !== $count ? 's' : ''));
		$this->response->setContent($message . PHP_EOL);
	}

	/**
	 * Get every record that is not deleted or disabled by
	 * TCA configuration, from $table.
	 *
	 * @param string $table
	 * @return array
	 */
	protected function getAllEnabledRecordsFromTable($table) {
		$pageRepository = $this->getPageRepository();
		// get an "enableFields" SQL condition, string starting with " AND ".
		$condition = $pageRepository->enableFields($table, 0, array(), TRUE);
		return $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('*', $table, '1=1' . $condition);
	}

	/**
	 * Creates an instance of TaskFactory to create Tasks.
	 *
	 * @codeCoverageIgnore
	 * @return TaskFactory
	 */
	protected function getTaskFactory() {
		return new TaskFactory();
	}

	/**
	 * Creates an instance of QueueFactory to create Queue instance.
	 *
	 * @codeCoverageIgnore
	 * @return QueueFactory
	 */
	protected function getQueueFactory() {
		return new QueueFactory();
	}

	/**
	 * Creates an instance of ObjectFactory to create new objects.
	 *
	 * @codeCoverageIgnore
	 * @return ObjectFactory
	 */
	protected function getObjectFactory() {
		return new ObjectFactory();
	}

	/**
	 * Creates an instance of CmisObjectFactory to create new CMIS objects.
	 *
	 * @codeCoverageIgnore
	 * @return CmisObjectFactory
	 */
	protected function getCmisObjectFactory() {
		return new CmisObjectFactory();
	}

	/**
	 * Gets an instance of the PageRepository which is used as
	 * support class to generate enableFields conditions.
	 *
	 * @codeCoverageIgnore
	 * @return PageRepository
	 */
	protected function getPageRepository() {
		return new PageRepository();
	}

	/**
	 * Prepare an instance of the table configuration analyzer
	 * which reads and checks tables and fields for indexability.
	 *
	 * @codeCoverageIgnore
	 * @return TableConfigurationAnalyzer
	 */
	protected function getTableConfigurationAnalyzer() {
		return new TableConfigurationAnalyzer();
	}

	/**
	 * Prepare an instance of the record analyzer.
	 *
	 * @param string $table
	 * @param array $record
	 * @return RecordAnalyzer
	 */
	protected function getRecordAnalyzer($table, $record) {
		return new RecordAnalyzer($table, $record);
	}

	/**
	 * Gets the Queue containing Tasks.
	 *
	 * @codeCoverageIgnore
	 * @return QueueInterface
	 */
	protected function getQueue() {
		return $this->getQueueFactory()->fetchQueue();
	}

}