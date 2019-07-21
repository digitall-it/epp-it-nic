<?php
require_once __DIR__ . '/../vendor/autoload.php';

use digitall\Epp;
use Symfony\Component\Yaml\Yaml;

$cfg = Yaml::parseFile(__DIR__ . '/../exam.yaml');

$epp_a = new Epp("epp-a", $cfg["servers"]["exam-a"]);
$epp_b = new Epp("epp-b", $cfg["servers"]["exam-b"]);

echo 'Test 7 - Updating one of the properties of a contact:';

$return = $epp_a->contactUpdate(
    [
        'handle' => 'BB10',
        'chg' => ['fax' => "+39.0503128298"]
    ]
);

if ($return['status']['code'] != 1000) die('FAILED');
echo "OK\n";

unset($epp_a);
unset($epp_b);