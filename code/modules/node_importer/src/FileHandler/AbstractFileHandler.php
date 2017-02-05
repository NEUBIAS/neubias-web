<?php

/**
 * @file
 * Contains \Drupal\node_importer\FileHandler\AbstractFileHandler.
 */

namespace Drupal\node_importer\FileHandler;

use Drupal\file\Entity\File;

/**
 * Abstract class which describes FileHandlers. All FileHandlers extend this class.
 * A FileHandler parses a given file and translates the content to a php object.
 * 
 * @author Christoph Beger
 */
abstract class AbstractFileHandler {
	protected $filePath;
	protected $data;
	protected $vocabularyImporter;
	protected $nodeImporter;
	protected $warnings = [];
	
	public function __construct($params) {
		if (empty($params)) throw new Exception('Error: no parameters provided.');
		if (is_null($params['path'])) throw new Exception('Error: named parameter "path" missing.');
		if (is_null($params['vocabularyImporter'])) throw new Exception('Error: named parameter "vocabularyImporter" missing.');
		if (is_null($params['nodeImporter'])) throw new Exception('Error: named parameter "nodeImporter" missing.');
		
		$this->data = [
			'vocabularies' => [],
			'nodes'        => [], 
		];
	
	    $this->filePath = $params['path'];
	    
	    $this->vocabularyImporter = $params['vocabularyImporter'];
	    $this->nodeImporter       = $params['nodeImporter'];
	}
	
	protected function logNotice($msg) {
		\Drupal::logger('node_importer')->notice($msg);
		print date('H:i:s', time()). "> $msg\n";
	}
	
	protected function logWarning($msg) {
		if (!in_array($msg, $this->warnings)) {
			\Drupal::logger('node_importer')->warning($msg);
			print date('H:i:s', time()). "> Warning: $msg\n";
			array_push($this->warnings, $msg);
		}
	}
	
	abstract public function setVocabularyData();
	
	abstract public function setNodeData();
	
	abstract public function setData();
	
}
 
?>