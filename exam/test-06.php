<?php
require_once __DIR__ . '/../vendor/autoload.php';

use digitall\Epp;
use Symfony\Component\Yaml\Yaml;

$cfg = Yaml::parseFile(__DIR__ . '/../exam.yaml');
$dryrun = $cfg['dryrun'];

$epp_a = new Epp('epp-a', $cfg['servers']['exam-a'], $dryrun);
$epp_b = new Epp('epp-b', $cfg['servers']['exam-b'], $dryrun);

echo 'Test 6 - Creation of two tech / admin contacts:';

$return = $epp_a->contactCreate($cfg["samplecontacts"]['CC01']);
if (!$dryrun && $return['status']['code'] != 1000) die('FAILED');
echo "OK-";

$return = $epp_a->contactCreate($cfg["samplecontacts"]['DD01']);
if (!$dryrun && $return['status']['code'] != 1000) die('FAILED');
echo "OK\n";

unset($epp_a);
unset($epp_b);