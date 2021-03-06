<?php
namespace Dkd\CmisService\Extraction;

/**
 * Extraction Interface
 *
 * Implemented by classes which are capable of extracting
 * plain text representations of richly formatted or
 * marked-up text content or proprietary file types.
 */
interface ExtractionInterface {

	/**
	 * Perform extraction, returning a simple string.
	 *
	 * @param mixed $content
	 * @return mixed
	 */
	public function extract($content);

	/**
	 * Extracts CMIS Relationships from value if value
	 * defines any associations. Returns empty array
	 * if no associations are detected or configured.
	 * Returns an array of arrays of properties for
	 * each required Relationship to be created.
	 *
	 * @param mixed $content
	 * @param string $table
	 * @param string $field
	 * @return array[]
	 */
	public function extractAssociations($content, $table, $field);

}
