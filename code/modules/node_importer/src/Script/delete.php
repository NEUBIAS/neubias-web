<?php

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

if (sizeof($argv) < 3)
    die("Usage: delete.php [drupal path] [content type] [userId?]\n");

$drupalPath  = $argv[1];
$contentType = $argv[2];
$userId      = $argv[3];

if (!$drupalPath) die("Error: script parameter 'drupalPath' missing.\n");
if (!$contentType) die("Error: script parameter 'contentType' missing.\n");


$autoloader = require_once $drupalPath. '/autoload.php';
$request = Request::createFromGlobals();
$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$kernel->boot();
$kernel->prepareLegacyRequest($request);

print "Deleting all nodes of type '$contentType'...\n";
$result = \Drupal::entityQuery('node')
    ->addMetaData('account', user_load($userId))
    ->condition('type', $contentType)
    ->execute();

print 'Found '. sizeof($result). " nodes.\n";
entity_delete_multiple('node', $result);

?>
