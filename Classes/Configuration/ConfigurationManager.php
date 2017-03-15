<?php
namespace Dkd\CmisService\Configuration;

use Dkd\CmisService\Configuration\Definitions\MasterConfiguration;
use Dkd\CmisService\Configuration\Reader\ConfigurationReaderInterface;
use Dkd\CmisService\Configuration\Writer\ConfigurationWriterInterface;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Reflection\ObjectAccess;
use function version_compare;

/**
 * Configuration Manager
 *
 * Handles reading and writing of configuration.
 *
 * Allows three different levels of operation:
 *
 * 1) Pure Reader
 *
 * When the constructor is passed only a Reader
 * instance - which is required - the Manager will
 * behave in a read-only fashion, never dispatching
 * calls to the Writer or touching the cache.
 *
 * 2) Reader and Writer
 *
 * When given a Reader and a Writer, the Manager
 * operates by always reading from the Reader and
 * saving to the Writer as determined by a system
 * configuration parameter that must be enabled for
 * writing to happen - and a simple checksum to see if
 * the destination actually requires a rewrite. In this
 * mode the cache is never touched.
 *
 * 3) Reader, Writer and Cache
 *
 * When given all three Reader, Writer and another
 * Reader instance, the second Reader instance will
 * be used as a sort of cache; reading the definitions
 * from that source if it exists, otherwise defaulting
 * to the original Reader - resulting in this behavior:
 *
 *    - If $cache Reader exists and has a source that
 *      also exists, the source is loaded from $cache.
 *    - If $cache Reader exists but has no existing
 *      source, $reader Reader is used to fetch the
 *      definitions from storage.
 *    - If $writer Writer exists and has a target which
 *      has a checksum different than current definition,
 *      $writer is told to write target on lifecycle end.
 *
 * Note: since this behavior is governed by system settings
 * it is possible to let the Production environment not
 * use any caching or writing but simply read from a small
 * static YAML file with L1 caching only, for optimal speed.
 *
 * Note: since this class is a Singleton, constructor
 * parameters are only respected when the first instance is
 * created and ignored for all others.
 */
class ConfigurationManager {

	const CACHE_RESOURCE = '%s/Cache/Code/cmis-service-cache.yaml';
	const MASTER_RESOURCE = 'plugin.tx_cmisservice.settings';

	/**
	 * @var ConfigurationReaderInterface
	 */
	protected $cache;

	/**
	 * @var ConfigurationReaderInterface
	 */
	protected $reader;

	/**
	 * @var ConfigurationWriterInterface
	 */
	protected $writer;

	/**
	 * L1 cache for active MasterConfiguration
	 *
	 * @var MasterConfiguration
	 */
	protected $masterConfiguration;

	/**
	 * Global, active (TypoScript) configuration
	 *
	 * @var array
	 */
	protected $globalConfiguration;

	/**
	 * Constructor method. See class doc comment for usage.
	 *
	 * @param ConfigurationReaderInterface $reader
	 * @param ConfigurationWriterInterface $writer
	 * @param ConfigurationReaderInterface $cache
	 */
	public function __construct(
			ConfigurationReaderInterface $reader,
			ConfigurationWriterInterface $writer = NULL,
			ConfigurationReaderInterface $cache = NULL,
			array $globalConfiguration = array()
	) {
		$this->reader = $reader;
		$this->writer = $writer;
		$this->cache = $cache;
		$this->globalConfiguration = $globalConfiguration;
	}

	/**
	 * Gets a global configuration option or array by
	 * dotted path, e.g. "config.absRefPrefix" etc.
	 * Pass NULL for all global configuration. If
	 * $path is an array, each value is looked up
	 * and the first to return a not-NULL value is
	 * then returned.
	 *
	 * @param mixed $path
	 * @return mixed
	 */
	public function getGlobalConfiguration($path = NULL) {
		if (is_array($path)) {
			foreach ($path as $candidate) {
				$value = $this->getGlobalConfiguration($candidate);
				if (NULL !== $value) {
					return $value;
				}
			}
		} elseif (NULL === $path) {
			return $this->globalConfiguration;
		}
		return ObjectAccess::getPropertyPath($this->globalConfiguration, $path);
	}

	/**
	 * Gets (with on-the-fly loading) the active MasterConfiguration
	 * definition used by the system.
	 *
	 * @return MasterConfiguration
	 * @api
	 */
	public function getMasterConfiguration() {
		if (FALSE === $this->masterConfiguration instanceof MasterConfiguration) {
			$definitionClassName = 'Dkd\\CmisService\\Configuration\\Definitions\\MasterConfiguration';
			$cachedResource = GeneralUtility::getFileAbsFileName($this->getCachedResourceIdentifier());
			if (TRUE === $this->cache instanceof ConfigurationReaderInterface && TRUE === $this->cache->exists($cachedResource)) {
				$this->masterConfiguration = $this->cache->read($cachedResource, $definitionClassName);
			} else {
				$this->masterConfiguration = $this->reader->read(self::MASTER_RESOURCE, $definitionClassName);
				$this->createOrUpdateCachedDefinition();
			}
		}
		return $this->masterConfiguration;
	}

	/**
	 * Export the loaded MasterConfiguration to the target identified
	 * in the method parameter, using the ConfigurationWriter
	 * implementation currently configured. If no Writer is configured
	 * this method throws a RuntimeException.
	 *
	 * @param mixed $targetResourceIdentifier
	 * @return boolean
	 * @throws \RuntimeException
	 * @api
	 */
	public function export($targetResourceIdentifier) {
		if (FALSE === $this->writer instanceof ConfigurationWriterInterface) {
			throw new \RuntimeException('Cannot export configuration - no ConfigurationWriter configured', 1409181458);
		}
		$definition = $this->getMasterConfiguration();
		$targetResourceIdentifier = GeneralUtility::getFileAbsFileName($targetResourceIdentifier);
		return $this->writer->write($definition, $targetResourceIdentifier);
	}

	/**
	 * Expires (marks for rebuilding by deleting) the currently cached
	 * definition. Does nothing if no cached definition is configured.
	 * Warning: not public API! The only legitimate use is when delegated
	 * from listeners reacting to cache truncation in the framework.
	 *
	 * @return NULL
	 */
	public function expireCachedDefinition() {
		if (FALSE === $this->cache instanceof ConfigurationReaderInterface) {
			return NULL;
		}
		$this->removeResource(GeneralUtility::getFileAbsFileName($this->getCachedResourceIdentifier()));
		return NULL;
	}

	/**
	 * Creates or updates (if required) the cached representation.
	 * Returns NULL if no operation was performed. Returns TRUE if
	 * cached definition was updated successfully. Returns FALSE if
	 * some (silent) error occurred during writing, or if writing
	 * was skipped by the Writer but not due to checksum mismatch.
	 * On FALSE, further errors will have been logged.
	 *
	 * @return boolean|NULL
	 */
	protected function createOrUpdateCachedDefinition() {
		if (
			FALSE === $this->writer instanceof ConfigurationWriterInterface
			|| FALSE === $this->cache instanceof ConfigurationReaderInterface
		) {
			return NULL;
		}
		$cachedResourceIdentifier = GeneralUtility::getFileAbsFileName($this->getCachedResourceIdentifier());
		$currentChecksum = $this->reader->checksum(self::MASTER_RESOURCE);
		$cachedChecksum = $this->cache->checksum($cachedResourceIdentifier);
		if ($cachedChecksum !== $currentChecksum) {
			$this->expireCachedDefinition();
			return $this->export($cachedResourceIdentifier);
		}
		return NULL;
	}

	/**
	 * Removes a resource if it exists. Returns TRUE if the file
	 * was removed or if it did not already exist.
	 *
	 * @param string $resourceIdentifier
	 * @return boolean
	 */
	protected function removeResource($resourceIdentifier) {
		return file_exists($resourceIdentifier) ? unlink($resourceIdentifier) : TRUE;
	}

    /**
     * @return string
     */
	public function getCachedResourceIdentifier()
    {
        return sprintf(
            self::CACHE_RESOURCE,
            version_compare(
                ExtensionManagementUtility::getExtensionVersion('core'),
                8,
                '>='
            ) ? 'typo3temp/var' : 'typo3temp'
        );
    }

}
