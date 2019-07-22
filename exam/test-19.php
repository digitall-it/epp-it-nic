<?php
require_once __DIR__ . '/../vendor/autoload.php';

use digitall\Epp;
use Symfony\Component\Yaml\Yaml;

$cfg = Yaml::parseFile(__DIR__ . '/../exam.yaml');
$dryrun = $cfg['dryrun'];

$epp_a = new Epp('epp-a', $cfg['servers']['exam-a'], $dryrun);
$epp_b = new Epp('epp-b', $cfg['servers']['exam-b'], $dryrun);

echo 'Test 19 - Request to change the Registrant contextual to a change of the Registrar for a domain name:';

$contact = $cfg["samplecontacts"]['IL10'];
$contact['handle'] = 'HH10';

$return = $epp_b->contactCreate($contact);
if (!$dryrun && $return['status']['code'] != 1000) die('FAILED');
echo 'OK-';

$return = $epp_b->domainTransfer(
    [
        'name' => 'test-1.it',
        'authInfo' => 'WWWtest-1',
    ],
    'request',
    [
        'newRegistrant' => 'HH10',
        'newAuthInfo' => 'HAC6-007'
    ]
);
if (!$dryrun && $return['status']['code'] != 1001) die('FAILED');

echo "OK\n";

unset($epp_a);
unset($epp_b);