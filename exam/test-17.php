<?php
require_once __DIR__ . '/../vendor/autoload.php';

use digitall\Epp;
use Symfony\Component\Yaml\Yaml;

$cfg = Yaml::parseFile(__DIR__ . '/../exam.yaml');

$epp_a = new Epp("epp-a", $cfg["servers"]["exam-a"]);
$epp_b = new Epp("epp-b", $cfg["servers"]["exam-b"]);

$domain_test = $cfg["sampledomains"]['test'];
$domain_test['authInfo'] = 'newwwtest-it';

echo 'Test 17 - Approval of the request to change the Registrar and elimination of the request message from the polling queue:';

$result = $epp_a->pollRequest();
if ($result['status']['code'] !== 1301) die('FAILED');
$msg_id = $result['id'];
echo "OK-";

$return = $epp_a->domainTransfer($domain_test, 'approve');
if ($return['status']['code'] != 1000) die('FAILED');
echo "OK-";

$result = $epp_a->pollAck($msg_id);
if ($return['status']['code'] != 1000) die('FAILED');
echo "OK\n";

unset($epp_a);
unset($epp_b);