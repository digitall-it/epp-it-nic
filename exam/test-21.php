<?php
require_once __DIR__ . '/../vendor/autoload.php';

use digitall\Epp;
use Symfony\Component\Yaml\Yaml;

$cfg = Yaml::parseFile(__DIR__ . '/../exam.yaml');
$dryrun = $cfg['dryrun'];

$epp_a = new Epp('epp-a', $cfg['servers']['exam-a'], $dryrun);
$epp_b = new Epp('epp-b', $cfg['servers']['exam-b'], $dryrun);

echo 'Test 21 - Adding a constraint to a domain name to prevent it from being modified:';

$return = $epp_b->domainUpdate(
    [
        'name' => 'test-1.it',
        'add' => [
            'status' => "clientUpdateProhibited"
        ]
    ]
);
if (!$dryrun && $return['status']['code'] != 1000) die('FAILED');
echo "OK-";

$return = $epp_b->domainGetInfo('test-1.it');
if (!$dryrun && $return['status']['code'] != 1000) die('FAILED');
echo "OK\n";

unset($epp_a);
unset($epp_b);