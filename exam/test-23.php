<?php
require_once __DIR__ . '/../vendor/autoload.php';

use digitall\Epp;
use Symfony\Component\Yaml\Yaml;

$cfg = Yaml::parseFile(__DIR__ . '/../exam.yaml');
$dryrun = $cfg['dryrun'];

$epp_a = new Epp('epp-a', $cfg['servers']['exam-a'], $dryrun);
$epp_b = new Epp('epp-b', $cfg['servers']['exam-b'], $dryrun);

echo 'Test 23 - Restoring a deleted domain name:';

$return = $epp_b->domainUpdate(
    [
        'name' => 'test-1.it',
        'restore' => 'request'
    ]
);

if (!$dryrun && $return['status']['code'] != 2304) die('FAILED');
echo "OK-";

$return = $epp_b->domainUpdate(
    [
        'name' => 'test-1.it',
        'rem' => [
            'status' => "clientUpdateProhibited"
        ]
    ]
);
if (!$dryrun && $return['status']['code'] != 1000) die('FAILED');
echo "OK-";

$return = $epp_b->domainUpdate(
    [
        'name' => 'test-1.it',
        'restore' => 'request'
    ]
);
if (!$dryrun && $return['status']['code'] != 1000) die('FAILED');
echo "OK\n";

unset($epp_a);
unset($epp_b);