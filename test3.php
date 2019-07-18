<?php
require_once __DIR__ . '/vendor/autoload.php';

use digitall\Plesk;
use Symfony\Component\Yaml\Yaml;

$cfg = Yaml::parseFile(__DIR__ . '/config.yaml');

$changepassword = false;

function removelogs()
{
    $logpath = __DIR__ . '/digitall/logs/client/';
    $dirh = opendir($logpath);
    while (($file = readdir($dirh)) !== false) {
        if (in_array($file, ['.', '..'])) continue;

        $file_parts = pathinfo($file);
        if ($file_parts['extension'] === 'log') unlink($logpath . $file);
    }
    closedir($dirh);
}

function removetraces()
{
    $logpath = __DIR__ . '/digitall/logs/epp/';
    $dirh = opendir($logpath);
    while (($file = readdir($dirh)) !== false) {
        if (in_array($file, ['.', '..'])) continue;
        $file_parts = pathinfo($file);
        if ($file_parts['extension'] === 'xml') unlink($logpath . $file);
    }
    closedir($dirh);
}

$samplecontacts = $cfg["samplecontacts"];

$handles = [];

foreach ($samplecontacts as $id => $contact) {
    $handle = $cfg['prefix'] . '-' . substr(md5(mt_rand()), 0, 5);
    $samplecontacts[$id]["handle"] = $handle;
}

$sampledomains = $cfg["sampledomains"];

foreach ($sampledomains as $dm_id => $domain) {
    $name = strtolower($cfg['prefix'] . '-' . substr(md5(mt_rand()), 0, 3) . '-' . $domain['name']);

    foreach ($domain['ns'] as $ns_id => $ns) {
        $ns_name = str_replace($domain['name'], $name, $ns['name']);
        $sampledomains[$dm_id]['ns'][$ns_id]['name'] = $ns_name;
    }

    foreach ($domain['contacts'] as $ct_id => $contact) {
        $sampledomains[$dm_id]['contacts'][$ct_id] = $samplecontacts[$contact]['handle'];
    }
    $sampledomains[$dm_id]["name"] = $name;
}

// $this->_protocol://$this->_host:$this->_port/enterprise/control/agent.php"

removelogs();
removetraces();

try {
    $plesk = new Plesk($cfg['provisioner']['plesk']);
} catch (Exception $e) {
    die('Cannot instantiate Plesk client:' . $e->getMessage());
};

$testdomain_primary =
    [
        'id' => (int)$plesk->getDomain($cfg['provisioner']['plesk']['domain_primary'])->id,
        'name' => $cfg['provisioner']['plesk']['domain_primary']
    ];
$testdomain_secondary =
    [
        'id' => (int)$plesk->getDomain($cfg['provisioner']['plesk']['domain_secondary'])->id,
        'name' => $cfg['provisioner']['plesk']['domain_secondary']
    ];
$plesk->createAlias('test12345a.it', $testdomain_primary);

$dns_record_id = $plesk->addDNSRecord($testdomain_primary, '', 'NS', 'dns8.digitall.it');
//var_dump($dns_record_id);

$plesk->createAlias('test12345c.it', $testdomain_primary);
$plesk->createAlias('test12345d.it', $testdomain_secondary);

//$y=$plesk->delDNSRecord($dns_record_id);
//var_dump($y);

//$plesk->createAlias('test12345.it', $testdomain);
//$plesk->deleteAlias('test12345.it');
//var_dump($sampledomains);

exit(0);