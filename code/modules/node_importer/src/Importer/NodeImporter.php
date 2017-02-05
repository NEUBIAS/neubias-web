<?php

/**
 * @file
 * Contains \Drupal\node_importer\Importer\NodeImporter.
 */

namespace Drupal\node_importer\Importer;

use \Exception;

use Drupal\node\Entity\Node;
use Drupal\file\Entity\File;
use Drupal\core\StreamWrapper\StreamWrapperManager;

/**
 * Importer for nodes.
 * 
 * @author Christoph Beger
 */
class NodeImporter extends AbstractImporter {
	/**
	 * @var $nodeReferences array
	 *   Stores references between nodes and other entities,
	 *   to process them after all entities are created.
	 *   [ nid => [ field_name => [ refEntityType => [ EntityTitle, ... ] ], ... ], ... ]
	 */
    private $nodeReferences = [];
    
    const MAX_FIELDNAME_LENGTH = 32;

    function __construct($overwrite = false, $userId) {
    	parent::__construct($overwrite, $userId);
    	
        $this->entities['node'] = []; // [ nid => ..., uuid => ... ]
        $this->entities['file'] = [];
        $this->entities['path'] = [];
    }
    
    public function import($data) {
        if (empty($data)) return;
        
        foreach ($data as $node) {
		    $this->createNode($node);
	    }
	    $this->insertNodeReferences();
    }
    
    public function countCreatedNodes() {
        return sizeof($this->entities['node']);
    }
    
    public function countCreatedFiles() {
        return sizeof($this->entities['file']);
    }
    
    /**
     * Creates a node for given parameters.
     * 
     * @param $params array of parameters
     *   required:
     *     "title"
     *     "type" corresponds to bundle (e.g. "article" or "page")
     *   optional:
     *     "fields" contains the fields with names and values
     *     "alias"
     *     "uuid"
     * 
     * @return node
     */
    public function createNode($params) {
		if (!$params['title']) throw new Exception('Error: named parameter "title" missing.');
		if (!$params['type']) throw new Exception('Error: named parameter "type" missing.');
		$uuid = $params['uuid'] ? $params['uuid'] : $params['title'];
		
		try {
			$this->deleteNodeIfExists($uuid);
		} catch (Exception $e) {
			$this->logWarning($e->getMessage());
			return;
		}
		
		$type = $params['type'] ?: 'article';
		if (!$this->contentTypeExists($type)) {
			$this->logWarning("Content type '$type' does not exist in Drupal.");
			return;
		}
		
		$node = Node::create([
			'type'     => $type,
			'title'    => $params['title'],
			'uuid'     => $uuid,
			'langcode' => 'en', // @todo get language from import file
			'status'   => 1,
			'uid'      => $this->userId
		]);
		$node->save();
		
		if (array_key_exists('fields', $params))
			$this->insertFields($node, $params['fields']);
		
		if (array_key_exists('alias', $params))
			$this->addAlias([
				'id'    => $node->id(),
				'alias' => $params['alias']
			]);
			
		$this->entities['node'][] = [ 'nid' => $node->id(), 'uuid' => $uuid ];
		$node = null;
	}
	
	/**
	 * Checks if a content type exists with given id.
	 * 
	 * @param $type content type
	 * 
	 * @return boolean
	 */
	private function contentTypeExists($type) {
		return array_key_exists(
			$type,
			\Drupal::entityManager()->getBundleInfo('node')
		);
	}
	
	/**
	 * Creates a Drupal file for uri.
	 * 
	 * @param $uri uri representation of the file
	 * 
	 * @return file
	 */
	private function createFile($uri) {
		if (!$uri) throw new Exception('Error: parameter $uri missing.');
		$drupalUri = file_default_scheme(). '://'. $uri;
		
		// @todo: does not work in script
		if (!file_exists($drupalUri))
			throw new Exception("Error: file '$drupalUri' does not exist.");
		
		if ($fid = $this->searchFileByUri($drupalUri)) {
			$this->logNotice("Found file $fid for uri '$drupalUri'.");
			return File::load($fid);
		}
		
		$file = File::create([
			'uid'    => $this->userId,
			'uri'    => $drupalUri,
			'status' => 1,
		]);
		$file->save();
		$this->entities['file'][] = $file->id();
			
		return $file;
	}
	
	/**
	 * Checks of a node with given uuid already exists
	 * and deletes it if overwrite is true.
	 * 
	 * @param $uuid uuid of the node
	 */
	private function deleteNodeIfExists($uuid) {
		if (!$uuid) throw new Exception('Error: parameter uuid missing.');
		
		if (!is_null($id = $this->searchNodeIdByUuid($uuid))) {
			if ($this->overwrite) {
				\Drupal::service('path.alias_storage')->delete([ 'source' => '/node/'. $id ]);
				Node::load($id)->delete();
				$this->logNotice("Deleted node $id with uuid $uuid, because overwrite was selected.");
			} else {
				throw new Exception(
					"Node with uuid '$uuid' already exists. "
					. 'Tick "overwrite" if you want to replace it and try again.'
				);
			}
		}
	}
	
	/**
	 * Queries the drupal DB with node uuid and returns corresponding id.
	 * 
	 * @param $uuid uuid
	 * 
	 * @return id
	 */
	protected function searchNodeIdByUuid($uuid) {
	    if (!$uuid) throw new Exception('Error: parameter $uuid missing');
	    
	    $result = array_values($this->searchEntityIds([
	        'entity_type' => 'node',
	        'uuid'        => $uuid,
	    ]));
	    
	    return empty($result) ? null : $result[0];
	}
	
	/**
	 * Inserts fields into a node
	 * 
	 * @param $node drupal node
	 * @param $fields array of node fields
	 */
	private function insertFields($node, $fields) {
		if (!$node) throw new Exception('Error: parameter $node missing');
		if (empty($fields)) return;
		
		foreach ($fields as $field) {
			$fieldName = substr($field['field_name'], 0, self::MAX_FIELDNAME_LENGTH);
			
			if (!$this->nodeHasField($node, $fieldName)) {
				$this->logWarning(
					'field "'. $fieldName. '" does not exists in "'. $node->bundle(). '".'
				);
				continue;
			}
			
			if (array_key_exists('references', $field)
				&& ($field['references'] == 'taxonomy_term' || $field['references'] == 'node')
			) {
				$this->nodeReferences[$node->id()][$fieldName][$field['references']]
					= $field['value'];
			} else {
				if (array_key_exists('references', $field) && $field['references'] == 'file') {
					if (array_key_exists('uri', $field['value'])) {
						$file = $this->createFile($field['value']['uri']);
						$field['value']['target_id'] = $file->id();
						unset($file);
					} else {
						for ($i = 0; $i < sizeof($field['value']); $i++) {
							$file = $this->createFile($field['value'][$i]['uri']);
							$field['value'][$i]['target_id'] = $file->id();
							unset($file);
						}
					}
				}
				if (!is_null($field['value'])
					&& (!is_array($field['value']) || !is_null($field['value']['value']))
				) {
					$node->get($fieldName)->setValue($field['value']);
				}
				if (!is_null($field['value'])) {
					if (is_array($field['value'])) {
                        if (!empty($field['value'])
                    	    && (!array_key_exists('value', $field['value'])
                        	    || !is_null($field['value']['value'])
                            )
                        ) {
                        	$node->get($fieldName)->setValue($field['value']);
                        }
                    } else {
                        $node->get($fieldName)->setValue($field['value']);
                    }
                }

			}
			unset($field);
		}
		
		$node->save();
		$node = null;
	}
	
	/**
	 * Checks if node has field with given name.
	 * 
	 * @param $node drupal node
	 * @param $fieldName name to check for
	 * 
	 * @return boolean
	 */
	private function nodeHasField($node, $fieldName) {
		if (!$node) throw new Exception('Error: parameter $node missing.');
		if (!$fieldName) throw new Exception('Error: parameter $fieldName missing.');
		
		try {
			$node->get($fieldName);	
		} catch (Exception $e) {
			return false;
		}
		return true;
	}
	
	/**
	 * Adds an alias to a node.
	 * 
	 * @param $params array of parameters
	 *   "id" id of the node (required)
	 *   "alias" alias to be inserted (optional)
	 */
	private function addAlias($params) {
		if (!$params['id']) throw new Exception('Error: named parameter "id" missing.');
		if (!$params['alias']) return;
		
		$path = \Drupal::service('path.alias_storage')->save(
			'/node/'. $params['id'], 
			'/'. $params['alias'], 
			'en'
		);
		
		$this->entities['path'][] = $path['pid'];
	}
	
	/**
	 * Handles all in $nodeReferences saved references and inserts them.
	 */
	public function insertNodeReferences() {
		foreach ($this->nodeReferences as $pid => $field) {
			foreach ($field as $fieldName => $reference) { // assumption: only one entitytype per field
				foreach ($reference as $entityType => $entityNames) {
					$entityIds = [];
					
					switch ($entityType) {
						case 'taxonomy_term': 
							$entityIds = $this->searchTagIdsByNames($entityNames);
							break;
						case 'node':
							$entityIds = $this->mapNodeUuidsToNids($entityNames);
							break;
						default:
							throw new Exception(
								'Error: not supported entity type "'
								. $entityType. '" in reference found.'
							);
					}
					$node = Node::load($pid);
					$node->get($fieldName)->setValue($entityIds);
					$node->save();
				}
			}
		}
	}
	
	/**
	 * Returns an array of nids for a given array of recently created node uuids.
	 * 
	 * @param $uuids array of node uuids
	 * 
	 * @return array of nids
	 */
	private function mapNodeUuidsToNids($uuids) {
		if (empty($uuids)) return [];
		
		return array_map(
			function($uuid) { return $this->mapNodeUuidToNid($uuid); }, 
			$uuids
		);
	}
	
	/**
	 * Returns a nid for a recently created node uuid.
	 * 
	 * @param $uuid node uuid
	 * 
	 * @return {integer} nid
	 */
	private function mapNodeUuidToNid($uuid) {
		if (!$uuid) return null;
		
		foreach ($this->entities['node'] as $node) {
			if ($node['uuid'] == $uuid) 
				return $node['nid'];
		}
		
		return null;
	}
	
	private function searchFileByUri($uri) {
		if (!$uri) throw new Exception('Error: parameter $uri missing.');
		
		return array_values($this->searchEntityIds([ 'entity_type' => 'file', 'uri' => $uri]))[0];
	}
	
}

?>