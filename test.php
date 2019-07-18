<?php
require_once __DIR__ . '/vendor/autoload.php';

use digitall\Epp;
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

function removequeue()
{
    $logpath = __DIR__ . '/digitall/logs/queue/';
    $dirh = opendir($logpath);
    while (($file = readdir($dirh)) !== false) {
        if (in_array($file, ['.', '..'])) continue;
        $file_parts = pathinfo($file);
        if ($file_parts['extension'] === 'txt') unlink($logpath . $file);
    }
    closedir($dirh);
}

removelogs();
removetraces();
removequeue();


$mode = $cfg['mode'];

try {
    $plesk = new Plesk($cfg['provisioner']['plesk']);
} catch (Exception $e) {
    die('Cannot instantiate client:' . $e->getMessage());
}

/**********************************
 *  Section 1: session operations *
 **********************************/

// Test 1: Handshake

$epp = new Epp("epp1", $cfg["servers"]["test1"]);
$epp->hello();

// Test 2: Authentication (by opening one or more simultaneous sessions)

$epp->login();
//$epp->pollCheck();die();


$epp2 = new Epp("epp2", $cfg["servers"]["test2"]);
$epp2->login();
$epp2->logout();

// Test 3: Change password

if ($mode === 'exam') {
    // skip in test env
    $epp->logout();
    $epp->login($cfg['testpassword']);
}

/***********************************************
 * Section 2: operations for managing contacts *
 ***********************************************/

// Test 4: Check the availability of contact identifiers to be used during the accreditation test

$samplecontacts = $cfg["samplecontacts"];

$handles = [];

foreach ($samplecontacts as $id => $contact) {
    $handle = $cfg['prefix'] . '-' . substr(md5(mt_rand()), 0, 5);
    $samplecontacts[$id]["handle"] = $handle;
}
$epp->contactsCheck($samplecontacts);

// Test 5: Creation of three registrant contacts

$epp->contactCreate($samplecontacts['registrant1']);
$epp->contactCreate($samplecontacts['registrant2']);
$epp->contactCreate($samplecontacts['registrant3']);

// Test 6: Creation of two tech / admin contacts

$epp->contactCreate($samplecontacts['techadmin1']);
$epp->contactCreate($samplecontacts['techadmin2']);

// Test 7: Updating one of the properties of a contact

$epp->contactUpdate(
    [
        'handle' => $samplecontacts['techadmin2']["handle"],
        'chg' => ['voice' => "+39.0246125585"]
    ]
);

// Test 8: Display of contact information

$epp->contactGetInfo($samplecontacts['registrant2']['handle']);

/************************************************************
 * Section 3: operations for the management of domain names *
 ************************************************************/

// Test 9: Verification of the availability of two domain names

$sampledomains = $cfg["sampledomains"];

foreach ($sampledomains as $dm_id => $domain) {
    $name = strtolower($cfg['prefix'] . '-' . substr(md5(mt_rand()), 0, 5) . '-' . $domain['name']);

    foreach ($domain['ns'] as $ns_id => $ns) {
        $ns_name = str_replace($domain['name'], $name, $ns['name']);
        $sampledomains[$dm_id]['ns'][$ns_id]['name'] = $ns_name;
    }

    foreach ($domain['contacts'] as $ct_id => $contact) {
        $sampledomains[$dm_id]['contacts'][$ct_id] = $samplecontacts[$contact]['handle'];
    }
    $sampledomains[$dm_id]["name"] = $name;
    if ($mode === 'test') $sampledomains[$dm_id]['dns_check_status'] = 'pending';
}

$r = $epp->domainsCheck($sampledomains);

// Test 10: Creation of two domain names

if ($mode === 'test') {

    // Provision domain aliases to pass DNS check in test env

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

    $plesk->createAlias($sampledomains['domain1']['name'], $testdomain_primary);
    $plesk->createAlias($sampledomains['domain2']['name'], $testdomain_primary);

    // End of Plesk provisioning code

}

$epp->domainCreate($sampledomains['domain1']);
$epp->domainCreate($sampledomains['domain2']);

if ($mode === 'test') {

    // Queue polling for DNS check results
    sleep(5);
    $epp->pollCheck();

    // Verify DNS check results

    if ($sampledomains['domain1']['dns_check_status'] !== 'ok') die($sampledomains['domain1']['name'] . ' DNS check not passed.');
    if ($sampledomains['domain2']['dns_check_status'] !== 'ok') die($sampledomains['domain2']['name'] . ' DNS check not passed.');

}

// Test 11: Adding a constraint to a domain name to prevent transfer

$epp->domainUpdate(
    [
        'name' => $sampledomains['domain1']['name'],
        'add' => ['status' => "clientTransferProhibited"]
    ]
);

// Test 12: Displaying the information of a domain name

$epp->domainGetInfo($sampledomains['domain1']['name']);

// Test 13: Updating the list of nameservers associated with a domain name

if ($mode === 'test') {

    // Provision domain aliases to pass DNS check

    $plesk->deleteAlias($sampledomains['domain1']['name']);
    $plesk->createAlias($sampledomains['domain1']['name'], $testdomain_secondary);

    // End of Plesk provisioning code

}

$epp->domainUpdate(
    [
        'name' => $sampledomains['domain1']['name'],
        'rem' => [
            'ns' => [
                'ns3' => [
                    'name' => $sampledomains['domain1']['ns_test']['tertiary']['name']
                ]
            ]
        ]
    ]
);

if ($mode === 'test') {

    // Reset status to pending

    $sampledomains['domain1']['dns_check_status'] = 'pending';

    // Queue polling for DNS check results

    $epp->pollCheck();

    // Verify DNS check results

    if ($sampledomains['domain1']['dns_check_status'] !== 'ok') die($sampledomains['domain1']['name'] . ' DNS check not passed.');
    if ($sampledomains['domain2']['dns_check_status'] !== 'ok') die($sampledomains['domain2']['name'] . ' DNS check not passed.');

}


// Test 14: Change of the Registrant of a domain name

/* @todo
 * $epp->domainUpdateRegistrant(
 * [
 * 'domain' => $sampledomains['domain1'],
 * 'registrant' => $samplecontacts['registrant3'],
 * 'blah' => 'blah'
 * ]
 * );
 * /*
 * // Test 15: Richiesta di modifica del Registrar di un nome a dominio
 * // @todo ???
 * // Test 16: Nuova richiesta di modifica del Registrar di un nome a dominio
 * // @todo ???
 * // Test 17: Approvazione della richiesta di modifica del Registrar ed eliminazione del messaggio di richiesta dalla
 * coda di polling
 * // @todo ???
 * // @todo Test 18: Modifica del codice AuthInfo di un nome a dominio
 * /* @todo
 * $epp->domainUpdateAuthInfo(
 * [
 * 'domain' => $sampledomains['domain1'],
 * 'authInfo' => $newAuthInfo,
 * ]
 * );
 * /*
 * // Test 19: Richiesta di modifica del Registrante contestuale ad una modifica del Registrar per un nome a dominio
 * /* @todo
 * $epp->domainUpdateRegistrantAndRegistrar(
 * [
 * 'domain' => $sampledomains['domain1'],
 * 'registrant' => $samplecontacts['registrant3'],
 * 'registrar' => 'blah'
 * ]
 * );
 * /*
 * // Test 20: Approvazione della richiesta di modifica del Registrante e del Registrar
 * // @todo ???
 * // Test 21: Aggiunta di un vincolo a un nome a dominio per impedirne la modifica
 * /* @todo
 * $epp->domainUpdate(
 * [
 * 'domain' => $sampledomains['domain1'],
 * 'blah' => 'blah'
 * ]
 * );
 */
// Test 22: Cancellation of a domain name

//@todo $epp->domainDelete($sampledomains['domain1']['domain']);

// Test 23: Restoring a deleted domain name

//@todo $epp_deleted->domainRecover($sampledomains['domain1']['domain']);

// Test 24: Cancellation of a contact

//@todo $epp->contactDelete($samplecontacts['registrant1']['handle']);

$epp->logout();
/*
if ($changepassword) {
    $epp->login($cfg["servers"]["test1"]["password"], $cfg['testpassword']); // restore password
    $epp->logout();
}
*/

if ($mode === 'test') {
    $plesk->deleteAlias($sampledomains['domain1']);
    $plesk->deleteAlias($sampledomains['domain2']);
}

exit(0);