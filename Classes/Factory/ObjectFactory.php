<?php
namespace Dkd\CmisService\Factory;

use Dkd\CmisService\Configuration\ConfigurationManager;
use Dkd\CmisService\Configuration\Definitions\MasterConfiguration;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\ObjectManagerInterface;

/**
 * Object Factory
 *
 * Wrapper for making instances of objects with constructor
 * arguments in a fashion that supports the host system's
 * object loader - in this default implementation using
 * Extbase's ObjectManager to create object instances.
 */
class ObjectFactory {

	const LOGGER_NAME = 'dkd.cmisservice';

	/**
	 * @var LoggerInterface
	 */
	protected static $logger;

	/**
	 * Make an instance of $className, if any additional parameters
	 * are present they will be used as constructor arguments.
	 *
	 * Note about potential porting to other frameworks:
	 *
	 * Some classes implement the SingletonInterface from this
	 * package which, in this TYPO3 CMS implementation context
	 * simply extends the framework's own SingletonInterface
	 * which, because this method also uses the TYPO3 CMS native
	 * way of creating new object instances, means that Singletons
	 * are supported without further code. Should any other
	 * implementation wish to support Singletons it can either
	 * use the same approach as this, to leverage the framework's
	 * Singletons if they exist - or, as a manual implementation
	 * of Singletons, store these instances in some registry if
	 * it implements this interface and then check this registry
	 * to be able to return the same instance in subsequent calls.
	 *
	 * @param string $className
	 * @return mixed
	 */
	public function makeInstance($className) {
		/** @var ObjectManagerInterface $manager */
		$manager = GeneralUtility::makeInstance('TYPO3\\CMS\\Extbase\\Object\\ObjectManager');
		$arguments = func_get_args();
		$instance = call_user_func_array(array($manager, 'get'), $arguments);
		return $instance;
	}

	/**
	 * Returns the initialized ConfigurationManager which gives
	 * access to all configuration parameters as well as exporting
	 * of current configuration.
	 *
	 * @return ConfigurationManager
	 */
	public function getConfigurationManager() {
		$managerClassName = $this->getConfigurationManagerClassName();
		$readerClassName = $this->getConfigurationReaderClassName();
		$writerClassName = $this->getConfigurationWriterClassName();
		$cacheClassName = $this->getConfigurationReaderCacheClassName();
		$writer = $cache = NULL;
		$reader = $this->makeInstance($readerClassName);
		if (NULL !== $writerClassName) {
			$writer = $this->makeInstance($writerClassName);
		}
		if (NULL !== $cacheClassName) {
			$cache = $this->makeInstance($cacheClassName);
		}
		$manager = $this->makeInstance('Dkd\\CmisService\\Configuration\\ConfigurationManager', $reader, $writer, $cache);
		return $manager;
	}

	/**
	 * Gets the configured PSR Logger implementation.
	 *
	 * @return LoggerInterface
	 */
	public function getLogger() {
		if (TRUE === self::$logger instanceof LoggerInterface) {
			return self::$logger;
		}
		/** @var LogManager $logManager */
		$logManager = $this->makeInstance('TYPO3\\CMS\\Core\\Log\\LogManager');
		return self::$logger = $logManager->getLogger(self::LOGGER_NAME);
	}

	/**
	 * Get the Master Configuration Definition which contains
	 * API methods to query every other configuration option.
	 *
	 * TYPO3 CMS specific information:
	 *
	 * In order to correctly bootstrap the configuration which is
	 * used for essential business logic of this package, the
	 * TypoScript information is read - but only key variables
	 * from _this_ reading of the TypoScript are used, namely
	 * the _configuration Reader and Writer and associated
	 * parameters_.
	 *
	 * If so configured, another configuration reader may read
	 * the TypoScript again, this time putting it into the API
	 * of the ConfigurationDefinition implementations. In the
	 * standard configuration this is the default approach, but
	 * other configuration may choose to use a static YAML file
	 * as only configuration source, ignoring any TypoScript
	 * except for these two key parameters for Reader and Writer.
	 *
	 * @return MasterConfiguration
	 */
	public function getConfiguration() {
		$configuration = $this->getConfigurationManager()->getMasterConfiguration();
		return $configuration;
	}

	/**
	 * Gets all TypoScript inside plugin.tx_cmisservice.settings.
	 *
	 * @return array
	 */
	public function getExtensionTypoScriptSettings() {
		/** @var ConfigurationManagerInterface $extbaseConfigurationManager */
		$extbaseConfigurationManager = $this->makeInstance('TYPO3\\CMS\\Extbase\\Configuration\\ConfigurationManager');
		return $extbaseConfigurationManager->getConfiguration(
			ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT,
			'Dkd.CmisService'
		);
	}

	/**
	 * Gets the configured class name to use as Configuration
	 * Manager implementation.
	 *
	 * @return string|NULL
	 */
	protected function getConfigurationManagerClassName() {
		return 'Dkd\\CmisService\\Configuration\\ConfigurationManager';
	}

	/**
	 * Gets the configured class name to use as Configuration
	 * Reader implementation.
	 *
	 * @return string|NULL
	 */
	protected function getConfigurationReaderClassName() {
		return 'Dkd\\CmisService\\Configuration\\Reader\\TypoScriptConfigurationReader';
	}

	/**
	 * Gets the configured class name to use as Configuration
	 * Writer implementation.
	 *
	 * @return string|NULL
	 */
	protected function getConfigurationWriterClassName() {
		return 'Dkd\\CmisService\\Configuration\\Writer\\YamlConfigurationWriter';
	}

	/**
	 * Gets the configured class name to use as Configuration
	 * Cache Reader implementation.
	 *
	 * @return string|NULL
	 */
	protected function getConfigurationReaderCacheClassName() {
		return 'Dkd\\CmisService\\Configuration\\Reader\\YamlConfigurationReader';
	}

}
