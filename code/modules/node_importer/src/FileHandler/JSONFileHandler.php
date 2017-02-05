<?php

/**
 * @file
 * Contains \Drupal\node_importer\FileHandler\JSONFileHandler.
 */

namespace Drupal\node_importer\FileHandler;

/**
 * FileHandler which parses JSON files.
 * 
 * @author Christoph Beger
 */
class JSONFileHandler extends AbstractFileHandler {
	
	public function __construct($params) {
		parent::__construct($params);
		
		$this->data = json_decode(file_get_contents($this->filePath), TRUE);
		if (json_last_error() != 0) throw new Exception(
			'Error: Could not decode the json file.'
		);
	}
	
	public function setData() {
		$this->setVocabularyData();
		$this->setNodeData();
	}
	
	public function setVocabularyData() {
		foreach ($this->data['vocabularies'] as $vocabulary) {
			$this->vocabularyImporter->createVocabulary(
				$vocabulary['vid'], 
				$vocabulary['vid']
			);
			
			foreach ($vocabulary['tags'] as $tag) {
				$this->vocabularyImporter->createTag(
					$vocabulary['vid'],
					$tag['name']
				);
			}
			
			$this->vocabularyImporter->setTagParents(
				$vocabulary['vid'],
				[$tag]
			);
		}
	}
	
	public function setNodeData() {
		foreach ($this->data['nodes'] as $node) {
			$this->nodeImporter->createNode($node);
		}
		$this->nodeImporter->insertNodeReferences();
	}
}
 
?>