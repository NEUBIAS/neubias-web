<?php

/**
 * @file
 * Contains \Drupal\node_importer\Controller\Form.
 */

namespace Drupal\node_importer\Controller;

use Exception;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\Core\Url;
use Drupal\Core\Link;

use Drupal\node_importer\Importer\VocabularyImporter;
use Drupal\node_importer\Importer\NodeImporter;
use Drupal\node_importer\FileHandler\FileHandlerFactory;


/**
 * Main Class which is instantiated by callung "/node_importer"
 * 
 * @author Christoph Beger
 */
class Form extends FormBase {
    
    public function getFormId() {
        return 'node_importer_form';
    }
  
    public function buildForm(array $form, FormStateInterface $form_state) {
        $form['content'] = $this->content();
        
        $form['file'] = [
            '#type'   => 'managed_file',
            '#title'  => $this->t('File'),
            '#upload_validators' => [
		        'file_validate_extensions' => [ 'json owl' ],
	        ],
            '#required' => true,
        ];
        
        $form['import_vocabularies'] = [
            '#type'  => 'checkbox',
            '#title' => $this->t('Import Vocabularies'),
        ];
        
        $form['import_nodes'] = [
            '#type'  => 'checkbox',
            '#title' => $this->t('Import Nodes'),
        ];
        
        $form['import_class_nodes'] = [
            '#type'  => 'checkbox',
            '#title' => $this->t('Import classes under "Node" as nodes'),
        ];
        
        $form['import_only_leaf_class_nodes'] = [
            '#type'  => 'checkbox',
            '#title' => $this->t('Only import leaf classes under "Node" as nodes'),
        ];
        
        $form['overwrite'] = [
            '#type'  => 'checkbox',
            '#title' => $this->t('Overwrite'),
        ];

        $form['submit'] = [
            '#type'  => 'submit',
            '#value' => $this->t('Submit')
        ];

        return $form;
    }
    
    public function content() {
        $formLink     = Link::createFromRoute('Form', 'node_importer');
        $progressLink = Link::createFromRoute('Progress', 'node_importer.progress');
        
        return [
            '#type' => 'markup',
            '#markup'
                =>'<nav class="tabs" role="navigation" aria-label="Tabs">'
                . '  <h2 class="visually-hidden">Primary tabs</h2>'
                . '  <ul class="tabs primary tabs--primary nav nav-tabs">'
                . '    <li class="is-active active">'. $formLink->toString(). '<span class="visually-hidden">(active tab)</span></li>'
                . '    <li>'. $progressLink->toString(). '</li>'
                . '  </ul>'
                . '</nav>'
        ];
    }
    
    public function validateForm(array &$form, FormStateInterface $form_state) {
        if (!$form_state->getValue('import_nodes')
            && !$form_state->getValue('import_vocabularies')
        ) {
            $form_state->setErrorByName(
                'import_nodes',
                $this->t('Nothing to import. Please select at least nodes or vocabularies for import.')
            );
        }
        
        if ($form_state->getValue('import_only_leaf_class_nodes')
            && !$form_state->getValue('import_class_nodes')
        ) {
            $form_state->setErrorByName(
                'import_only_leaf_class_nodes',
                $this->t('Only leaf class node import is not allowed, when class node import is not selected.')
            );
        }
    }

    public function submitForm(array &$form, FormStateInterface $form_state) {
        $fid                    = $form_state->getValue('file')[0];
        $importVocabularies     = $form_state->getValue('import_vocabularies');
        $importNodes            = $form_state->getValue('import_nodes');
        $classesAsNodes         = $form_state->getValue('import_class_nodes');
        $onlyLeafClassesAsNodes = $form_state->getValue('import_only_leaf_class_nodes');
        $overwrite              = $form_state->getValue('overwrite');
        $userId                 = \Drupal::currentUser()->id();
        
        $file       = File::load($fid);
        $filePath   = \Drupal::service('file_system')->realpath($file->getFileUri());
        $drupalPath = getcwd();
        $newFile    = $drupalPath. '/sites/default/files/'. $file->getFilename();
        
        copy($filePath, $newFile);
        
        $cmd 
            = "php -q modules/node_importer/src/Script/import.php $drupalPath "
            . "$newFile $userId $importVocabularies $importNodes $classesAsNodes "
            . "$onlyLeafClassesAsNodes $overwrite";
        
        $this->execInBackground($cmd);
        
        drupal_set_message(
			$this->t('Import started! Have a look at /admin/reports/dblog to see the progress.')
		);
    }
	
	private function execInBackground($cmd) { 
        if (substr(php_uname(), 0, 7) == "Windows"){ 
            pclose(popen("start /B ". $cmd, "r"));  
        } 
        else { 
            exec($cmd. " > /dev/null &");   
        }
    }
    
}

?>