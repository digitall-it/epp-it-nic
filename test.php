<?php
require_once __DIR__ . '/vendor/autoload.php';

use digitall\Epp;
use Symfony\Component\Yaml\Yaml;

$cfg = Yaml::parseFile(__DIR__ . '/config.yaml');

$epp1 = new Epp("epp1");
$epp1->setCredentials($cfg["servers"]["test1"]);

try {
    $epp1->hello();
} catch (Exception $e) {
    die('Cannot hello:' . $e->getMessage());
}