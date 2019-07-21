<?php
require_once __DIR__ . '/../vendor/autoload.php';

use digitall\Epp;
use Symfony\Component\Yaml\Yaml;

$cfg = Yaml::parseFile(__DIR__ . '/../exam.yaml');

$epp_a = new Epp("epp-a", $cfg["servers"]["exam-a"]);
$epp_b = new Epp("epp-b", $cfg["servers"]["exam-b"]);

echo 'Test 4 - Check the availability of contact identifiers to be used during the accreditation test:';

$return = $epp_a->contactsCheck(
    [
        'AA10' => $cfg["samplecontacts"]['AA10'],
        'BB10' => $cfg["samplecontacts"]['BB10'],
        'CC01' => $cfg["samplecontacts"]['CC01'],
        'DD01' => $cfg["samplecontacts"]['DD01'],
        'IL10' => $cfg["samplecontacts"]['IL10']
    ]
);
if ($return['status']['code'] != 1000) die('FAILED');
echo "OK\n";

unset($epp_a);
unset($epp_b);