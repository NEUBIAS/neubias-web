<?php

/**
 * @file
 * Contains \Drupal\node_importer\Importer\VocabularyImporter.
 */

namespace Drupal\node_importer\Importer;

use \Exception;

use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;

/**
 * Importer for vocabularies
 * 
 * @author Christoph Beger
 */
class VocabularyImporter extends AbstractImporter {
    
    function __construct($overwrite = false, $userId) {
    	parent::__construct($overwrite, $userId);
    	
        $this->entities['taxonomy_vocabulary'] = [];
        $this->entities['taxonomy_term'] = [];
    }
    
    public function import($data) {
        if (empty($data)) return;
        
        foreach ($data as $vocabulary) {
            $this->createVocabulary($vocabulary['vid'], $vocabulary['name']);
            $this->createTags($vocabulary['vid'], $vocabulary['tags']);
		    $this->setTagParents($vocabulary['vid'], $vocabulary['tags']);
        }
    }
    
    public function countCreatedVocabularies() {
        return sizeof($this->entities['taxonomy_vocabulary']);
    }
    
    public function countCreatedTags() {
        return sizeof($this->entities['taxonomy_term']);
    }
    
    /**
     * Creates a vocabulary.
     * 
     * @param $vid vid of the vocabulary
     * @param $name name of the vocabulary
     */
    public function createVocabulary($vid, $name) {
		if (is_null($vid)) throw new Exception('Error: parameter $vid missing.');
		if (is_null($name)) throw new Exception('Error: parameter $name missing.');
		
		if ($this->overwrite) {
			$exists = $this->clearVocabularyIfExists($vid);
    	
	    	if (!$exists) {
				$vocabulary = Vocabulary::create([
					'name'   => $name,
					'weight' => 0,
					'vid'    => $vid
				]);
				$vocabulary->save();
				$this->entities['taxonomy_vocabulary'][] = $vocabulary->id();
				$vocabulary = null;
			}
		} else {
			if (!$this->vocabularyExists($vid)) {
				$vocabulary = Vocabulary::create([
					'name'   => $name,
					'weight' => 0,
					'vid'    => $vid
				]);
				$vocabulary->save();
				$this->entities['taxonomy_vocabulary'][] = $vocabulary->id();
				$vocabulary = null;
			}
		}
	}
	
	/**
	 * Creates a set of Drupal tags for given vocabulary.
	 * Does not add parents to the tags, because they may not exit yet.
	 * 
	 * @param $vid vid of the vocabulary
	 * @param $tags array of tags
	 */
	public function createTags($vid, $tags) {
	    if (is_null($vid)) throw new Exception('Error: parameter $vid missing.');
	    if (empty($tags)) return;
	    
	    foreach ($tags as $tag) {
			$term = $this->createTag($vid, $tag['name']);
		}
	}
	
	/**
	 * Checks if a tag with given name already exists in given vocabulary.
	 * 
	 * @param $vid vocabulary
	 * @param $name name of the tag
	 * 
	 * @return {boolean}
	 */
	public function tagExists($vid, $name) {
		if (!isset($vid)) throw new Exception('Error: parameter $vid missing.');
		if (!isset($name)) throw new Exception('Error: parameter $name missing.');
		
		return null !== $this->searchTagIdByName($vid, $name);
	}
	
	/**
	 * Creates a single Drupal tag for given vocabulary.
	 * Does not add parents to the tags, because they may not exit yet.
	 * 
	 * @param $vid vid of the vocabulary
	 * @param $name name of the tag
	 */
	public function createTag($vid, $name) {
		if (is_null($vid)) throw new Exception('Error: parameter $vid missing.');
	    if (empty($name)) return;
	    
	    if ($this->tagExists($vid, $name)) {
	    	$this->logNotice(
	    		"Tag '$name' already exists in vocabulary " 
	    		. "'$vid', tick overwrite if you want to replace it."
	    	);
	    	return;
	    }
	    
	    $term = Term::create([
			'name' => $name,
			'vid'  => $vid,
		]);
		$term->save();
			
		$this->entities['taxonomy_term'][] = $term->id();
	}
	
	/**
	 * Checks of a vocabulary with given vid already exists
	 * and deletes all its tags if overwrite is true.
	 * 
	 * @param $vid vid of the vocabulary
	 * 
	 * @return boolean
	 */
	private function clearVocabularyIfExists($vid) {
		if ($this->vocabularyExists($vid)) {
			if ($this->overwrite) {
				$tids = $this->searchEntityIds([
	        		'entity_type' => 'taxonomy_term',
	        		'vid'         => $vid
	    		]);
				
				if (!empty($tids)) {
					$storage_handler = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
					$terms = $storage_handler->loadMultiple($tids);
					$storage_handler->delete($terms);
					
					$storage_handler = null;
					$terms = null;
					$tids = null;
				}
			} else {
				throw new Exception(
					'Error: vocabulary with vid "'. $vid. '" already exists. '
					. 'Tick "overwrite" if you want to replace it and try again.'
				);
	    	}
	    	return true;
	    }
	    return false;
	}
	
	/**
	 * Checks if a vocabulary with vid exists.
	 * 
	 * @param $vid vid to search for
	 * 
	 * @return boolean
	 */
	private function vocabularyExists($vid) {
		if (is_null($vid)) throw new Exception('Error: parameter $vid missing');

		$array = array_values($this->searchEntityIds([
			'vid'         => $vid,
			'entity_type' => 'taxonomy_vocabulary',
		]));
		$result = !empty($array) && $array[0] ? true : false;
		$array = null;
		
		return $result;
	}
	
	/**
	 * Adds parents to all created tags.
	 * 
	 * @param $vid vid of the vocabulary to process
	 * @param $tags all tags which were created previously
	 */
	public function setTagParents($vid, $tags) {
		if (is_null($vid)) throw new Exception('Error: parameter $vid missing.');
		if (empty($tags)) return;
		
		foreach ($tags as $tag) {
			if (empty($tag['parents'])) continue;
			
			$tagEntity = Term::load($this->searchTagIdByName($vid, $tag['name']));
			
			$tagEntity->parent->setValue($this->searchTagIdsByNames(
			    array_map(
			        function($parent) use($vid) { 
			        	return [ 'vid' => $vid, 'name' => $parent ];
			        }, 
			        $tag['parents']
			    )
			));
			$tagEntity->save();
		}
	}
	
}
 
?>