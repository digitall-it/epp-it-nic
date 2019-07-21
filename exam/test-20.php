<?php
require_once __DIR__ . '/../vendor/autoload.php';

use digitall\Epp;
use Symfony\Component\Yaml\Yaml;

$cfg = Yaml::parseFile(__DIR__ . '/../exam.yaml');

$epp_a = new Epp("epp-a", $cfg["servers"]["exam-a"]);
$epp_b = new Epp("epp-b", $cfg["servers"]["exam-b"]);


echo 'Test 20 - Approval of the request to change the Registrant and the Registrar:';

$result = $epp_a->pollRequest();
if ($result['status']['code'] !== 1301) die('FAILED');
echo "OK-";

$return = $epp_a->domainTransfer($cfg["sampledomains"]['test1'], 'approve');
if ($return['status']['code'] != 1000) die('FAILED');
echo "OK\n";

unset($epp_a);
unset($epp_b);