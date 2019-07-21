<?php
require_once __DIR__ . '/../vendor/autoload.php';

use digitall\Epp;
use Symfony\Component\Yaml\Yaml;

$cfg = Yaml::parseFile(__DIR__ . '/../exam.yaml');

$epp_a = new Epp("epp-a", $cfg["servers"]["exam-a"]);
$epp_b = new Epp("epp-b", $cfg["servers"]["exam-b"]);

$return = $epp_b->domainDelete('test-1.it');
if ($return['status']['code'] != 1000) die('FAILED');
echo "OK-";

$return = $epp_b->domainGetInfo('test-1.it');
if ($return['status']['code'] != 1000) die('FAILED');
echo "OK\n";

unset($epp_a);
unset($epp_b);