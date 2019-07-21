<?php
require_once __DIR__ . '/../vendor/autoload.php';

use digitall\Epp;
use Symfony\Component\Yaml\Yaml;

$cfg = Yaml::parseFile(__DIR__ . '/../exam.yaml');

$epp_a = new Epp("epp-a", $cfg["servers"]["exam-a"]);
$epp_b = new Epp("epp-b", $cfg["servers"]["exam-b"]);

echo 'Test 13 - Updating the list of nameservers associated with a domain name:';

$return = $epp_a->domainUpdate(
    [
        'name' => 'test.it',
        'rem' => [
            'ns' => [
                'ns2' => 'ns2.test.it'
            ]
        ]
    ]
);

if ($return['status']['code'] != 1000) die('FAILED');

echo "OK\n";

unset($epp_a);
unset($epp_b);