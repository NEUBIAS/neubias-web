<?php

/**
 * @file
 * Contains \Drupal\node_importer\FileHandler\OWLFileHandler.
 */

namespace Drupal\node_importer\FileHandler;

use Drupal\file\Entity\File;
use \Exception;


/**
 * FileHandler which parses OWL files.
 * 
 * @author Christoph Beger
 */
class OWLFileHandler extends AbstractFileHandler {
	
	/** Declaration of DUO default classes/properties **/ 
	const VOCABULARY       = 'http://www.lha.org/duo#Vocabulary';
	const NODE             = 'http://www.lha.org/duo#Node';
	const IMG              = 'http://www.lha.org/duo#Img';
	const ENTITY           = 'http://www.lha.org/duo#Entity';
	const DOC              = 'http://www.lha.org/duo#Doc';
	const BINARY           = 'http://www.lha.org/duo#Binary';
	const FILE             = 'http://www.lha.org/duo#File';
	const TITLE            = 'http://www.lha.org/duo#title';
	const ALIAS            = 'http://www.lha.org/duo#alias';
	const CONTENT          = 'http://www.lha.org/duo#content';
	const SUMMARY          = 'http://www.lha.org/duo#summary';
	const REF_NUM          = 'http://www.lha.org/duo#ref_num';
	const REF_TYPE         = 'http://www.lha.org/duo#ref_type';
	const NODE_REF         = 'http://www.lha.org/duo#node_ref';
	const FILE_REF         = 'http://www.lha.org/duo#file_ref';
	const IMAGE_REF        = 'http://www.lha.org/duo#image_ref';
	const TERM_REF         = 'http://www.lha.org/duo#taxonomy_term_ref';
	const DOC_REF          = 'http://www.lha.org/duo#doc_ref';
	const ANNOTATION_FIELD = 'http://www.lha.org/duo#field';
	const DATATYPE_FIELD   = 'http://www.lha.org/duo#literal_field';
	const OBJECT_FIELD     = 'http://www.lha.org/duo#reference_field';
	const URI              = 'http://www.lha.org/duo#uri';
	const ALT              = 'http://www.lha.org/duo#alt';
	const NAMED_INDIVIDUAL = 'http://www.w3.org/2002/07/owl#NamedIndividual';
	
	private $classesAsNodes         = false;
	private $onlyLeafClassesAsNodes = false;
	
	public function __construct($params) {
		$this->logNotice('OWLFileHandler::__construct(): '. memory_get_usage());
		parent::__construct($params);
		
		$this->graph = new \EasyRdf_Graph();
		$this->graph->parse(file_get_contents($this->filePath));
		$this->logNotice('graph parsed: '. memory_get_usage());
		
		if ($params['classesAsNodes']) $this->classesAsNodes = true;
		if ($params['onlyLeafClassesAsNodes']) $this->onlyLeafClassesAsNodes = true;
	}
	
	public function setData() {
		$this->setVocabularyData();
		$this->setNodeData();
	}
	
	public function setVocabularyData() {
		foreach ($this->getVocabularyClasses() as $class) {
			$vid = $class->localName();
			$this->logNotice('Handling vocabulary: '. $vid);
			$this->vocabularyImporter->createVocabulary($vid, $vid);
			
			$this->logNotice('Collecting terms...');
			$tags = $this->findAllSubClassesOf($class->getUri());
			$this->logNotice('Found '. sizeof($tags). ' terms.');
			
			$this->logNotice('Inserting terms into Drupal DB...');
			foreach ($tags as $tag) {
				$this->vocabularyImporter->createTag($vid, $tag->label() ?: $tag->localName());
			}
			
			$this->logNotice('Adding child parent linkages to terms...');
			foreach ($tags as $tag) {
				$this->vocabularyImporter->setTagParents(
					$vid,
					[[
						'name'    => $tag->label() ?: $tag->localName(),
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
		$this->logNotice('Found '. sizeof($individuals). ' Nodes.');
		
		$this->logNotice('Inserting nodes into Drupal DB...');
		foreach ($individuals as $individual) {
			$node = [
				'title'  => $this->getNodeTitle($individual),
				'type'   => $this->getBundle($individual),
				'alias'  => $this->getProperty($individual, self::ALIAS),
				'fields' => $this->createNodeFields($individual),
				'uuid'   => $individual->getUri()
			];
			
			$this->nodeImporter->createNode($node);
		}
		
		unset($individuals);
		
		$this->logNotice('Adding node references...');
		$this->nodeImporter->insertNodeReferences();
	}
	
	/**
	 * Returns an array with all classes under "Vocabulary".
	 * 
	 * @return array of classes
	 */
	private function getVocabularyClasses() {
		return $this->getDirectSubClassesOf($this->graph->resource(self::VOCABULARY)); 
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
	
		foreach (
			$this->getDirectSubClassesOf(
				$this->graph->resource(self::NODE)
			) as $bundleResource
		) {
			if (
				$this->isATransitive($node, $bundleResource)
				|| $this->hasTransitiveSuperClass($node, $bundleResource->getUri())
			)
				return strtolower(preg_replace(
					'/[^A-Za-z0-9]/', '_', $bundleResource->localName()
				));
		}
		
		return null;
	}
	
	/**
	 * Returns an array of all drupal-fields for a given individual.
	 * 
	 * @param $individual individual resource
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
					'value'   => $this->removeRdfsType(
						array_key_exists(self::CONTENT, $properties) 
						? $properties[self::CONTENT] : null
					),
					'summary' => $this->removeRdfsType(
						array_key_exists(self::SUMMARY, $properties) 
						? $properties[self::SUMMARY] : null
					),
					'format'  => 'full_html'
				]
			]
		];
		
		if ($this->classesAsNodes && !$this->onlyLeafClassesAsNodes) {
			$fields[] = $this->createFieldParent($individual);
			
			if ($individual->type() == 'owl:Class')
				$fields[] = $this->createFieldChild($individual);
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
			if (!$individual->hasProperty($property))
				continue;

			if ($field = $this->createNodeField($individual, $property))
				$fields[] = $field;
		}
		
		return $fields;
	}
	
	private function createFieldParent($resource) {
		if (!$resource) throw new Exception('Error: parameter $resource missing.');
		
		if ($resource->type() == 'owl:Class') {
			return [
				'field_name' => 'field_parent',
				'value'      => array_map(
					function($x) { return $x->getUri(); },
					array_filter(
						$this->getDirectSuperClassesOf($resource),
						function($x) {
							return $this->hasTransitiveSuperClass($x, self::NODE)
								&& !$this->hasDirectSuperClass($x, self::NODE);
						}
					)
				),
				'references' => 'node'
			];
		} else {
			return [
				'field_name' => 'field_parent',
				'value'      => array_map(
					function($x) { return $x->getUri(); },
					array_filter(
						$resource->allResources('rdf:type'),
						function($x) {
							return $this->hasTransitiveSuperClass($x, self::NODE)
								&& !$this->hasDirectSuperClass($x, self::NODE);
						}
					)
				),
				'references' => 'node'
			];
		}
	}
	
	private function createFieldChild($class) {
		if (!$class) throw new Exception('Error: parameter $class missing.');
		
		return [
			'field_name' => 'field_child',
			'value'      => array_map(
				function($x) { return $x->getUri(); },
				array_merge($this->getDirectSubClassesOf($class), $this->getInstancesOf($class))
			),
			'references' => 'node'
		];
	}
	
	private function getInstancesOf($class) {
		if (!$class) throw new Exception('Error: parameter $class missing');
		
		return $this->graph->allOfType($class->getUri());
	}
	
	/**
	 * Returns an array of tag tuple [vid, name].
	 * 
	 * @param $individual individual resource
	 * 
	 * @return array containing all tag tuples
	 */
	private function createFieldTags($individual) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		
		$fieldTags = [];
		
		$resources = array_merge(
			$individual->allResources('rdf:type'),
			$this->getDirectSuperClassesOf($individual)
		);
		
		foreach ($resources as $tag) {
			if (!$this->hasTransitiveSuperClass($tag, self::VOCABULARY))
				continue;
			
			$vocabulary = $this->getVocabularyForTag($tag);
			
			$fieldTags[] = [
				'vid'  => $vocabulary->localName(),
				'name' => $tag->label() ?: $tag->localName()
			];
		}
		
		return $fieldTags ?: [];
	}
	
	/**
	 * Returns true if $subClass is a transitive subclass of $class.
	 * 
	 * @param $class class resource
	 * @param $subClass superclass uri to search for
	 * 
	 * @return boolean
	 */
	private function hasTransitiveSuperClass($class, $superClass) {
		if (!$class) throw new Exception('Error: parameter $class missing.');
		if (!$superClass) throw new Exception('Error: parameter $superClass missing.');
		
		foreach ($this->findAllSuperClassesOf($class) as $curSuperClass) {
			if ($curSuperClass->getUri() == $superClass)
				return true;
		}
		return false;
	}
	
	/**
	 * Returns an array for a single field of a given node/individual.
	 * 
	 * @param $individual individual as resource
	 * @param $property IRI representation of the property
	 * 
	 * @return array with all properties of the field
	 */
	private function createNodeField($individual, $property) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		if (!$property) throw new Exception('Error: parameter $property missing');
		
		if ($literals = $this->getSortedLiterals($individual, $property)) { // includes DataProperties
			$field = [
				'value' => array_map(
					function ($x) { return $this->literalValueToString($x); },
					$literals
				),
				'field_name' => $property->localName()
			];
		} elseif ($individual->allResources($property)) { // includes ObjectProperties
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
		$title = $this->getProperty($entity, self::TITLE);
		
		return $title ?: $entity->localName(); # . ' ('. $entity->localName(). ')'
	}
	
	/**
	 * Returns an array for a field with values for each referenced resource.
	 * 
	 * @param $individual individual resource
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
			'field_name' => $property->localName()
		];
		
		foreach ($resources as $target) {
			$targetProperties = $this->getPropertiesAsArray($target);
			$value;
			
			if ($this->isATransitive($target, self::NODE)) {
				$value = $target->getUri();
				$field['references'] = 'node';
			//} elseif ($this->isATransitive($target, self::IMG)) { // @todo
			// 	$value = [
			// 		'alt'   => $targetProperties[self::ALT],
			// 		'title' => $targetProperties[self::TITLE],
			// 		'uri'   => $targetProperties[self::URI]
			// 	];
			// 	$field['entity'] = 'file';
			} elseif (
				$this->isATransitive($target, self::FILE)
				|| $this->hasTransitiveSuperClass($target, self::FILE)
			) {
				$value = [ 'uri' => $this->getFilePath($target) ];
				$field['references'] = 'file';
			// } elseif ($this->isATransitive($target, self::DOC)) { // @todo
			// 	$refType = self::DOC_REF;
			} elseif ($this->getVocabularyForTag($target) != null) {
				$value = [
					'vid'  => $this->getVocabularyForTag($target)->localName(),
					'name' => $target->label() ?: $target->localName()
				];
				$field['references'] = 'taxonomy_term';
			} elseif ($this->isATransitive($target, self::ENTITY)) {
				$axiom = $this->getAxiomWithTargetForIndividual($individual, $property, $target);
				$axiomProperties = $this->getPropertiesAsArray($axiom);
				
				if (
					array_key_exists(self::ANNOTATION_FIELD, $axiomProperties)
					&& $targetField = $axiomProperties[self::ANNOTATION_FIELD]
				) {
					$value = $this->removeRdfsType($targetProperties[$targetField]);
				} else {
					$this->logWarning(
						'Entity \''. $target->localName()
						. '\' by \''. $individual->localName()
						. '\' referenced but no field given. '
						. '(\''. $property->localName(). '\')'
					);
					continue;
				}
			} else {
				$this->logWarning(
					"Nonexistent entity '". $target->localName()
					. "' referenced by '". $individual->localName()
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
	 * @param $entity class or individual
	 * 
	 * @return {string} path
	 */
	private function getFilePath($entity) {
		if (!$entity) throw new Exception('Error: parameter $entity missing.');
		$uri = $this->getProperty($entity, self::URI);
		
		$types = $entity->typesAsResources();
		$classes = $this->getDirectSuperClassesOf($entity);
		
		foreach ((!empty($classes) ? $classes : $types) as $class) {
			if (!$this->hasTransitiveSuperClass($class, self::FILE))
				continue;
			
			$result = $class->label() ?: $class->localName();
			foreach ($this->findAllSuperClassesOf($class) as $superclass) {
				if (!$this->hasTransitiveSuperClass($superclass, self::FILE))
					continue;
				$result = ($superclass->label() ?: $superclass->localName()). '/'. $result;
			}
			
			return $result. '/'. $uri;
		}
		
		return $uri;
	}
	
	/**
	 * Returns an array containing all annotated targets of the axioms and all
	 * resources which have no assingned axiom. Axioms are prioriced.
	 * 
	 * @param $individual individual
	 * @param $property property
	 * 
	 * @return array of targeted resources
	 */
	private function getSortedResources($individual, $property) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		if (!$property) throw new Exception('Error: parameter $property missing');
		
		$resources = $individual->allResources($property);
		if (empty($resources)) return null;
		$axioms = $this->getAxiomsForIndividual($individual, $property);
		
		$result = [];
		foreach ($axioms as $axiom) {
			$result[] = $axiom->get('owl:annotatedTarget');
		}
		
		foreach ($resources as $resource) {
			if (!in_array($resource, $result))
				$result[] = $resource;
		}
		
		return $result;
	}
	
	/**
	 * Returns an array of literals for a given individual and property.
	 * 
	 * @param $individual individual
	 * @param $property property
	 * 
	 * @return array of literals sorted by ref_num if it exists
	 */
	private function getSortedLiterals($individual, $property) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		if (!$property) throw new Exception('Error: parameter $property missing');
		
		$literals = $individual->allLiterals($property);
		
		if (empty($literals)) return null;
		$axioms = $this->getAxiomsForIndividual($individual, $property);
		
		$result = [];
		foreach ($axioms as $axiom) {
			$result[] = $axiom->getLiteral('owl:annotatedTarget');
		}
		
		foreach ($literals as $literal) {
			if (!in_array($literal, $result))
				$result[] = $literal;
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
	 * @return resource axiom
	 */
	private function getAxiomWithTargetForIndividual($individual, $property, $target) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		if (!$property) throw new Exception('Error: parameter $property missing');
		if (!$target) throw new Exception('Error: parameter $target missing');
		
		$axioms = $this->getAxiomsForIndividual($individual, $property);
		foreach($axioms as $axiom) {
			if ($axiom->get('owl:annotatedTarget')->getUri() == $target->getUri())
				return $axiom;
		}
	}
	
	/**
	 * Returns value of the resource property
	 * 
	 * @param $resource resource
	 * @param $uri properties uri
	 * 
	 * @return
	 */
	private function getProperty($resource, $uri) {
		if (!$resource) throw new Exception('Error: parameter $resource missing');
		if (!$uri) throw new Exception('Error: parameter $uri missing');
		
		$properties = $this->getPropertiesAsArray($resource);
		if (!array_key_exists($uri, $properties))
			return null;
		
		return $this->removeRdfsType($properties[$uri]);
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
	 * Returns a string with <a> tag for individual and ref_num.
	 * 
	 * @param $individual individual resource
	 * @param $num ref_num property
	 * 
	 * @return string
	 */
	private function createUrlForAxiom($individual, $num) {
		throw new Exception('Deprecated!');
		if (!$num) throw new Exception('Error: parameter $num missing');
		
		$axioms = $this->graph->resourcesMatching('owl:annotatedSource', $individual);
		foreach ($axioms as $axiom) {
			$properties = $this->getPropertiesAsArray($axiom);
			
			if (!$properties[self::REF_NUM] == $num. '"^^xsd:integer')
				continue;
				
			$target = $this->graph->resource($properties['owl:annotatedTarget']);
			$uri = $this->getProperty($target, self::URI);
				
			if (!$uri) {
				$alias = $this->getProperty($target, self::ALIAS);
				
				if (!$alias) throw new Exception('Error: URLs can only reference entities with uri or alias. ('. $target->localName(). ')');
				$uri = base_path(). $alias;
			}
				
			return '<a href="'. base_path(). $alias. '">'. $target->localName(). '</a>';
		}
		
		return null;
	}
	
	/**
	 * Returns array of axioms for a given individual and property,
	 * sorted by ref_num if exists.
	 * 
	 * @param $individual individual uri
	 * @param $property property uri
	 * 
	 * @return array
	 */
	private function getAxiomsForIndividual($individual, $property) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		if (!$property) throw new Exception('Error: parameter $property missing');
		
		$result = [];
		$prevIndex = 0;
		
		$axioms = $this->graph->resourcesMatching('owl:annotatedSource', $individual);
		foreach ($axioms as $axiom) {
			if (!$axiom->hasProperty('owl:annotatedProperty', $property))
		 		continue;
		 		
			$curIndex = preg_replace('/"\^\^xsd:integer/', '', $this->getProperty($axiom, self::REF_NUM));
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
	 * @param $tag tag resource
	 * 
	 * @return string vid
	 */
	private function getVocabularyForTag($tag) {
		if (!$tag) throw new Exception('Error: parameter $tag missing');
		
		foreach ($this->getVocabularyClasses() as $vocabulary) {
			foreach ($this->findAllSubClassesOf($vocabulary->getUri()) as $subClass) {
				if ($subClass->getUri() == $tag->getUri())
					return $vocabulary;
			}
		}
		
		return null;
		// throw new Exception("Error: tag: '$tag->localName()' could not be found.");
	}
	
	/**
	 * Returns an array with all properties of an individual.
	 * 
	 * @param $individual individual resource
	 * 
	 * @return array
	 */
	private function getPropertiesAsArray($individual) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		
		$properties = explode(' -> ', $this->graph->dumpResource($individual, 'text'));
		$array = [];
			
		for ( $i = 1; $i < sizeof($properties); $i++) {
			if ($i % 2 == 0) continue;
			$array[$properties[$i]] = trim(preg_replace('/^\s*"|"\s*$/', '', $properties[$i + 1]));
		}
		
		return $array;
	}
	
	/**
	 * Returns all individuals under class Node and classes under node,
	 * depending on classesAsNodes and onlyLeafClasses.
	 * 
	 * @return array resources
	 */
	private function getIndividuals() {
		$individuals = [];
		
		if ($this->classesAsNodes) {
			if ($this->onlyLeafClassesAsNodes) {
				foreach ($this->getDirectSubClassesOf(self::NODE) as $nodeTypeClass) {
					$individuals = array_merge(
						$individuals,
						$this->findAllLeafClassesOf($nodeTypeClass->getUri())
					);
				}
			} else {
				foreach ($this->getDirectSubClassesOf(self::NODE) as $nodeTypeClass) {
					$individuals = array_merge(
						$individuals,
						$this->findAllSubClassesOf($nodeTypeClass->getUri())
					);
				}
			}
		}
		
		foreach ($this->graph->allOfType('owl:NamedIndividual') as $individual) {
			if (
				!$this->isATransitive($individual, self::NODE)
				|| $individual->isA(self::NODE) // individuals directly under Node are ignored
			) continue;
			$individuals[] = $individual;
		}
		
		return $individuals;
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
	 * @return array subclasses as resources
	 */
	private function getDirectSubClassesOf($class) {
		return $this->graph->resourcesMatching(
			'rdfs:subClassOf', 
			$this->graph->resource($class)
		);
	}
	
	private function getDirectSuperClassesOf($class) {
		return $class->allResources('rdfs:subClassOf');
	}
	
	private function getClasses() {
		return $this->graph->allOfType('owl:Class');
	}
	
	private function getAnnotationProperties() {
		$annotationProperties = $this->graph->allOfType('owl:AnnotationProperty');
		
		$properties = [];
		foreach ($annotationProperties as $annotationProperty) {
			$superProperties = $this->getProperty($annotationProperty, 'rdfs:subPropertyOf');
			
			if (strpos($superProperties, self::ANNOTATION_FIELD) !== false)
				$properties[] = $annotationProperty;
		}
		
		return $properties;
	}
	
	private function getDatatypeProperties() {
		$datatypeProperties = $this->graph->allOfType('owl:DatatypeProperty');
		
		$properties = [];
		foreach ($datatypeProperties as $datatypeProperty) {
			$superProperties = $this->getProperty($datatypeProperty, 'rdfs:subPropertyOf');
			
			if (strpos($superProperties, self::DATATYPE_FIELD) !== false)
				$properties[] = $datatypeProperty;
		}
		
		return $properties;
	}
	
	private function getObjectProperties() {
		$objectProperties = $this->graph->allOfType('owl:ObjectProperty');
		
		$properties = [];
		foreach ($objectProperties as $objectProperty) {
			$superProperties = $this->getProperty($objectProperty, 'rdfs:subPropertyOf');
				
			if (strpos($superProperties, self::OBJECT_FIELD) !== false)
				$properties[] = $objectProperty;
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
			$result[] = $subClass;
			$result = array_merge($result, $this->findAllSubClassesOf($subClass->getUri()));
		}
		
		return array_unique($result);
	}
	
	/**
	 * Checks if the given individual is a transitive instantiation of given class.
	 * 
	 * @param $individual individual resource
	 * @param $superClass superClass uri
	 * 
	 * @return boolean
	 */
	private function isATransitive($individual, $superClass) {
		if (!$individual) throw new Exception('Error: parameter $individual missing');
		if (!$superClass) throw new Exception('Error: parameter $superClass missing');
		
		if ($individual->isA($this->graph->resource($superClass)->getUri()))
			return true;
		
		foreach ($individual->allResources('rdf:type') as $class) {
			if ($class->getUri() == $superClass) {
				return true;
			} else {
				foreach ($this->findAllSuperClassesOf($class) as $curSuperClass) {
					if ($curSuperClass->getUri() == $superClass)
						return true;
				}
			}
		}
		
		return false;
	}
	
	private function findAllSuperClassesOf($class) {
		if (!$class) throw new Exception('Error: parameter $class missing.');
		
		$result = [];
		foreach ($this->getDirectSuperClassesOf($class) as $superClass) {
			$result[] = $superClass;
			$result = array_merge($result, $this->findAllSuperClassesOf($superClass));
		}
		return array_unique($result);
	}
	
	/**
	 * Checks if given class has given superclass.
	 * 
	 * @param $class class resource
	 * @param $superClass superclass resource
	 * 
	 * @return boolean
	 */
	private function hasDirectSuperClass($class, $superClass) {
		if (!$class) throw new Exception('Error: parameter $class missing');
		if (!$superClass) throw new Exception('Error: parameter $superClass missing');
		
		if (in_array($superClass, $this->getDirectSuperClassesOf($class)))
			return true;
		
		return false;
	}
	
	/**
	 * Returns an array containing all parental tags of a given tag.
	 * 
	 * @param $tag tag resource
	 * 
	 * @return array of parental tag local names
	 */
	private function getParentTags($tag) {
		if (!$tag) throw new Exception('Error: parameter $tag missing');
		
		return array_map(
			function($x) { return $x->label() ?: $x->localName(); },
			array_filter(
				$this->getDirectSuperClassesOf($tag),
				function($x) { return !$this->hasDirectSuperClass($x, self::VOCABULARY); }
			)
		);
	}
	
}
 
?>