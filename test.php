<?php
require_once __DIR__ . '/vendor/autoload.php';

use digitall\Epp;
use Symfony\Component\Yaml\Yaml;

$cfg = Yaml::parseFile(__DIR__ . '/config.yaml');

$changepassword = false;

function removelogs()
{
    $logpath = __DIR__ . '/digitall/logs/';
    $dirh = opendir($logpath);
    while (($file = readdir($dirh)) !== false) {
        if (in_array($file, array('.', '..'))) continue;

        $file_parts = pathinfo($file);
        if ($file_parts['extension'] === 'log') unlink($logpath . $file);
    }
    closedir($dirh);
}

removelogs();

//$epp2->login();

/**************************************
 *  Sezione 1: operazioni di sessione *
 **************************************/

// Test 1: Handshake

$epp = new Epp("epp1", $cfg["servers"]["test1"]);
$epp->hello();

// Test 2: Autenticazione (tramite l’apertura di una o più sessioni simultanee)

$epp->login();

$epp2 = new Epp("epp2", $cfg["servers"]["test2"]);
$epp2->hello();
$epp2->login();
$epp2->logout();

// Test 3: Modifica della password

if ($changepassword) {
    $epp->logout();
    $epp->login($cfg['testpassword']);
}

/******************************************************
 * Sezione 2: operazioni per la gestione dei contatti *
 ******************************************************/

// Test 4: Controllo della disponibilità degli identificatori dei contatti da utilizzare durante il test di accreditamento

$samplecontacts = $cfg["samplecontacts"];

$handles = [];

foreach ($samplecontacts as $id => $contact) {
    $handle = $cfg['prefix'] . '-' . substr(md5(mt_rand()), 0, 5);
    $samplecontacts[$id]["handle"] = $handle;
}

$epp->contactsCheck($samplecontacts);

// Test 5: Creazione di tre contatti di tipo registrant

$epp->contactCreate($samplecontacts['registrant1']);
$epp->contactCreate($samplecontacts['registrant2']);
$epp->contactCreate($samplecontacts['registrant3']);

// Test 6: Creazione di due contatti di tipo tech/admin

$epp->contactCreate($samplecontacts['techadmin1']);
$epp->contactCreate($samplecontacts['techadmin2']);

// Test 7: Aggiornamento di una delle proprietà di un contatto

$epp->contactUpdate(
    [
        'handle' => $samplecontacts['techadmin2']["handle"],
        'chg' => ['voice' => "+39.0246125585"]
    ]
);

// Test 8: Visualizzazione delle informazioni di un contatto

$epp->contactGetInfo($samplecontacts['registrant2']['handle']);

/************************************************************
 * Sezione 3: operazioni per la gestione dei nomi a dominio *
 ************************************************************/
// Test 9: Verifica della disponibilità di due nomi a dominio

$sampledomains = $cfg["sampledomains"];

foreach ($sampledomains as $id => $domain) {
    $name = strtolower($cfg['prefix'] . '-' . substr(md5(mt_rand()), 0, 5) . '-' . $domain['name']);
    $sampledomains[$id]["name"] = $name;
}
$epp->domainsCheck($sampledomains);

// Test 10: Creazione di due nomi a dominio

//@todo $epp->domainCreate($sampledomains['domain1']);
//@todo $epp->domainCreate($sampledomains['domain2']);


// Test 11: Aggiunta di un vincolo ad un nome a dominio per impedirne il trasferimento

/* @todo
 * $epp->domainUpdate(
 * [
 * 'domain' => $sampledomains['domain1'],
 * 'blah' => 'blah'
 * ]
 * );
 */

// Test 12: Visualizzazione delle informazioni di un nome a dominio

//@todo $epp->domainGetInfo($sampledomains['domain1']);

// Test 13: Aggiornamento della lista dei nameserver associati a un nome a dominio
/* @todo
 * $epp->domainUpdateNameservers(
 * [
 * 'domain' => $sampledomains['domain1'],
 * 'blah' => 'blah'
 * ]
 * );
 */
// Test 14: Modifica del Registrante di un nome a dominio
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
 *
 * // @todo ???
 *
 * // Test 16: Nuova richiesta di modifica del Registrar di un nome a dominio
 *
 * // @todo ???
 *
 * // Test 17: Approvazione della richiesta di modifica del Registrar ed eliminazione del messaggio di richiesta dalla coda di polling
 *
 * // @todo ???
 *
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
 *
 * // @todo ???
 *
 * // Test 21: Aggiunta di un vincolo a un nome a dominio per impedirne la modifica
 * /* @todo
 * $epp->domainUpdate(
 * [
 * 'domain' => $sampledomains['domain1'],
 * 'blah' => 'blah'
 * ]
 * );
 */
// Test 22: Cancellazione di un nome a dominio

//@todo $epp->domainDelete($sampledomains['domain1']['domain']);

// Test 23: Ripristino di un nome a dominio cancellato

//@todo $epp_deleted->domainRecover($sampledomains['domain1']['domain']);


// Test 24: Cancellazione di un contatto

//@todo $epp->contactDelete($samplecontacts['registrant1']['handle']);

$epp->logout();
if ($changepassword) {
    $epp->login($cfg["servers"]["test1"]["password"], $cfg['testpassword']); // restore password
    $epp->logout();
}

exit(0);