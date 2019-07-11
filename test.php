<?php
require_once __DIR__ . '/vendor/autoload.php';

use digitall\Epp;
use Symfony\Component\Yaml\Yaml;

$cfg = Yaml::parseFile(__DIR__ . '/config.yaml');

$epp1 = new Epp("epp1", $cfg["servers"]["test1"]);
$epp1->hello();
$epp1->login();

//$epp2->login();


$epp1->logout();
//$epp2->logout();

$epp1->login($cfg['testpassword']);

$epp2 = new Epp("epp2", $cfg["servers"]["test2"]);
$epp2->hello();
$epp2->login();
$epp2->logout();


$epp1->logout();

$epp1->login($cfg["servers"]["test1"]["password"], $cfg['testpassword']); // restore password
$epp1->logout();

exit(0);