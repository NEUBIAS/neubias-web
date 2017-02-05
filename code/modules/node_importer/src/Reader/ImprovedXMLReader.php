<?php

/**
 * @file
 * Contains \Drupal\node_importer\Parser\ImprovedXMLReader.
 */

namespace Drupal\node_importer\Reader;

use Exception;

/**
 * @author Christoph Beger
 */
class ImprovedXMLReader extends \XMLReader {
   
   public function getAttributes() {
		if (!$this->hasAttributes) return [];
		
		$attributes = [];
		
		while ($this->moveToNextAttribute()) {
			$attributes[$this->name] = $this->value;
		}
		
		return $attributes;
	}
	
	public function getAttribute($name) {
		while ($this->moveToNextAttribute()) {
		    if ($this->name == $name)
		        return $this->value;
		}
		return null;
	}
	
	public function getChildren() {
	    $xml = new \SimpleXMLElement($this->readOuterXML());
	    
	    return $xml->children('owl', true);
	}
	
}