<?php
require_once __DIR__ . '/vendor/autoload.php';

use digitall\Epp;
use digitall\Plesk;
use Symfony\Component\Yaml\Yaml;

$cfg = Yaml::parseFile(__DIR__ . '/config.yaml');
$changepassword = false;
function remove_logs_client()
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

function remove_logs_epp()
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

function remove_logs_queue()
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

function remove_logs_plesk()
{
    $logpath = __DIR__ . '/digitall/logs/plesk/';
    $dirh = opendir($logpath);
    while (($file = readdir($dirh)) !== false) {
        if (in_array($file, ['.', '..'])) continue;
        $file_parts = pathinfo($file);
        if ($file_parts['extension'] === 'xml') unlink($logpath . $file);
    }
    closedir($dirh);
}

$mode = $cfg['mode'];
if ($mode === 'test') {
    $epp = new Epp("epp1", $cfg["servers"]["test1"]);
    // Clean the polling queue
    $epp->login();
    $epp->pollCheck();
    $epp->logout();
    unset($epp);
}

remove_logs_client();
remove_logs_epp();
remove_logs_queue();
remove_logs_plesk();

//die();

try {
    $plesk = new Plesk($cfg['provisioner']['plesk']); // Plesk client used to provision domain aliases used to pass DNS check
} catch (Exception $e) {
    die('Cannot instantiate client:' . $e->getMessage());
}

/**********************************
 *  Section 1: session operations *
 **********************************/

echo "Test 1 - Handshake:";
$epp = new Epp("epp1", $cfg["servers"]["test1"]);
$epp->hello();
echo "OK\n";

echo "Test 2 - Authentication (by opening one or more simultaneous sessions):";
$epp->login();
$epp2 = new Epp("epp2", $cfg["servers"]["test2"]);
$epp2->login();
//$epp2->logout();
echo "OK\n";

echo "Test 3 - Change password:";
if ($mode === 'exam') {
    // skip in test env
    $epp->logout();
    $epp->login($cfg['testpassword']);
}

echo "OK\n";

/***********************************************
 * Section 2: operations for managing contacts *
 ***********************************************/

echo "Test 4 - Check the availability of contact identifiers to be used during the accreditation test:";
$samplecontacts = $cfg["samplecontacts"];
$handles = [];
foreach ($samplecontacts as $id => $contact) {
    $handle = $cfg['prefix'] . '-' . substr(md5(mt_rand()), 0, 5);
    $samplecontacts[$id]["handle"] = $handle;
}
$epp->contactsCheck($samplecontacts);
echo "OK\n";

echo "Test 5 - Creation of three registrant contacts:";
$epp->contactCreate($samplecontacts['registrant1']);
$epp->contactCreate($samplecontacts['registrant2']);
$epp->contactCreate($samplecontacts['registrant3']);
echo "OK\n";

echo "Test 6 - Creation of two tech / admin contacts:";
$epp->contactCreate($samplecontacts['techadmin1']);
$epp->contactCreate($samplecontacts['techadmin2']);
echo "OK\n";

echo "Test 7 - Updating one of the properties of a contact:";
$epp->contactUpdate(
    [
        'handle' => $samplecontacts['techadmin2']["handle"],
        'chg' => ['voice' => "+39.0246125585"]
    ]
);
echo "OK\n";

echo "Test 8 - Display of contact information:";
$epp->contactGetInfo($samplecontacts['registrant2']['handle']);
echo "OK\n";

/************************************************************
 * Section 3: operations for the management of domain names *
 ************************************************************/

echo "Test 9 - Verification of the availability of two domain names:";
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
$epp->domainsCheck($sampledomains);
echo "OK\n";

echo "Test 10 - Creation of two domain names:";
if ($mode === 'test') { // Provision domain aliases to pass DNS check in test env
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
}
$epp->domainCreate($sampledomains['domain1']);
$epp->domainCreate($sampledomains['domain2']);
echo "OK\n";

echo "Test 11 - Adding a constraint to a domain name to prevent transfer:";
$epp->domainUpdate(
    [
        'name' => $sampledomains['domain2']['name'],
        'add' => ['status' => "clientTransferProhibited"]
    ]
);
echo "OK\n";

echo "Test 12 - Displaying the information of a domain name:";
$epp->domainGetInfo($sampledomains['domain2']['name']);
echo "OK\n";

echo "Test 13 - Updating the list of nameservers associated with a domain name";
if ($mode === 'test') { // Provision domain aliases to pass DNS check
    $plesk->deleteAlias($sampledomains['domain1']['name']);
    $plesk->createAlias($sampledomains['domain1']['name'], $testdomain_secondary);
}
$epp->domainUpdate(
    [
        'name' => $sampledomains['domain1']['name'],
        'add' => [
            'ns' => [
                'ns3' => $sampledomains['domain1']['ns_test']['tertiary']
            ]
        ],
        'rem' => [
            'ns' => [
                'ns2' => $sampledomains['domain1']['ns']['secondary']
            ]
        ]
    ]
);
echo "OK\n";

echo "Test 14: Change of the Registrant of a domain name:";
$epp->domainUpdate(
    [
        'name' => $sampledomains['domain2']['name'],
        'chg' => [
            'contact' => [
                'registrant' => $samplecontacts['registrant2']
            ]
        ]
    ]
);
echo "OK\n";

echo "Test 15 - Request to change the Registrar of a domain name:";
$epp->domainTransfer($sampledomains['domain1'], 'request');
echo "OK\n";

/*
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
    <command>
        <transfer op="request">
            <domain:transfer xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
                <domain:name>
                    test.it
                </domain:name>
                <domain:authInfo>
                    <domain:pw>
                        newwwtest-it
                    </domain:pw>
                </domain:authInfo>
            </domain:transfer>
        </transfer>
        <clTRID>
            xxxxxxx-xxxxxxx
        </clTRID>
    </command>
</epp>
*/

echo "Test 16 - New request to change the Registrar of a domain name:";
$epp->domainTransfer($sampledomains['domain1'], 'request');
echo "OK\n";
/*
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
    <command>
        <update>
            <domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
                <domain:name>
                    test.it
                </domain:name>
                <domain:rem>
                    <domain:status s="clientTransferProhibited"/>
                </domain:rem>
            </domain:update>
        </update>
        <clTRID>
            xxxxxxx-xxxxxxx
        </clTRID>
    </command>
</epp>
*/

/*
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
    <command>
        <transfer op="request">
            <domain:transfer xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
                <domain:name>
                    test.it
                </domain:name>
                <domain:authInfo>
                    <domain:pw>
                        newwwtest-it
                    </domain:pw>
                </domain:authInfo>
            </domain:transfer>
        </transfer>
        <clTRID>
            xxxxxxx-xxxxxxx
        </clTRID>
    </command>
</epp>
*/

/*
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
    <command>
        <transfer op="query">
            <domain:transfer xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
                <domain:name>
                    test.it
                </domain:name>
                <domain:authInfo>
                    <domain:pw>
                        newwwtest-it
                    </domain:pw>
                </domain:authInfo>
            </domain:transfer>
        </transfer>
        <clTRID>
            xxxxxxx-xxxxxxx
        </clTRID>
    </command>
</epp>
*/

/*
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
    <command>
        <poll op="req"/>
        <clTRID>
            xxxxxxx-xxxxxxx
        </clTRID>
    </command>
</epp>
*/


echo "Test 17 - Approval of the request to change the Registrar and elimination of the request message from the polling queue:";
$epp->domainTransfer($sampledomains['domain1']['name'], 'approve');

/*
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
    <command>
        <transfer op="approve">
            <domain:transfer xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
                <domain:name>
                    test.it
                </domain:name>
                <domain:authInfo>
                    <domain:pw>
                        newwwtest-it
                    </domain:pw>
                </domain:authInfo>
            </domain:transfer>
        </transfer>
        <clTRID>
            xxxxxxx-xxxxxxx
        </clTRID>
    </command>
</epp>
*/

/*
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
    <command>
        <poll op="ack" msgID="1"/>
        <clTRID>
            xxxxxxx-xxxxxxx
        </clTRID>
    </command>
</epp>
*/

/*
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
    <command>
        <poll op="ack" msgID="3"/>
        <clTRID>
            xxxxxxx-xxxxxxx
        </clTRID>
    </command>
</epp>
*/
echo "OK\n";

echo "Test 18 - Modification of the AuthInfo code of a domain name:";
$sampledomains['domain2']['authInfo'] .= '-changed';
$epp->domainUpdate(
    [
        'name' => $sampledomains['domain2']['name'],
        'chg' => [
            'authInfo' => $sampledomains['domain2']['authInfo']
        ]
    ]
);

echo "OK\n";

echo "Test 19 - Request to change the Registrant contextual to a change of the Registrar for a domain name:";
/*
$epp->domainUpdateRegistrantAndRegistrar(
    [
        'domain' => $sampledomains['domain1'],
        'registrant' => $samplecontacts['registrant3'],
        'registrar' => 'blah'
    ]
);*/

/*
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
    <command>
        <transfer op="request">
            <domain:transfer xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
                <domain:name>
                    test-1.it
                </domain:name>
                <domain:authInfo>
                    <domain:pw>
                        WWWtest-1
                    </domain:pw>
                </domain:authInfo>
            </domain:transfer>
        </transfer>
        <extension>
            <extdom:trade xmlns:extdom="http://www.nic.it/ITNIC-EPP/extdom-1.0" xsi:schemaLocation="http://www.nic.it/ITNIC-EPP/extdom-1.0 extdom-1.0.xsd">
                <extdom:transferTrade>
                    <extdom:newRegistrant>
                        HH10
                    </extdom:newRegistrant>
                    <extdom:newAuthInfo>
                        <extdom:pw>
                            HAC6-007
                        </extdom:pw>
                    </extdom:newAuthInfo>
                </extdom:transferTrade>
            </extdom:trade>
        </extension>
        <clTRID>
            xxxxxxx-xxxxxxx
        </clTRID>
    </command>
</epp>
*/
echo "OK\n";

echo "Test 20 - Approval of the request to change the Registrant and the Registrar:";

$epp->domainTransfer($sampledomains['domain1']['name'], 'approve');
/*
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
    <command>
        <poll op="req"/>
        <clTRID>
            xxxxxxx-xxxxxxx
        </clTRID>
    </command>
</epp>
*/

/*
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
    <command>
        <transfer op="approve">
            <domain:transfer xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
                <domain:name>
                    test-1.it
                </domain:name>
                <domain:authInfo>
                    <domain:pw>
                        WWWtest-1
                    </domain:pw>
                </domain:authInfo>
            </domain:transfer>
        </transfer>
        <clTRID>
            xxxxxxx-xxxxxxx
        </clTRID>
    </command>
</epp>
*/


echo "OK\n";

echo "Test 21 - Adding a constraint to a domain name to prevent it from being modified:";
$epp->domainUpdate(
    [
        'name' => $sampledomains['domain2']['name'],
        'add' => [
            'status' => "clientUpdateProhibited"
        ]
    ]
);
echo "OK\n";

echo "Test 22 - Cancellation of a domain name:";
$epp->domainDelete($sampledomains['domain1']['domain']);
echo "OK\n";

echo "Test 23 - Restoring a deleted domain name:";

/*
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
    <command>
        <update>
            <domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
                <domain:name>
                    test-1.it
                </domain:name>
                <domain:chg/>
            </domain:update>
        </update>
        <extension>
            <rgp:update xmlns:rgp="urn:ietf:params:xml:ns:rgp-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:rgp-1.0 rgp-1.0.xsd">
                <rgp:restore op="request"/>
            </rgp:update>
        </extension>
        <clTRID>
            xxxxxxx-xxxxxxx
        </clTRID>
    </command>
</epp>
*/

/*
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
    <command>
        <update>
            <domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
                <domain:name>
                    test-1.it
                </domain:name>
                <domain:chg/>
            </domain:update>
        </update>
        <extension>
            <rgp:update xmlns:rgp="urn:ietf:params:xml:ns:rgp-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:rgp-1.0 rgp-1.0.xsd">
                <rgp:restore op="request"/>
            </rgp:update>
        </extension>
        <clTRID>
            xxxxxxx-xxxxxxx
        </clTRID>
    </command>
</epp>
*/


/*
$epp_deleted = new Epp("deleted1", $cfg["servers"]["deleted1"]);
$epp_deleted->login();
$epp_deleted->domainRecover($sampledomains['domain1']['domain']);
*/
echo "OK\n";

echo "Test 24 - Cancellation of a contact:";
$epp->contactDelete($samplecontacts['registrant1']['handle']);
echo "OK";

if ($mode === 'test') {
    // Deprovision domain aliases used to pass DNS check
    $plesk->deleteAlias($sampledomains['domain1']);
    $plesk->deleteAlias($sampledomains['domain2']);

    $epp->logout();
    $epp2->logout();
}
exit(0);