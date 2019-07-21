<?php
require_once __DIR__ . '/../vendor/autoload.php';

use digitall\Epp;
use Symfony\Component\Yaml\Yaml;

$cfg = Yaml::parseFile(__DIR__ . '/../exam.yaml');

$epp_a = new Epp("epp-a", $cfg["servers"]["exam-a"]);
$epp_b = new Epp("epp-b", $cfg["servers"]["exam-b"]);

echo 'Test 1 - Handshake:';

$return = $return = $epp_a->hello();
if ($return !== 'OK') die('FAILED');

echo "OK\n";

unset($epp_a);
unset($epp_b);