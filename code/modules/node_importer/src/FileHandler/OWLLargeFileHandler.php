<?php

/**
 * @file
 * Contains \Drupal\node_importer\FileHandler\OWLLargeFileHandler.
 */

namespace Drupal\node_importer\FileHandler;

use Drupal\file\Entity\File;
use Drupal\node_importer\Reader\ImprovedXMLReader;
use \Exception;

/**
 * FileHandler which parses large OWL files.
 * 
 * @author Christoph Beger
 */
class OWLLargeFileHandler extends AbstractFileHandler {
			
	/** Declaration of DUO default classes/properties **/ 
	const VOCABULARY       = 'http://www.lha.org/duo#Vocabulary';
	const NODE             = 'http://www.lha.org/duo#Node';
	const IMG              = 'http://www.lha.org/duo#Img';
	const ENTITY           = 'http://www.lha.org/duo#Entity';
	const DOC              = 'http://www.lha.org/duo#Doc';
	const BINARY           = 'http://www.lha.org/duo#Binary';
	const FILE             = 'http://www.lha.org/duo#File';
	const DOC_REF          = 'http://www.lha.org/duo#doc_ref';
	const ANNOTATION_FIELD = 'http://www.lha.org/duo#field';
	const DATATYPE_FIELD   = 'http://www.lha.org/duo#literal_field';
	const OBJECT_FIELD     = 'http://www.lha.org/duo#reference_field';
	const NAMED_INDIVIDUAL = 'http://www.w3.org/2002/07/owl#NamedIndividual';
	
	private $classesAsNodes         = false;
	private $onlyLeafClassesAsNodes = false;
	
	public function __construct($params) {
		parent::__construct($params);
		
		if ($params['classesAsNodes']) $this->classesAsNodes = true;
		if ($params['onlyLeafClassesAsNodes']) $this->onlyLeafClassesAsNodes = true;
	}
	
	public function setData() {
		$this->setVocabularyData();
		$this->setNodeData();
	}
	
	public function setVocabularyData() {
		foreach ($this->getVocabularyClasses() as $class) {
			$this->logNotice('Handling vocabulary: '. $class);
			$vid = $this->getLocalName($class);
			$this->vocabularyImporter->createVocabulary($vid, $vid);
			
			$this->logNotice('Collecting terms...');
			$tags = $this->findAllSubClassesOf($class);
			$this->logNotice('Found '. sizeof($tags). ' terms.');
			
			$this->logNotice('Inserting terms into Drupal DB...');
			foreach ($tags as $tag) {
				$label = $this->getProperty($this->getXMLElement($tag), 'label')[0];
				$name = $label ?: $this->getLocalName($tag);
				
				$this->vocabularyImporter->createTag($vid, $name);
			}
			
			$this->logNotice('Adding child parent linkages to terms...');
			foreach ($tags as $tag) {
				$label = $this->getProperty($this->getXMLElement($tag), 'label')[0];
				$name = $label ?: $this->getLocalName($tag);
				
				$this->vocabularyImporter->setTagParents(
					$vid,
					[[
						'name'    => $name,
						'parents' => $this->getParentTags($tag)
					]]
				);
			}
			
			unset($tags);
		}
	}
	
	public function setNodeData() {
		$this->logNotice('Collecting nodes...');
		$individuals = $this->getIndividuals();
		$this->logNotice('Found '. sizeof($individuals). ' nodes.');
		
		$this->logNotice('Inserting nodes into Drupal DB...');
		foreach ($individuals as $individual) {
			$xml = $this->getXMLElement($individual);
			$this->createNodeFields($individual);
			
			$node = [
				'title'  => $this->getNodeTitle($xml),
				'type'   => $this->getBundle($individual),
				'alias'  => $this->getProperty($xml, 'alias')[0],
				'fields' => $this->createNodeFields($individual),
				'uuid'   => $individual
			];
			
			$this->nodeImporter->createNode($node);
		}
		
		unset($individuals);
		
		$this->logNotice('Adding node references...');
		$this->nodeImporter->insertNodeReferences();
	}
	
	/**
	 * Returns the iri suffix.
	 * 
	 * @param $entity string
	 * 
	 * @return {string} local name
	 */
	private function getLocalName($entity) {
		return preg_replace('/^.*#/', '', $entity);
	}
	
	/**
	 * Returns an array with all classes under "Vocabulary".
	 * 
	 * @return array of classes
	 */
	private function getVocabularyClasses() {
		return $this->getDirectSubClassesOf(self::VOCABULARY); 
	}

	/** 
	 * Returns the bundle string for a given node resource.
	 * 
	 * @param $node resource object which is a member of a node subclass
	 * 
	 * @return string bundle
	 */
	private function getBundle($node) {
		if (!$node) throw new Exception('Error: parameter $node missing');
		
		foreach ($this->getDirectSubClassesOf(self::NODE) as $bundle) {
			if (
				$this->isATransitive($node, $bundle)
				|| $this->hasTransitiveSuperClass($node, $bundle)
			)
				return strtolower(preg_replace(
					'/[^A-Za-z0-9]/', '_', $this->getLocalName($bundle)
				));
		}
		
		return null;
	}
	
	/**
	 * Returns an array of all drupal-fields for a given individual.
	 * 
	 * @param $individual individual uri
	 * 
	 * @return array of node fields
	 */
	private function createNodeFields($individual) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		
		$properties = $this->getPropertiesAsArray($individual);
		
		$fields = [
			[
				'field_name' => 'body', 
				'value'      => [ 
					'value'   =>
						array_key_exists('content', $properties) 
						? $properties['content'][0] : null,
					'summary' =>
						array_key_exists('summary', $properties) 
						? $properties['summary'][0] : null,
					'format'  => 'full_html'
				]
			]
		];
		
		if ($this->classesAsNodes && !$this->onlyLeafClassesAsNodes) {
			$xml = $this->getXMLElement($individual);
			$fields[] = $this->createFieldParent($xml);
			
			if ($xml->getName() == 'Class')
				$fields[] = $this->createFieldChild($xml);
		}
		
		if (
			$this->isATransitive($individual, self::VOCABULARY)
			|| $this->hasTransitiveSuperClass($individual, self::VOCABULARY)
		) {
			$fields[] = [
				'field_name' => 'field_tags',
				'value'      => $this->createFieldTags($individual),
				'references' => 'taxonomy_term'
			];
		}
		
		foreach ($this->getProperties() as $property) {
			if (!array_key_exists($this->getLocalName($property), $properties))
				continue;

			if ($field = $this->createNodeField($individual, $property))
				$fields[] = $field;
		}
		
		return $fields;
	}
	
	private function createFieldParent($resource) {
		if (!$resource) throw new Exception('Error: parameter $resource missing.');
		
		if ($resource->getName() == 'Class') {
			return [
				'field_name' => 'field_parent',
				'value'      => array_filter(
					$this->getDirectSuperClassesOf($resource),
					function($x) {
						return $this->hasTransitiveSuperClass($x, self::NODE)
							&& !$this->hasDirectSuperClass($x, self::NODE);
					}
				),
				'references' => 'node'
			];
		} else {
			return [
				'field_name' => 'field_parent',
				'value'      => array_filter(
					$this->getTypesOf($resource),
					function($x) {
						return $this->hasTransitiveSuperClass($x, self::NODE)
							&& !$this->hasDirectSuperClass($x, self::NODE);
					}
				),
				'references' => 'node'
			];
		}
	}
	
	private function getTypesOf($resource) {
		if (!$resource) throw new Exception('Error: parameter $resource missing.');
		
		$properties = $this->getPropertiesAsArray($this->getUri($resource));
		return $properties['type'];
	}
	
	private function createFieldChild($class) {
		if (!$class) throw new Exception('Error: parameter $class missing.');
		
		return [
			'field_name' => 'field_child',
			'value'      => 
				array_merge(
					$this->getDirectSubClassesOf($this->getUri($class)),
					$this->getInstancesOf($class)
				),
			'references' => 'node'
		];
	}
	
	private function getInstancesOf($class) {
		if (!$class) throw new Exception('Error: parameter $class missing');
		
		$xml = new ImprovedXMLReader();
		$xml->open($this->filePath);
		
		$result = [];
		while ($xml->read()) {
			if ($xml->nodeType != ImprovedXMLReader::ELEMENT
				|| $xml->name != 'owl:NamedIndividual'
				|| $xml->depth != 1
			) continue;
			
			$node = new \SimpleXMLElement($xml->readOuterXML());
			
			foreach ($node->children('rdf', true) as $child) {
				if ($child->getName() != 'type') continue;
				$parent = $child->attributes('rdf', true)->resource;
			
				if ((string)$parent == $this->getUri($class))
					$result[] = $this->getUri($node);
			}
		}
		
		$xml->close();
		return $result;
	}
	
	/**
	 * Returns an array of tag tuple [vid, name].
	 * 
	 * @param $individual individual uri
	 * 
	 * @return array containing all tag tuples
	 */
	private function createFieldTags($individual) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		
		$properties = $this->getPropertiesAsArray($individual);
		$tags = array_merge(
			($properties['type'] ?: []),
			($properties['subClassOf'] ?: [])
		);
		
		$fieldTags = [];
		foreach ($tags as $tag) {
			if (!$this->hasTransitiveSuperClass($tag, self::VOCABULARY))
				continue;
			
			$vocabulary = $this->getVocabularyForTag($tag);
			
			$label = $this->getProperty($this->getXMLElement($tag), 'label')[0];
			$name = $label ?: $this->getLocalName($tag);
			
			$fieldTags[] = [
				'vid'  => $this->getLocalName($vocabulary),
				'name' => $name
			];
		}
		
		return $fieldTags ?: [];
	}
	
	/**
	 * Returns true if $superClass is a transitive superclass of $class.
	 * 
	 * @param $class class uri
	 * @param $subClass subclass to search for
	 * 
	 * @return boolean
	 */
	private function hasTransitiveSuperClass($class, $superClass) {
		if (!$class) throw new Exception('Error: parameter $class missing.');
		if (!$superClass) throw new Exception('Error: parameter $superClass missing.');
		
		foreach ($this->findAllSuperClassesOf($class) as $curSuperClass) {
			if ($curSuperClass == $superClass)
				return true;
		}
		return false;
	}
	
	/**
	 * Returns an array for a single field of a given node/individual.
	 * 
	 * @param $individual individual as uri
	 * @param $property IRI representation of the property
	 * 
	 * @return array with all properties of the field
	 */
	private function createNodeField($individual, $property) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		if (!$property) throw new Exception('Error: parameter $property missing');
		
		if ($literals = $this->getSortedLiterals($individual, $property)) { // includes DataProperties
			$field = [
				'value'      => $literals,
				'field_name' => $this->getLocalName($property)
			];
		} elseif ($this->getSortedResources($individual, $property)) { // includes ObjectProperties
			$field = $this->getResourceValuesForNodeField($individual, $property);
		}
		
		return $field ?: null;
	}
	
	/**
	 * Returns the value of the literal as string. Dates are converted to strings, using format().
	 * 
	 * @param $literal literal
	 * 
	 * @return string
	 */
	private function literalValueToString($literal) {
		if (!$literal) return null;
		
		if ($literal->getDatatype() == 'xsd:dateTime')
			return $literal->format('Y-m-d');
		
		return $this->removeRdfsType($literal->getValue());
	}
	
	/**
	 * Returns a string containing the title and the id of an entity.
	 * 
	 * @param $entity entity
	 * 
	 * @return string title
	 */
	private function getNodeTitle($entity) {
		$title = $this->getProperty($entity, 'title')[0];
		$localName = $this->getLocalName($this->getUri($entity));
		
		return $title ?: $localName;
	}
	
	/**
	 * Returns an array for a field with values for each referenced resource.
	 * 
	 * @param $individual individual uri
	 * @param $property the property for which the field should get constructed.
	 * 
	 * @return array containing fields: 'value' (array of values), 'field_name'
	 */
	private function getResourceValuesForNodeField($individual, $property) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		if (!$property) throw new Exception('Error: parameter $property missing');
		
		$resources = $this->getSortedResources($individual, $property);
		if (!$resources || empty($resources)) return null;
		
		$field = [
			'value' => [],
			'field_name' => $this->getLocalName($property)
		];
		
		foreach ($resources as $target) {
			$targetProperties = $this->getPropertiesAsArray($target);
			$xml = $this->getXMLElement($target);
			$value;
			
			if ($this->isATransitive($target, self::NODE)) {
				$value = $target;
				$field['references'] = 'node';
			// } elseif ($this->isATransitive($target, self::IMG)) {
			// 	$value = [
			// 		'alt'   => $targetProperties['alt'][0],
			// 		'title' => $targetProperties['title'][0],
			// 		'uri'   => $targetProperties['uri'][0]
			// 	];
			// 	$field['entity'] = 'file';
			} elseif (
				$this->isATransitive($target, self::FILE)
				|| $this->hasTransitiveSuperClass($target, self::FILE)
			) {
				$value = [ 'uri' => $this->getFilePath($target) ];
				$field['references'] = 'file';
			// } elseif ($this->isATransitive($target, self::DOC)) {
			// 	$refType = self::DOC_REF;
			} elseif (($vocabulary = $this->getVocabularyForTag($target)) != null) {
				$label = $this->getProperty($this->getXMLElement($target), 'label')[0];
				$name = $label ?: $this->getLocalName($target);
				
				$value = [
					'vid'  => $this->getLocalName($vocabulary),
					'name' => $name
				];
				$field['references'] = 'taxonomy_term';
			} elseif ($this->isATransitive($target, self::ENTITY)) {
				$axiom = $this->getAxiomWithTargetForIndividual($individual, $property, $target);
					
				foreach ($axiom->children('duo', true) as $child) {
					if ($child->getName() == 'field') {
						$fieldName = $this->getLocalName($child->attributes('rdf', true)->resource);
						break;
					}
				}
				
				if ($fieldName) {
					$value = $targetProperties[$fieldName][0];
				} else {
					$this->logWarning(
						'Entity \''. $this->getLocalName($target)
						. '\' by \''. $this->getLocalName($individual)
						. '\' referenced but no field given. '
						. '(\''. $this->getLocalName($property). '\')'
					);
					continue;
				}
			} else {
				$this->logWarning(
					"Nonexistent entity '". $this->getLocalName($target)
					. "' referenced by '". $this->getLocalName($individual)
					. "' and property '$property'"
				);
				continue;
			}
			
			$field['value'][] = $value;
		}
		
		return $field;
	}
	
	/**
	 * Returns the path to a file.
	 * Path is created from annotation duo:uri and all upper classes
	 * which are subclasses of duo:File, concatenated with '/'.
	 * 
	 * @param $uri class or individual
	 * 
	 * @return {string} path
	 */
	private function getFilePath($uri) {
		if (!$uri) throw new Exception('Error: parameter $uri missing.');
		$properties = $this->getPropertiesAsArray($uri);
		$xml = $this->getXMLElement($uri);
		
		$types = $this->getProperty($xml, 'type');
		$classes = $this->getProperty($xml, 'subClassOf');
		
		foreach ((!empty($classes) ? $classes : $types) as $class) {
			if (!$this->hasTransitiveSuperClass($class, self::FILE))
				continue;
			
			$result = $this->getProperty($this->getXMLElement($class), 'label')[0] 
				?: $this->getLocalName($class);
			foreach ($this->findAllSuperClassesOf($class) as $superclass) {
				if (!$this->hasTransitiveSuperClass($superclass, self::FILE))
					continue;
				$result = (
					$this->getProperty($this->getXMLElement($superclass), 'label')[0]
					?: $this->getLocalName($superclass)
				). '/'. $result;
			}
			
			return $result. '/'. $properties['uri'][0];
		}
		
		return $properties['uri'][0];
	}
	
	/**
	 * Returns an array containing all annotated targets of the axioms and all
	 * resources which have no assingned axiom. Axioms are prioriced.
	 * 
	 * @param $individual individual uri
	 * @param $property property uri
	 * 
	 * @return array of targeted resources as uris
	 */
	private function getSortedResources($individual, $property) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		if (!$property) throw new Exception('Error: parameter $property missing');
		
		$resources = $this->getResources($individual, $property);
		if (empty($resources)) return null;
		
		$axioms = $this->getAxiomsForIndividual($individual, $property);
		
		$result = [];
		foreach ($axioms as $axiom) {
			$result[] = $this->getProperty($axiom, 'annotatedTarget')[0];
		}
		
		foreach ($resources as $resource) {
			if (!in_array($resource, $result))
				$result[] = $resource;
		}
		
		return $result;
	}
	
	/**
	 * Returns an array of uris for a given individual and property.
	 * 
	 * @param $individual uri
	 * @param $property uri
	 * 
	 * @return array of uris
	 */
	private function getResources($individual, $property) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		if (!$property) throw new Exception('Error: parameter $property missing');
	
		$xml = $this->getXMLElement($individual);
		
		$result = [];
		foreach (['owl', 'duo', 'rdfs', 'rdf', ''] as $ns) {
			foreach ($xml->children($ns, true) as $child) {
				if (
					$child->getName() != $this->getLocalName($property)
					|| $child->attributes('rdf', true)->resource == null
				) continue;
				
				$result[] = (string)$child->attributes('rdf', true)->resource;
			}
		}
		
		return $result;
	}
	
	/**
	 * Returns a sorted array of literals for a given individual and property.
	 * 
	 * @param $individual individual uri
	 * @param $property property uri
	 * 
	 * @return array of literals sorted by ref_num if it exists
	 */
	private function getSortedLiterals($individual, $property) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		if (!$property) throw new Exception('Error: parameter $property missing');
		
		$literals = $this->getLiterals($individual, $property);
		if (empty($literals)) return null;
		
		$axioms = $this->getAxiomsForIndividual($individual, $property);
		
		$result = [];
		foreach ($axioms as $axiom) {
			$result[] = $this->getProperty($axiom, 'annotatedTarget')[0];
		}
		
		foreach ($literals as $literal) {
			if (!in_array($literal, $result))
				$result[] = $literal;
		}
		
		return $result;
	}
	
	/**
	 * Returns an array with all values for a literal property.
	 * 
	 * @param $individual uri
	 * @param $property uri
	 * 
	 * @return array of values
	 */
	private function getLiterals($individual, $property) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		if (!$property) throw new Exception('Error: parameter $property missing');
		
		$xml = $this->getXMLElement($individual);
		
		$result = [];
		foreach (['owl', 'duo', 'rdfs', 'rdf', ''] as $ns) {
			foreach ($xml->children($ns, true) as $child) {
				if (
					$child->getName() != $this->getLocalName($property)
					|| $child->attributes('rdf', true)->resource != null
				) continue;
				
				$result[] = (string)$child;
			}
		}
		
		return $result;
	}
	
	/**
	 * Returns a single axiom form a given set of axiom,
	 * which targets a given resource.
	 * 
	 * @param $individual individual references by the axiom
	 * @param $property property of the axiom
	 * @param $target target references by the axiom
	 * 
	 * @return array of axiom properties
	 */
	private function getAxiomWithTargetForIndividual($individual, $property, $target) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		if (!$property) throw new Exception('Error: parameter $property missing');
		if (!$target) throw new Exception('Error: parameter $target missing');
		
		$axioms = $this->getAxiomsForIndividual($individual, $property);
		
		foreach ($axioms as $axiom) {
			foreach ($axiom->children('owl', true) as $child) {
				if ($child->getName() != 'annotatedTarget')
					continue;
					
				if ((string)$child->attributes('rdf', true)->resource == $target)
					return $axiom;
			}
		}
		
		return null;
	}
	
	/**
	 * Returns value of the resource property
	 * 
	 * @param $resource xml
	 * @param $uri properties uri
	 * 
	 * @return
	 */
	private function getProperty($resource, $uri) {
		if (is_null($resource)) throw new Exception('Error: parameter $resource missing');
		if (is_null($uri)) throw new Exception('Error: parameter $uri missing');
		
		$result = [];
		foreach (['owl', 'rdf', 'rdfs', 'duo', ''] as $ns) {
			foreach ($resource->children($ns, true) as $child) {
				if ($child->getName() != $this->getLocalName($uri)) continue;
				
				if ($refResource = $child->attributes('rdf', true)->resource) {
					$result[] = $refResource;
				} else {
					$result[] = (string)$child;
				}
			}
		}
		
		return $result;
	}
	
	/**
	 * Returns the string without rdfs types at the end (e.g. '^^xsd:integer').
	 * 
	 * @param $string string with or without rdfs type suffix
	 * 
	 * @return string
	 */
	private function removeRdfsType($string) {
		if (!$string) return null;
		
		return preg_replace('/"?\^\^.*$/', '', $string);
	}
	
	/**
	 * Returns array of axioms for a given individual and property,
	 * sorted by ref_num if exists.
	 * 
	 * @param $individual individual uri
	 * @param $property property uri
	 * 
	 * @return array of SimpleXMLElements
	 */
	private function getAxiomsForIndividual($individual, $property) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		if (!$property) throw new Exception('Error: parameter $property missing');
		
		$xml = new ImprovedXMLReader();
		$xml->open($this->filePath);
		
		$result = [];
		$prevIndex = 0;
		
		while ($xml->read()) {
			if (
				$xml->nodeType != ImprovedXMLReader::ELEMENT
				|| $xml->name != 'owl:Axiom'
				|| $xml->depth != 1
			) continue;
			$axiom = new \SimpleXMLElement($xml->readOuterXML());
			
			if(
				$this->getProperty($axiom, 'annotatedSource')[0] != $individual
				|| $this->getProperty($axiom, 'annotatedProperty')[0] != $property
			) continue;
		 	
			$curIndex = $this->getProperty($axiom, 'ref_num')[0];
			if (!$curIndex)
				$curIndex = $prevIndex + 1;
			$result[$curIndex] = $axiom;
			$prevIndex = $curIndex;
		}
		
		ksort($result);
		return $result;
	}
	
	/**
	 * Returns the vid for a given tag.
	 * 
	 * @param $tag tag uri
	 * 
	 * @return string vid
	 */
	private function getVocabularyForTag($tag) {
		if (!$tag) throw new Exception('Error: parameter $tag missing');
		
		foreach ($this->getVocabularyClasses() as $vocabulary) {
			foreach ($this->findAllSubClassesOf($vocabulary) as $subClass) {
				if ($subClass == $tag)
					return $vocabulary;
			}
		}
		
		return null;
		// throw new Exception("Error: tag: '$tag->localName()' could not be found.");
	}
	
	/**
	 * Returns an array with all properties of an individual.
	 * 
	 * @param $individual individual uri
	 * 
	 * @return array of property value arrays
	 */
	private function getPropertiesAsArray($individual) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		
		$xml = $this->getXMLElement($individual);
		if ($xml == null) return [];
		
		$array = [];
		
		foreach (['rdfs', 'duo', 'owl', 'rdf', ''] as $ns) {
			foreach ($xml->children($ns, true) as $child) {
				if ($resource = $child->attributes('rdf', true)->resource) {
					$array[$child->getName()][] = (string)$resource;
				} else {
					$array[$child->getName()][] = (string)$child;
				}
			}
		}
		
		return $array;
	}
	
	/**
	 * Returns a class, property or individual by uri.
	 * 
	 * @param $uri uri to search for
	 * 
	 * @return SimpleXMLElement
	 */
	private function getXMLElement($uri) {
		$xml = new ImprovedXMLReader();
		$xml->open($this->filePath);
		
		while ($xml->read()) {
			if ($xml->nodeType != ImprovedXMLReader::ELEMENT
				|| !preg_match('/owl:/', $xml->name)
				|| $xml->depth != 1
			) continue;
			
			$node = new \SimpleXMLElement($xml->readOuterXML());
			
			if ($this->getUri($node) == $uri) {
				$xml->close();
				return $node;
			}
		}
		
		$xml->close();
		return null;
	}
	
	/**
	 * Returns all individuals under class Node and classes under node,
	 * depending on classesAsNodes and onlyLeafClasses.
	 * 
	 * @return array of uris
	 */
	private function getIndividuals() {
		$individuals = [];
		
		if ($this->classesAsNodes) {
			if ($this->onlyLeafClassesAsNodes) {
				foreach ($this->getDirectSubClassesOf(self::NODE) as $nodeTypeClass) {
					$individuals = array_merge(
						$individuals,
						$this->findAllLeafClassesOf($nodeTypeClass)
					);
				}
			} else {
				foreach ($this->getDirectSubClassesOf(self::NODE) as $nodeTypeClass) {
					$individuals = array_merge(
						$individuals,
						$this->findAllSubClassesOf($nodeTypeClass)
					);
				}
			}
		}
		
		foreach ($this->getAllOfType('owl:NamedIndividual') as $individual) {
			if (
				!$this->isATransitive($individual, self::NODE)
				|| $this->isA($individual, self::NODE) // individuals directly under Node are ignored
			) continue;
			$individuals[] = $individual;
		}
		
		return $individuals;
	}
	
	/**
	 * Returns all individuals of a specified type.
	 * 
	 * @param $type type of the requested individuals
	 * 
	 * @return array of uris
	 */
	private function getAllOfType($type) {
		$xml = new ImprovedXMLReader();
		$xml->open($this->filePath);
		
		$result = [];
		
		while ($xml->read()) {
			if ($xml->nodeType != ImprovedXMLReader::ELEMENT
				|| $xml->name != $type
				|| $xml->depth != 1
			) continue;
			
			$node = new \SimpleXMLElement($xml->readOuterXML());
			$result[] = $this->getUri($node);
		}
		
		$xml->close();
		return $result;
	}
	
	/**
	 * Returns all subclasses of the given class, which dont have subclasses (=leafs).
	 * Found leaf classes can be instantiated by individuals!
	 * 
	 * @param $class class uri
	 * 
	 * @return array of classes
	 */
	private function findAllLeafClassesOf($class) {
		if (is_null($class)) throw new Exception('Error: parameter $class missing.');
		
		$leafClasses = [];
		foreach ($this->findAllSubClassesOf($class) as $subClass) {
			if (empty($this->getDirectSubClassesOf($subClass)))
				$leafClasses[] = $subClass;
		}
		
		return $leafClasses;
	}
	
	/**
	 * Returns the direct subclasses of a given class uri.
	 * 
	 * @param $class class uri
	 * 
	 * @return array subclasses as uris
	 */
	private function getDirectSubClassesOf($class) {
		$xml = new ImprovedXMLReader();
		$xml->open($this->filePath);
		
		$result = [];
		while ($xml->read()) {
			if ($xml->nodeType != ImprovedXMLReader::ELEMENT
				|| $xml->name != 'owl:Class'
				|| $xml->depth != 1	
			) continue;
			$node = new \SimpleXMLElement($xml->readOuterXML());
			
			foreach ($node->children('rdfs', true) as $child) {
				if ($child->getName() != 'subClassOf') continue;
				$parent = $child->attributes('rdf', true)->resource;
			
				if ((string)$parent == $class) $result[] = $this->getUri($node);
			}
		}
		
		$xml->close();
		return $result;
	}
	
	private function getDirectSuperClassesOf($class) {
		if (is_null($class)) throw new Exception('Error: parameter $class missing.');
		
		$properties = $this->getPropertiesAsArray($this->getUri($class));
		return $properties['subClassOf'];
	}
	
	private function getAnnotationProperties() {
		$annotationProperties = $this->getAllOfType('owl:AnnotationProperty');
		
		$properties = [];
		foreach ($annotationProperties as $annotationProperty) {
			foreach ($this->getPropertiesAsArray($annotationProperty)['subPropertyOf'] as $superProperty) {
				if ($superProperty != self::ANNOTATION_FIELD)
					continue;
				$properties[] = $annotationProperty;
			}
		}
		
		return $properties;
	}
	
	private function getUri($resource) {
		if (is_null($resource)) throw new Exception('Error: parameter $resource missing.');
		
		return (string)$resource->attributes('rdf', true)->about;
	}
	
	private function getDatatypeProperties() {
		$datatypeProperties = $this->getAllOfType('owl:DatatypeProperty');
		
		$properties = [];
		foreach ($datatypeProperties as $datatypeProperty) {
			foreach ($this->getPropertiesAsArray($datatypeProperty)['subPropertyOf'] as $superProperty) {
				if ($superProperty != self::DATATYPE_FIELD)
					continue;
				$properties[] = $datatypeProperty;
			}
		}
		
		return $properties;
	}
	
	private function getObjectProperties() {
		$objectProperties = $this->getAllOfType('owl:ObjectProperty');
		
		$properties = [];
		foreach ($objectProperties as $objectProperty) {
			foreach ($this>getPropertiesAsArray($objectProperty)['subPropertyOf'] as $superProperty) {
				if ($superProperty != self::OBJECT_FIELD)
					continue;
				$properties[] = $objectProperty;
			}
		}
		
		return $properties;
	}
	
	private function getProperties() {
		return array_merge(
			$this->getAnnotationProperties(),
			$this->getDatatypeProperties(),
			$this->getObjectProperties()
		);
	}
	
	/**
	 * Returns all subclasses for a given class.
	 * This function calls it self recursively.
	 * 
	 * @param $class class uri
	 * 
	 * @return array of class resources
	 */
	private function findAllSubClassesOf($class) {
		$result = [];
		
		foreach ($this->getDirectSubClassesOf($class) as $subClass) {
			if (!in_array($subClass, $result)) {
				$result[] = $subClass;
				$result = array_merge($result, $this->findAllSubClassesOf($subClass));
			}
		}
	
		return array_unique($result);
	}
	
	/**
	 * Checks if the given individual is a transitive instantiation of given class.
	 * 
	 * @param $individual individual uri
	 * @param $superClass superClass uri
	 * 
	 * @return boolean
	 */
	private function isATransitive($individual, $superClass) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		if (!$superClass) throw new Exception('Error: parameter $superClass missing');
		
		if ($this->isA($individual, $superClass))
			return true;
		
		$xml = $this->getXMLElement($individual);
		foreach ($this->getProperty($xml, 'type') as $directSuperClass) {
			if ($superClass == $directSuperClass)
				return true;
			
			foreach ($this->findAllSuperClassesOf($directSuperClass) as $curClass) {
				if ($superClass == $curClass)
					return true;
			}
		}
		
		return false;
	}
	
	private function findAllSuperClassesOf($class) {
		if (!$class) throw new Exception('Error: parameter $class missing.');
		
		$xml = $this->getXMLElement($class);
		if (is_null($xml)) return [];
		$result = $this->getProperty($xml, 'subClassOf');
		
		foreach ($result as $superClass) {
			$result = array_merge($result, $this->findAllSuperClassesOf($superClass));
		}
		
		return array_unique($result);
	}
	
	private function isA($individual, $superClass) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		if (!$superClass) throw new Exception('Error: parameter $superClass missing');
		
		$node = $this->getXMLElement($individual);
		if ($node->getName() != 'NamedIndividual')
			return false;
		
		foreach ($node->children('rdf', true) as $child) {
			if ($child->getName() != 'type') continue;
			if ($child->attributes('rdf', true)->resource == $superClass)
				return true;
		}
		
		return false;
	}
	
	/**
	 * Checks if given class has given superclass.
	 * 
	 * @param $class class uri
	 * @param $superClass superclass uri
	 * 
	 * @return boolean
	 */
	private function hasDirectSuperClass($class, $superClass) {
		if (!$class) throw new Exception('Error: parameter $class missing');
		if (!$superClass) throw new Exception('Error: parameter $superClass missing');
		
		$xml = $this->getXMLElement($class);
		
		if (in_array($superClass, $this->getProperty($xml, 'subClassOf')))
			return true;
		
		return false;
	}
	
	/**
	 * Returns an array containing all parental tags of a given tag.
	 * 
	 * @param $tag tag uri
	 * 
	 * @return array of parental tag local names
	 */
	private function getParentTags($tag) {
		if (!$tag) throw new Exception('Error: parameter $tag missing');
		
		return array_map(
			function($x) { 
				$label = $this->getProperty($this->getXMLElement($x), 'label')[0];
				return $label ?: $this->getLocalName($x);
				
			},
			array_filter(
				$this->getPropertiesAsArray($tag)['subClassOf'] ?: [],
				function($x) { return ($x != self::VOCABULARY); }
			)
		);
	}
	
}
 
?>