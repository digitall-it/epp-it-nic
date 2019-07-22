<?php

namespace digitall;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use RuntimeException;
use SimpleXMLElement;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;

/**
 * Class Epp
 */
class Epp
{
    /**
     * Result code of a response with a successful completed operation
     */
    private const RESPONSE_COMPLETED_SUCCESS = 1000;
    /**
     * Result code of a response with an authorization error
     */
    private const RESPONSE_FAILED_AUTH_ERROR = 2200;
    /**
     * Result code of a response from a successful logout request
     */
    private const RESPONSE_COMPLETED_END_SESSION = 1500;
    /**
     * Result code of a response to a misconfigured command
     */
    private const RESPONSE_FAILED_COMMAND_USE_ERROR = 2002;
    /**
     * Result code of a response with a login request to a server in a too short period of time
     */
    const RESPONSE_FAILED_SESSION_LIMIT_EXCEEDED = 2502;
    /**
     * Result code of a response to a command with a syntax error
     */
    const RESPONSE_FAILED_SYNTAX_ERROR = 2001;
    /**
     * Result code of a response to a command with a required parameter missing
     */
    const RESPONSE_FAILED_REQUIRED_PARAMETER_MISSING = 2003;
    /**
     * Result code of a response to a command with a parameter outside the value range
     */
    const RESPONSE_FAILED_PARAMETER_VALUE_RANGE = 2004;
    /**
     * Result code of a response to a command to change a contact or domain that does not belong to the Registrar
     */
    const RESPONSE_COMPLETED_AUTH_ERROR = 2201;
    /**
     * Result code of a response to a request to use a contact or domain that does not exist on the server
     */
    const RESPONSE_COMPLETED_OBJECT_DOES_NOT_EXIST = 2303;
    /**
     * Result code of a response to a request to perform an action on an object with a locked status
     */
    const RESPONSE_COMPLETED_OBJECT_STATUS_PROHIBITS_OPERATIONS = 2304;
    /**
     * Result code of a response to a request to perform an action on an object that does not belong to parent
     */
    const RESPONSE_FAILED_DATA_MANAGEMENT_POLICY_VIOLATION = 2308;
    /**
     * Result code of an empty queue check
     */
    const RESPONSE_COMPLETED_QUEUE_HAS_NO_MESSAGES = 1300;
    /**
     * Result code of a non empty queue check
     */
    const RESPONSE_COMPLETED_QUEUE_HAS_MESSAGES = 1301;
    /**
     * Result code of a response to an action that is not immediately done but is in a pending state
     */
    const RESPONSE_COMPLETED_ACTION_PENDING = 1001;
    /**
     * @var Client GuzzleHTTP client
     */
    private $client;
    /**
     * @var Environment Twig template system
     */
    private $tpl;
    /**
     * @var string|null Client transaction ID
     */
    private $clTRID;
    /**
     * @var string EPP client name
     */
    private $name;
    /**
     * @var bool Dry run mode
     */
    private $dryrun;

    /**
     * Class constructor
     *
     * @param $name        string Name of the Epp instance for the logs
     * @param $credentials array username, password and server
     */
    public function __construct($name, $credentials, $dryrun = false)
    {
        $this->name = $name;
        $this->dryrun = $dryrun;
        //if($dryrun) file_put_contents(__dir__ . '/logs/epp/'. $this->name . '-dry-run.txt', '');
        try {
            $this->log = new Logger('log-' . $name);
            //ErrorHandler::register($this->log);
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/logs/client/debug-' . $name . '.log', Logger::DEBUG));
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/logs/client/info-' . $name . '.log', Logger::INFO));
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/logs/client/warning-' . $name . '.log', Logger::WARNING));
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/logs/client/error-' . $name . '.log', Logger::ERROR));
        } catch (Exception $e) {
            die('Cannot instantiate logger:' . $e->getMessage());
        }

        $this->credentials = $credentials;

        $this->cookiejar = new FileCookieJar(__DIR__ . '/cookies/cookiejar-' . $name . '.txt', true);

        $this->client = new Client([
            'base_uri' => $this->credentials['uri'],
            'verify' => false,
            'cookies' => $this->cookiejar
        ]);

        $tpl_loader = new FilesystemLoader(__DIR__ . '/tpl/epp');
        $tpl_cfg = [];
        //$tpl_cfg['cache'] = __DIR__ . '/cache';
        $this->tpl = new Environment($tpl_loader, $tpl_cfg);

        $this->log->info('EPP client created');
    }

    /**
     * Class destructor
     */
    public function __destruct()
    {
        $this->log->info('EPP client destroyed');
    }

    /**
     * Transmit an hello message, helps to mantain connection and receive server parameters
     *
     * @return string status
     */
    public function hello(): string
    {

        $response = $this->send($this->render('hello'));
        $this->log->info('hello sent');

        if ($this->dryrun) return '';

        if ($this->nodeExists($response->greeting)) {
            $this->log->info('Received a greeting after hello');
            $status = 'OK';
        } else {
            $this->log->error("No greeting after hello");
            $status = 'KO';
//            throw new RuntimeException('No greeting after hello');
        }
        return $status;

    }

    /**
     * Sends a message to the server, receives and parses the response to a structure
     *
     * @param string $xml Message to transmit
     *
     * @return SimpleXMLElement Structure to return
     */
    private function send(string $xml, bool $debug = false): SimpleXMLElement
    {
        if ($this->dryrun) {
            file_put_contents(__dir__ . '/logs/epp/' . $this->name . '-dry-run.txt', $xml .
                "\n---------------------------------------------------------------------------------------------\n",
                FILE_APPEND);

            return new SimpleXMLElement('<xml></xml>');
        }
        $this->log->debug('Sending... ' . preg_replace('!\s+!', ' ', $xml));
        file_put_contents(__dir__ . '/logs/epp/' . microtime(true) . '-send.xml', $xml);
        try {
            /* @var $client Client */
            if (isset($xml)) {
                $res = $this->client->request('POST', '/',
                    [
                        'body' => $xml
                    ]);
            } else {
                throw new \http\Exception\RuntimeException('Empty request');
            }
        } catch (GuzzleException $e) {
            throw new RuntimeException('Error during transmission: ' . $e->getMessage());
        }
        $xml_received = $res->getBody();
        file_put_contents(__dir__ . '/logs/epp/' . microtime(true) . '-received.xml', $xml_received);
        if ($debug) echo($xml_received);
        $this->log->debug('Receiving... ' . preg_replace('!\s+!', ' ', $xml_received));

        return simplexml_load_string($xml_received);
    }

    /**
     * Renders a message from a template using the given parameters
     *
     * @param       $template string Name of the template to use for rendering
     * @param array $vars     Parameters to fill the template with
     *
     * @return string XML Code generated
     */
    public function render($template, array $vars = []): string
    {
        $vars['clTRID'] = $this->set_clTRID('DGT');

        try {
            $xml = $this->tpl->render($template . '.twig', $vars);
        } catch (LoaderError $e) {
            throw new RuntimeException('Cannot load template file during rendering of ' . $template . ' template: ' . $e->getMessage());
        } catch (RuntimeError $e) {
            throw new RuntimeException('Runtime error during rendering of ' . $template . ' template: ' . $e->getMessage());
        } catch (SyntaxError $e) {
            throw new RuntimeException('Syntax error during rendering of ' . $template . ' template: ' . $e->getMessage());
        }
        return $xml;
    }

    /**
     * Generate a client transaction ID unique to each transmission, to send with the message
     *
     * @param $prefix string Text to attach at the start of the string
     *
     * @return string Generated text
     */
    public function set_clTRID($prefix)
    {
        $this->clTRID = $prefix . "-" . time() . "-" . substr(md5(mt_rand()), 0, 5);
        if (strlen($this->clTRID) > 32)
            $this->clTRID = substr($this->clTRID, -32);
        return $this->clTRID;
    }

    /**
     * Helper function that checks if a node exists in an XML structure
     *
     * @param $obj SimpleXMLElement XML node to check
     *
     * @return bool Result of the check
     */
    public function nodeExists($obj): bool
    {
        return (@is_countable($obj)) && (@count($obj) !== 0);
    }

    /**
     * Login message
     * Can also change the password with an optional second parameter
     * Can use a specific password  instead of the configured one if given the optional third parameter
     *
     * @param string $newpassword  New password to use
     * @param string $testpassword Current password to use, overrides config
     *
     * @return array status
     */
    public function login($newpassword = null, $testpassword = null): array
    {
        $vars = [
            'clID' => $this->credentials['username'],
            'pw' => $this->credentials['password']
        ];

        if ($newpassword !== null) $vars['newPW'] = $newpassword;

        if ($testpassword !== null) $vars['pw'] = $testpassword;


        $xml = $this->render('login', $vars);
        $response = $this->send($xml);
        $this->log->info('login sent');

        if ($this->dryrun) return [];

        if (!$this->nodeExists($response->response)) {
            $this->log->error("No response to login");
            throw new RuntimeException('No response to login');
        };

        $code = (int)$response->response->result["code"];
        $msg = (string)$response->response->result->msg;
        $this->log->info('Received a response to login: ' . $msg);

        $this->handleReturnCode('Session start', $code);

        if (self::RESPONSE_COMPLETED_SUCCESS === $code && $newpassword !== null) {
            $this->log->info('Password changed ' . (($testpassword !== null) ? 'back ' : '') . 'to "' . $newpassword . '"');
        };

        return
            ['status' =>
                [
                    'code' => $code,
                    'msg' => $msg
                ]
            ];
    }

    /**
     * Logs formatted messages regarding common communication problems
     *
     * @param             $msg    string Description of the message sent to the server
     * @param int         $code   Error code
     * @param string|null $reason Optional secondary message that explains more
     */
    private function handleReturnCode($msg, int $code, $reason = null): void
    {
        switch ($code) {
            case self::RESPONSE_COMPLETED_AUTH_ERROR:
                $this->log->warn($msg . ' - Authorization error.');
                $this->logReason($msg, $reason);
                //throw new RuntimeException ($msg . ' - Wrong credentials.' . $reason);
                break;
            case self::RESPONSE_FAILED_AUTH_ERROR:
                $this->log->err($msg . ' - Wrong credentials.');
                $this->logReason($msg, $reason);
                //throw new RuntimeException ($msg . ' - Wrong credentials.' . $reason);
                break;
            case self::RESPONSE_FAILED_SESSION_LIMIT_EXCEEDED:
                $this->log->err($msg . ' - Session limit exceeded, server closing connection. Try again later.');
                $this->logReason($msg, $reason);
                //throw new RuntimeException ($msg . ' - Session limit exceeded, server closing connection. Try again later.' . $reason);
                break;
            case self::RESPONSE_COMPLETED_END_SESSION:
                $this->log->info($msg . ' - Session ended.');
                break;
            case self::RESPONSE_COMPLETED_SUCCESS:
                $this->log->info($msg . ' - Success.');
                break;
            case self::RESPONSE_FAILED_COMMAND_USE_ERROR:
                $this->log->err($msg . ' - Command use error.');
                $this->logReason($msg, $reason);
                //$this->logout(); // Prevent session limit
                //throw new RuntimeException($msg . ' - Command use error.' . $reason);
                break;
            case self::RESPONSE_FAILED_DATA_MANAGEMENT_POLICY_VIOLATION:
                $this->log->err($msg . ' - Data management policy violation.');
                $this->logReason($msg, $reason);
                break;
            //$this->logout(); // Prevent session limit
            //throw new RuntimeException($msg . ' - Data management policy violation.' . $reason);
            case self::RESPONSE_FAILED_SYNTAX_ERROR:
                $this->log->err($msg . ' - Syntax error.');
                $this->logReason($msg, $reason);
                break;
            case self::RESPONSE_COMPLETED_OBJECT_STATUS_PROHIBITS_OPERATIONS:
                $this->log->err($msg . ' - Locked status.');
                $this->logReason($msg, $reason);
                //$this->logout(); // Prevent session limit
                //throw new RuntimeException($msg . ' - Syntax error.' . $reason);
                break;
            case self::RESPONSE_FAILED_PARAMETER_VALUE_RANGE:
                $this->log->err($msg . ' - Syntax error.');
                $this->logReason($msg, $reason);
                //$this->logout(); // Prevent session limit
                //throw new RuntimeException($msg . ' - Syntax error.' . $reason);
                break;
            case self::RESPONSE_FAILED_REQUIRED_PARAMETER_MISSING:
                $this->log->err($msg . ' - Required parameter missing.');
                $this->logReason($msg, $reason);
                //$this->logout(); // Prevent session limit
                //throw new RuntimeException($msg . ' - Required parameter missing.' . $reason);
                break;
            case self::RESPONSE_COMPLETED_OBJECT_DOES_NOT_EXIST:
                break;
            case self::RESPONSE_COMPLETED_QUEUE_HAS_NO_MESSAGES:
                $this->log->info('The queue has no messages');
                break;
            case self::RESPONSE_COMPLETED_QUEUE_HAS_MESSAGES:
                $this->log->info('There are still messages in queue');
                break;
            case self::RESPONSE_COMPLETED_ACTION_PENDING:
                $this->log->info('Command completed successfully; action pending');
                break;
            default:
                $this->log->err($msg . ' - Unhandled return code ' . $code);
                $this->logReason($msg, $reason);
                //$this->logout(); // Prevent session limit
                throw new RuntimeException($msg . ' - Unhandled return code ' . $code . '.' . $reason);
                break;
        }
    }

    /**
     * Logs a secondary reason that comes with certain error codes
     *
     * @param $msg    string Description of the message sent to the server
     * @param $reason string Secondary text to log
     */
    private function logReason($msg, $reason)
    {
        if ($reason !== null) $this->log->err($msg . ' - Reason:' . $reason);
    }

    /**
     * Logs out from the server
     *
     * @return array status
     */
    public function logout(): array
    {
        $xml = $this->render('logout', ['clTRID' => $this->clTRID]);
        $response = $this->send($xml);
        $this->log->info('logout sent');

        if ($this->dryrun) return [];

        if (!$this->nodeExists($response->response)) {
            $this->log->error("No response to logout");
            throw new RuntimeException('No response to logout');
        };

        $code = (int)$response->response->result["code"];
        $msg = (string)$response->response->result->msg;
        $this->log->info('Received a response to logout: ' . $msg);

        $this->handleReturnCode('Session end', (int)$code);

        return
            ['status' =>
                [
                    'code' => $code,
                    'msg' => $msg
                ]
            ];
    }

    /**
     * Check availability of a list of contacts
     *
     * @param $contacts array List of contact handles to check
     *
     * @return array Status and availability
     */
    public function contactsCheck($contacts): array
    {
        $availability = [];

        $xml = $this->render('contact-check', ['contacts' => $contacts]);
        $response = $this->send($xml);
        $handles = "";
        foreach ($contacts as $contact) $handles .= '"' . $contact["handle"] . '", ';
        $handles = rtrim($handles, ', ');
        $this->log->info('contact check sent for ' . $handles);

        if ($this->dryrun) return [];

        if (!$this->nodeExists($response->response)) {
            $this->log->error("No response to logout");
            throw new RuntimeException('No response to logout');
        }

        $code = (int)$response->response->result["code"];
        $msg = (string)$response->response->result->msg;
        $this->log->info('Received a response to contact check: ' . $msg);

        $this->handleReturnCode('Contact check', (int)$code);
        if (self::RESPONSE_COMPLETED_SUCCESS == $code) {
            $ns = $response->getNamespaces(true);
            /**
             * @var $contacts_checked SimpleXMLElement Returned contacts structure with availability data
             */
            $contacts_checked = $response->response->resData->children($ns['contact'])->chkData->cd;
            $logstring = '';
            foreach ($contacts_checked as $contact) {
                $handle = (string)$contact->id;
                $avail = (bool)$contact->id->attributes()->avail;
                $availability[$handle] = $avail;
                $logstring .= '"' . $handle . '" is ' . ($avail ? '' : 'not ') . 'available, ';
            }
            $logstring = rtrim($logstring, ', ') . '.';
            $this->log->info($logstring);
        }

        return [
            'availability' => $availability,
            'status' =>
                [
                    'code' => $code,
                    'msg' => $msg
                ]
        ];
    }


    /**
     * Creates a contact
     *
     * @param array $contact Details of the contact
     *
     * @return array status
     */
    public
    function contactCreate(array $contact): array
    {
        $xml = $this->render('contact-create', ['contact' => $contact]);
        $response = $this->send($xml);

        $this->log->info('contact create sent for "' . $contact['handle'] . '"');

        if ($this->dryrun) return [];

        if (!$this->nodeExists($response->response)) {
            $this->log->error('No response.');
            throw new RuntimeException('No response.');
        }
        $code = (int)$response->response->result["code"];
        $msg = (string)$response->response->result->msg;
        $this->log->info('Received a response to contact create: ' . $msg);
        $reason = ($this->nodeExists($response->response->result->extValue->reason)) ? $response->response->result->extValue->reason : null;
        $this->handleReturnCode('Contact create', (int)$code, $reason);

        return
            [
                'status' =>
                    [
                        'code' => $code,
                        'msg' => $msg
                    ]
            ];

    }

    /**
     * Updates a contact
     *
     * @param array $contact Details of the contact
     *
     * @return array status
     */
    public
    function contactUpdate(array $contact): array
    {

        $xml = $this->render('contact-update', ['contact' => $contact]);

        $response = $this->send($xml);

        $this->log->info('contact update sent for "' . $contact['handle'] . '"');

        if ($this->dryrun) return [];

        if (!$this->nodeExists($response->response)) {
            $this->log->error('No response.');
            throw new RuntimeException('No response.');
        }

        $code = (int)$response->response->result["code"];
        $msg = (string)$response->response->result->msg;
        $this->log->info('Received a response to contact update: ' . $msg);
        $reason = $this->nodeExists($response->response->result->extValue->reason) ? $response->response->result->extValue->reason : null;
        $this->handleReturnCode('Contact update', (int)$code, $reason);

        return [
            'status' =>
                [
                    'code' => $code,
                    'msg' => $msg,
                    'reason' => $reason
                ]
        ];

    }


    /**
     * Gets contact info
     *
     * @param string $handle
     *
     * @return array Status and structured contact data (if exists) with response codes
     */
    public
    function contactGetInfo(string $handle): array
    {
        $xml = $this->render('contact-info', ['handle' => $handle]);

        $response = $this->send($xml);

        $this->log->info('contact info sent for "' . $handle . '"');

        if ($this->dryrun) return [];

        if (!$this->nodeExists($response->response)) {
            $this->log->error('No response.');
            throw new RuntimeException('No response.');
        }

        $code = (int)$response->response->result["code"];
        $msg = (string)$response->response->result->msg;
        $this->log->info('Received a response to contact info: ' . $msg);
        $reason = ($this->nodeExists($response->response->result->extValue->reason)) ? $response->response->result->extValue->reason : null;
        $this->handleReturnCode('Contact info', (int)$code, $reason);

        $return = [
            'status' =>
                [
                    'code' => $code,
                    'msg' => $msg
                ]
        ];

        if (self::RESPONSE_COMPLETED_SUCCESS == $code) {
            $ns = $response->getNamespaces(true);
            $return['contact'] = $response->response->resData->children($ns['contact'])->infData;
        };

        return $return;

    }


    /**
     * Checks multiple domains for availability
     *
     * @param $domains
     *
     * @return array Status and availability data about contacts
     */
    public
    function domainsCheck($domains): array
    {
        $availability = [];

        $xml = $this->render('domain-check', ['domains' => $domains]);
        $response = $this->send($xml);
        $names = "";
        foreach ($domains as $domain) $names .= '"' . $domain["name"] . '", ';
        $names = rtrim($names, ', ');
        $this->log->info('domain check sent for ' . $names);

        if ($this->dryrun) return [];

        if (!$this->nodeExists($response->response)) {
            $this->log->error('No response.');
            throw new RuntimeException('No response.');
        }

        $code = (int)$response->response->result["code"];
        $msg = (string)$response->response->result->msg;
        $this->log->info('Received a response to domain check: ' . $msg);

        $this->handleReturnCode('domain check', (int)$code);

        $return = [
            'status' =>
                [
                    'code' => $code,
                    'msg' => $msg
                ]
        ];

        if (self::RESPONSE_COMPLETED_SUCCESS == $code) {
            $ns = $response->getNamespaces(true);
            $domains = $response->response->resData->children($ns['domain'])->chkData->cd;
            $logstring = '';
            foreach ($domains as $domain) {
                $name = (string)$domain->name;
                $avail = (bool)$domain->name->attributes()->avail;

                $return_domain =
                    ['avail' => $avail];
                $logstring .= '"' . $name . '" is ' . ($avail ? '' : 'not ') . 'available, ';

                if (!$avail) {
                    $reason = $domain->reason;
                    $return_domain['reason'] = $reason;
                    $logstring = rtrim($logstring, ', ') . '(reason is:' . $reason . '), ';
                }

                $availability[$name] = $return_domain;


            }
            $logstring = rtrim($logstring, ', ') . '.';
            $this->log->info($logstring);

            $return['availability'] = $availability;
        }

        return $return;

    }


    /**
     * Creates a domain
     *
     * @param array $domain Structured data of the domain
     *
     * @return array status
     */
    public
    function domainCreate(array $domain): array
    {
        $xml = $this->render('domain-create', ['domain' => $domain]);
        $response = $this->send($xml);

        $this->log->info('domain create sent for "' . $domain['name'] . '"');

        if ($this->dryrun) return [];

        if (!$this->nodeExists($response->response)) {
            $this->log->error('No response.');
            throw new RuntimeException('No response.');
        }

        $code = (int)$response->response->result["code"];
        $msg = (string)$response->response->result->msg;
        $this->log->info('Received a response to domain create: ' . $msg);
        $reason = ($this->nodeExists($response->response->result->extValue->reason)) ? $response->response->result->extValue->reason : null;
        $this->handleReturnCode('domain create', (int)$code, $reason);

        return [
            'status' =>
                [
                    'code' => $code,
                    'msg' => $msg,
                    'reason' => $reason
                ]
        ];
    }


    /**
     * Updates a domain
     *
     * @param array $domain Details of the domain to update
     *
     * @return array status
     */
    public
    function domainUpdate(array $domain)
    {
        $xml = $this->render('domain-update', ['domain' => $domain]);
        $response = $this->send($xml);

        $this->log->info('domain update sent for "' . $domain['name'] . '"');

        if ($this->dryrun) return [];

        if (!$this->nodeExists($response->response)) {
            $this->log->error('No response.');
            throw new RuntimeException('No response.');
        }

        $code = (int)$response->response->result["code"];
        $msg = (string)$response->response->result->msg;
        $this->log->info('Received a response to domain update: ' . $msg);
        $reason = ($this->nodeExists($response->response->result->extValue->reason)) ? $response->response->result->extValue->reason : null;
        $this->handleReturnCode('domain update', (int)$code, $reason);

        return [
            'status' =>
                [
                    'code' => $code,
                    'msg' => $msg,
                    'reason' => $reason
                ]
        ];
    }


    /**
     * @param string      $name
     * @param string|null $authInfo
     *
     * @return array status
     */
    public
    function domainGetInfo(string $name, string $authInfo = null)
    {
        $vars = ['name' => $name];
        if ($authInfo !== null) $vars['authInfo'] = $authInfo;

        $xml = $this->render('domain-info', $vars);

        $response = $this->send($xml);

        $this->log->info('domain info request sent for "' . $name . '"');

        if ($this->dryrun) return [];

        if (!$this->nodeExists($response->response)) {
            $this->log->error('No response.');
            throw new RuntimeException('No response.');
        };

        $code = (int)$response->response->result["code"];
        $msg = (string)$response->response->result->msg;
        $this->log->info('Received a response to domain info request: ' . $msg);
        $reason = ($this->nodeExists($response->response->result->extValue->reason)) ? $response->response->result->extValue->reason : null;
        $this->handleReturnCode('domain info', (int)$code, $reason);


        $return = [
            'status' =>
                [
                    'code' => $code,
                    'msg' => $msg,
                    'reason' => $reason
                ]
        ];


        if (self::RESPONSE_COMPLETED_SUCCESS == $code) {
            $ns = $response->getNamespaces(true);
            $return['domain'] = $response->response->resData->children($ns['domain'])->infData;
        };

        return $return;

    }


    /**
     * Check polling queue
     */
    public
    function pollCheck(): void
    {

        if ($this->dryrun) return;

        $result = $this->pollRequest();

        if ($result['status']['code'] == self::RESPONSE_COMPLETED_QUEUE_HAS_MESSAGES) {
            $contents = print_r($result, true);

            file_put_contents(__dir__ . '/logs/queue/' . microtime(true) . '.txt', $contents);

            $this->pollAck($result['id']);
        }

    }

    /**
     * @return array status with optional message
     */
    public function pollRequest()
    {
        $xml = $this->render('poll-request');
        $response = $this->send($xml);

        $this->log->info('Poll req request sent');

        if ($this->dryrun) return [];

        if (!$this->nodeExists($response->response)) {
            $this->log->error('No response.');
            throw new RuntimeException('No response.');
        }

        $code = (int)$response->response->result["code"];
        $msg = (string)$response->response->result->msg;
        $this->log->info('Received a response to poll req request: ' . $msg);
        $reason = ($this->nodeExists($response->response->result->extValue->reason)) ? $response->response->result->extValue->reason : null;
        $this->handleReturnCode('poll req', (int)$code, $reason);

        $return =
            [
                'status' =>
                    [
                        'code' => $code,
                        'msg' => $msg,
                        'reason' => $reason
                    ]
            ];

        if (self::RESPONSE_COMPLETED_QUEUE_HAS_MESSAGES == $code) {
            //$ns = $response->getNamespaces(true);
            //$return['domain'] = $response->response->resData->children($ns['domain'])->infData;
            $return['msg'] = (string)$response->response->msgQ->msg;
            $return['count'] = (int)$response->response->msgQ->attributes()->count;
            $return['id'] = (string)$response->response->msgQ->attributes()->id;
            $return['date'] = (string)$response->response->msgQ->qDate;

            if ($this->nodeExists($response->response->extension->children('http://www.nic.it/ITNIC-EPP/extdom-2.0'))) {
                $extdom = $response->response->extension->children('http://www.nic.it/ITNIC-EPP/extdom-2.0');
                $return['dnsErrorMsgData'] = $extdom->dnsErrorMsgData;
                $log_dns = $return['dnsErrorMsgData']->asXML();
                file_put_contents(__dir__ . '/logs/queue/' . microtime(true) . '-dns.txt', $log_dns);
            }

            //$return['extension'] = $response->response->extension
            $this->log->info('Message queued on ' . $return['date'] . ' with ID ' . $return['id'] . ' "' . $return['msg'] . '"');
        } else if (self::RESPONSE_COMPLETED_QUEUE_HAS_NO_MESSAGES) {
            $this->log->info('No messages in queue');
        };

        return $return;
    }

    /**
     * @param string $id
     *
     * @return array status
     */
    public function pollAck(string $id): array
    {
        $xml = $this->render('poll-ack', ['id' => $id]);
        $response = $this->send($xml);

        $this->log->info('Poll ack request sent');

        if ($this->dryrun) return [];

        if (!$this->nodeExists($response->response)) {
            $this->log->error('No response.');
            throw new RuntimeException('No response.');
        }

        $code = (int)$response->response->result["code"];
        $msg = (string)$response->response->result->msg;
        $this->log->info('Received a response to poll ack request: ' . $msg);
        $reason = ($this->nodeExists($response->response->result->extValue->reason)) ? $response->response->result->extValue->reason : null;
        $this->handleReturnCode('poll ack', (int)$code, $reason);

        return [
            'status' =>
                [
                    'code' => $code,
                    'msg' => $msg,
                    'reason' => $reason
                ]
        ];

    }

    /**
     * @param array  $domain
     * @param string $op
     * @param null   $extdom
     *
     * @return array status
     */
    public
    function domainTransfer(array $domain, string $op, $extension = null): array
    {
        if (!in_array($op, ['request', 'cancel', 'approve', 'reject', 'query'])) throw new RuntimeException('Operation ' . $op . ' is not valid. Accepted operations are \'request\',\'cancel\',\'approve\', \'reject\' and \'query\'.');

        $vars = [
            'domain' => $domain,
            'op' => $op
        ];
        if ($extension !== null) $vars['extension'] = $extension;

        $xml = $this->render('domain-transfer',
            $vars
        );
        $response = $this->send($xml);
        $this->log->info('Domain transfer request sent, operation ' . $op);

        if ($this->dryrun) return [];

        if (!$this->nodeExists($response->response)) {
            $this->log->error('No response.');
            throw new RuntimeException('No response.');
        }

        $code = (int)$response->response->result["code"];
        $msg = (string)$response->response->result->msg;
        $this->log->info('Received a response to domain transfer ' . $op . ': ' . $msg);
        $reason = ($this->nodeExists($response->response->result->extValue->reason)) ? $response->response->result->extValue->reason : null;
        $this->handleReturnCode('domain transfer ' . $op, (int)$code, $reason);


        return [
            'status' =>
                [
                    'code' => $code,
                    'msg' => $msg,
                    'reason' => $reason
                ]
        ];
    }


    /**
     * Delete a contact
     *
     * @param string $handle
     *
     * @return array status
     */
    public
    function contactDelete(string $handle): array
    {
        $xml = $this->render('contact-delete', ['handle' => $handle]);
        $response = $this->send($xml);

        $this->log->info('Contact delete for ' . $handle . ' request sent.');
        if ($this->dryrun) return [];

        if (!$this->nodeExists($response->response)) {
            $this->log->error('No response.');
            throw new RuntimeException('No response.');
        }

        $code = (int)$response->response->result["code"];
        $msg = (string)$response->response->result->msg;
        $this->log->info('Received a response to request of contact ' . $handle . ' deletion: ' . $msg);
        $reason = ($this->nodeExists($response->response->result->extValue->reason)) ? $response->response->result->extValue->reason : null;
        $this->handleReturnCode('contact delete ' . $handle, (int)$code, $reason);

        return [
            'status' =>
                [
                    'code' => $code,
                    'msg' => $msg,
                    'reason' => $reason
                ]
        ];
    }

    /**
     * Delete a domain
     *
     * @param string $name
     *
     * @return array status
     */
    public
    function domainDelete(string $name): array
    {
        $xml = $this->render('domain-delete', ['name' => $name]);
        $response = $this->send($xml);

        $this->log->info('Domain  delete for ' . $name . ' request sent.');
        if ($this->dryrun) return [];


        if (!$this->nodeExists($response->response)) {
            $this->log->error('No response.');
            throw new RuntimeException('No response.');
        }

        $code = (int)$response->response->result["code"];
        $msg = (string)$response->response->result->msg;
        $this->log->info('Received a response to request of domain ' . $name . ' deletion: ' . $msg);
        $reason = ($this->nodeExists($response->response->result->extValue->reason)) ? $response->response->result->extValue->reason : null;
        $this->handleReturnCode('domain delete ' . $name, (int)$code, $reason);

        return [
            'status' =>
                [
                    'code' => $code,
                    'msg' => $msg,
                    'reason' => $reason
                ]
        ];
    }
}