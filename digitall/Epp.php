<?php

namespace digitall;

use Exception;
use GuzzleHttp\Client;
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
    private const RESPONSE_COMPLETED_SUCCESS = 1000;
    private const RESPONSE_FAILED_AUTH_ERROR = 2200;
    private const RESPONSE_COMPLETED_END_SESSION = 1500;
    private const RESPONSE_FAILED_COMMAND_USE_ERROR = 2002;
    const RESPONSE_FAILED_SESSION_LIMIT_EXCEEDED = 2502;
    const RESPONSE_FAILED_SYNTAX_ERROR = 2001;
    const RESPONSE_FAILED_REQUIRED_PARAMETER_MISSING = 2003;
    const RESPONSE_FAILED_PARAMETER_VALUE_RANGE = 2004;
    private $client;
    private $tpl;
//    private $svTRID;
    private $clTRID;

    /**
     * Epp constructor.
     * @param $name
     * @param $credentials
     */
    public function __construct($name, $credentials)
    {

        try {
            $this->log = new Logger('log-' . $name);
            //ErrorHandler::register($this->log);
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/logs/debug-' . $name . '.log', Logger::DEBUG));
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/logs/info-' . $name . '.log', Logger::INFO));
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/logs/warning-' . $name . '.log', Logger::WARNING));
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/logs/error-' . $name . '.log', Logger::ERROR));
        } catch (Exception $e) {
            die('Cannot instantiate logger:' . $e->getMessage());
        }

        $this->credentials = $credentials;

        $this->client = new Client([
            'base_uri' => $this->credentials['uri'],
            'verify' => false,
            'cookies' => true
        ]);

        $tpl_loader = new FilesystemLoader(__DIR__ . '/xml');
        $tpl_cfg = array();
        //$tpl_cfg['cache'] = __DIR__ . '/cache';
        $this->tpl = new Environment($tpl_loader, $tpl_cfg);

        $this->log->info('EPP client created');
    }

    /**
     *
     */
    public function __destruct()
    {
        $this->log->info('EPP client destroyed');
    }

    public function hello(): void
    {

        $response = $this->send($this->render('hello'));
        $this->log->info('hello sent');

        if ($this->nodeExists($response->greeting)) {
            $this->log->info('Received a greeting after hello');
        } else {
            $this->log->error("No greeting after hello");
            throw new RuntimeException('No greeting after hello');
        }

    }

    /**
     * @param string $xml
     * @return SimpleXMLElement
     */
    private function send(string $xml, bool $debug = false): SimpleXMLElement
    {
        try {
            /* @var $client Client */
            if (isset($xml)) {
                $res = $this->client->request('POST', '',
                    [
                        'body' => $xml
                    ]);
            } else {
                throw new \http\Exception\RuntimeException('Empty request');
            }
        } catch (GuzzleException $e) {
            throw new RuntimeException('Error during transmission: ' . $e->getMessage());
        }
        if ($debug) echo($res->getBody());
        return simplexml_load_string($res->getBody());
    }

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

    public function set_clTRID($prefix)
    {
        $this->clTRID = $prefix . "-" . time() . "-" . substr(md5(mt_rand()), 0, 5);
        if (strlen($this->clTRID) > 32)
            $this->clTRID = substr($this->clTRID, -32);
        return $this->clTRID;
    }

    public function nodeExists($obj): bool
    {
        return (is_countable($obj)) && (count($obj) !== 0);
    }

    /**
     * @param null $newpassword
     * @param null $testpassword
     */
    public function login($newpassword = null, $testpassword = null): void
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

        if ($this->nodeExists($response->response)) {
            $code = $response->response->result["code"];
            $this->log->info('Received a response to login: ' . $response->response->result->msg);

            $this->handleReturnCode('Session start', (int)$code);

            if (
                self::RESPONSE_COMPLETED_SUCCESS == $code &&
                $newpassword !== null
            ) {
                $this->log->info('Password changed ' . (($testpassword !== null) ? 'back ' : '') . 'to "' . $newpassword . '"');
            }

        } else {
            $this->log->error("No response to login");
            throw new RuntimeException('No response to login');
        }
    }

    private function logReason($msg, $reason)
    {
        if ($reason !== null) $this->log->err($msg . ' - Reason:' . $reason);
    }

    private function handleReturnCode($msg, int $code, $reason = null): void
    {
        switch ($code) {
            case self::RESPONSE_FAILED_AUTH_ERROR:
                $this->log->err($msg . ' - Wrong credentials.');
                $this->logReason($msg, $reason);
                throw new RuntimeException ($msg . ' - Wrong credentials.' . $reason);
                break;
            case self::RESPONSE_FAILED_SESSION_LIMIT_EXCEEDED:
                $this->log->err($msg . ' - Session limit exceeded, server closing connection. Try again later.');
                $this->logReason($msg, $reason);
                throw new RuntimeException ($msg . ' - Session limit exceeded, server closing connection. Try again later.' . $reason);
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
                $this->logout(); // Prevent session limit
                throw new RuntimeException($msg . ' - Command use error.' . $reason);
                break;
            case self::RESPONSE_FAILED_SYNTAX_ERROR:
                $this->log->err($msg . ' - Syntax error.');
                $this->logReason($msg, $reason);
                $this->logout(); // Prevent session limit
                throw new RuntimeException($msg . ' - Syntax error.' . $reason);
                break;
            case self::RESPONSE_FAILED_PARAMETER_VALUE_RANGE:
                $this->log->err($msg . ' - Syntax error.');
                $this->logReason($msg, $reason);
                $this->logout(); // Prevent session limit
                throw new RuntimeException($msg . ' - Syntax error.' . $reason);
                break;
            case self::RESPONSE_FAILED_REQUIRED_PARAMETER_MISSING:
                $this->log->err($msg . ' - Required parameter missing.');
                $this->logReason($msg, $reason);
                $this->logout(); // Prevent session limit
                throw new RuntimeException($msg . ' - Required parameter missing.' . $reason);
                break;
            default:
                $this->log->err($msg . ' - Unhandled return code ' . $code);
                $this->logout(); // Prevent session limit
                throw new RuntimeException($msg . ' - Unhandled return code ' . $code . '.' . $reason);
                break;
        }
    }

    public function logout(): void
    {
        $xml = $this->render('logout', ['clTRID' => $this->clTRID]);
        $response = $this->send($xml);
        $this->log->info('logout sent');

        if ($this->nodeExists($response->response)) {
            $code = $response->response->result["code"];

            $this->log->info('Received a response to logout: ' . $response->response->result->msg);

            $this->handleReturnCode('Session end', (int)$code);

        } else {
            $this->log->error("No response to logout");
            throw new RuntimeException('No response to logout');
        }
    }

    public function contactsCheck($contacts): array
    {
        $availability = [];

        $xml = $this->render('contact-check', ['contacts' => $contacts]);
        $response = $this->send($xml);
        $handles = "";
        foreach ($contacts as $contact) $handles .= '"' . $contact["handle"] . '", ';
        $handles = rtrim($handles, ', ');
        $this->log->info('contact check sent for ' . $handles);

        if ($this->nodeExists($response->response)) {
            $code = $response->response->result["code"];
            $this->log->info('Received a response to contact check: ' . $response->response->result->msg);

            $this->handleReturnCode('Contact check', (int)$code);
            if (self::RESPONSE_COMPLETED_SUCCESS == $code) {
                $ns = $response->getNamespaces(true);
                $contacts = $response->response->resData->children($ns['contact'])->chkData->cd;
                $logstring = '';
                foreach ($contacts as $contact) {
                    $handle = (string)$contact->id;
                    $avail = (bool)$contact->id->attributes()->avail;
                    $availability[$handle] = $avail;
                    $logstring .= '"' . $handle . '" is ' . ($avail ? '' : 'not ') . 'available, ';
                }
                $logstring = rtrim($logstring, ', ') . '.';
                $this->log->info($logstring);
            }
        } else {
            $this->log->error("No response to logout");
            throw new RuntimeException('No response to logout');
        }

        return $availability;
    }

    public function contactCreate(array $contact)
    {
        $xml = $this->render('contact-create', ['contact' => $contact]);
        $response = $this->send($xml);

        $this->log->info('contact create sent for "' . $contact['handle'] . '"');

        if ($this->nodeExists($response->response)) {
            $code = $response->response->result["code"];
            $this->log->info('Received a response to contact create: ' . $response->response->result->msg);
            $reason = ($this->nodeExists($response->response->result->extValue->reason)) ? $response->response->result->extValue->reason : null;
            $this->handleReturnCode('Contact create', (int)$code, $reason);

        } else {
            $this->log->error('No response to contact create for "' . $contact['handle'] . '"');
            throw new RuntimeException('No response to contact create for "' . $contact['handle'] . '"');
        }
    }

    public function contactUpdate(array $contact)
    {

        $xml = $this->render('contact-update', ['contact' => $contact]);

        $response = $this->send($xml);

        $this->log->info('contact update sent for "' . $contact['handle'] . '"');

        if ($this->nodeExists($response->response)) {
            $code = $response->response->result["code"];
            $this->log->info('Received a response to contact update: ' . $response->response->result->msg);
            $reason = ($this->nodeExists($response->response->result->extValue->reason)) ? $response->response->result->extValue->reason : null;
            $this->handleReturnCode('Contact update', (int)$code, $reason);

        } else {
            $this->log->error('No response to contact update for "' . $contact['handle'] . '"');
            throw new RuntimeException('No response to contact update for "' . $contact['handle'] . '"');
        }

    }
}