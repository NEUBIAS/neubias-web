<?php

/**
 * @file
 * Contains \Drupal\node_importer\Timer\Timer.
 */

namespace Drupal\node_importer\Timer;

/**
 * @author Christoph Beger
 */
class Timer {
    
    private $startTime;
    private $lastStop;
    
    public function __construct() {
        $this->startTime = time();
        $this->lastStop = $this->startTime;
    }
    
    public function printDiff($msg = null) {
        $time = time();
        print ($msg ? $msg. ': ' : ''). ($time - $this->lastStop). "\n";
        $this->lastStop = $time;
    }
}

?>