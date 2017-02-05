<?php

/**
 * @file
 * Contains \Drupal\node_importer\Importer\AbstractImporter.
 */

namespace Drupal\node_importer\Importer;

use \Exception;

/**
 * This abstract class declares all functions which are required for an Importer.
 * 
 * @author Christoph Beger
 */
abstract class AbstractImporter {
    /**
     * @var array $entities array of created entities, used to rollback.
     * @var boolean $overwrite importer overwrites existing nodes/vocabularies if true.
     */
    protected $entities  = [];
    protected $overwrite = false;
    protected $warnings  = [];
    protected $userId;
    
    public function __construct($overwrite = false, $userId) {
    	$this->userId = $userId;
    	if ($overwrite) $this->overwrite = true;
    }
    
    /**
     * Imports given data php object.
     * 
     * @param $data php object with the data to be imported
     * @param $overwrite specifies overwrite, default: false
     */
    abstract public function import($data);
    
    /**
     * Deletes all created entities.
     */
    public function rollback() {
         foreach ($this->entities as $type => $entities) {
         	$entity_manager = \Drupal::entityManager();
			foreach ($entities as $entity) {
				switch ($type) {
					case 'path':
						\Drupal::service('path.alias_storage')->delete([ 'pid' => $entity ]);
						break;
					case 'node': 
						$drupal_entity = $entity_manager->getStorage($type)->load($entity['nid']);
						if ($drupal_entity) $drupal_entity->delete();
						break;
					default:
						$drupal_entity = $entity_manager->getStorage($type)->load($entity);
						if ($drupal_entity) $drupal_entity->delete();
				}
			}
		}
     }
    
    /**
     * Returns an array of IDs for a given entity_type and additional restrictions.
     * 
     * @param $params array of named parameters
     *   "entity_type" is required.
     *   All additional fields are used to query the Drupal DB.
     * 
     * @return array of IDs
     */
    protected function searchEntityIds($params) {
		if (!$params['entity_type']) throw new Exception('Error: named parameter "entity_type" missing');
		$query = \Drupal::entityQuery($params['entity_type']);
		
		foreach ($params as $key => $value) {
			if ($key == 'entity_type') continue;
			$query->condition($key, $value);
			unset($key, $value);
		}
		$result = $query->execute();
		$query = null;
		
		return $result;
	}
	
	/**
	 * Returns the tid for a vid and tag name.
	 * 
	 * @param $vid vid of the vocabulary to search in
	 * @param $name name of the tag
	 * 
	 * @return integer tid
	 */
	protected function searchTagIdByName($vid, $name) {
	    if (!$vid) throw new Exception('Error: parameter $vid missing');
	    if (!$name) throw new Exception('Error: parameter $name missing');
	    
	    $result = $this->searchEntityIds([
	        'entity_type' => 'taxonomy_term',
	        'vid'         => $vid,
	        'name'        => $name
	    ]);
	    
	    return $result ? array_values($result)[0] : null;
	}
	
	/**
	 * Returns an array of tids for a set of tags.
	 * Each tag is represented by an array [vid, name].
	 * 
	 * @param $tag array of tag representations
	 * 
	 * @return array of tids
	 */
	protected function searchTagIdsByNames($tags) {
		if (empty($tags)) return [];
		
		return array_map(
			function($tag) {
				return $this->searchTagIdByName($tag['vid'], $tag['name']);
			}, 
			$tags
		);
	}
	
	protected function logWarning($msg) {
		if (!in_array($msg, $this->warnings)) {
			\Drupal::logger('node_importer')->warning($msg);
			print date('H:i:s', time()). "> Warning: $msg\n";
			array_push($this->warnings, $msg);
		}
	}
	
	protected function logNotice($msg) {
		\Drupal::logger('node_importer')->notice($msg);
		print date('H:i:s', time()). "> $msg\n";
	}
	
}
 
?>