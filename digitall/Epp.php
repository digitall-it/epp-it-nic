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
        try {
            $xml = $this->tpl->render($template . '.xml', $vars);
        } catch (LoaderError $e) {
            throw new RuntimeException('Cannot load template file during rendering of ' . $template . ' template: ' . $e->getMessage());
        } catch (RuntimeError $e) {
            throw new RuntimeException('Runtime error during rendering of ' . $template . ' template: ' . $e->getMessage());
        } catch (SyntaxError $e) {
            throw new RuntimeException('Syntax error during rendering of ' . $template . ' template: ' . $e->getMessage());
        }
        return $xml;
    }

    public function nodeExists($obj): bool
    {
        return count($obj) !== 0;
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

            switch ($code) {
                case self::RESPONSE_COMPLETED_SUCCESS:
//                    $this->svTRID = (string)$response->response->trID->svTRID;
//                    $this->log->info('Server transaction ID set to ' . $this->svTRID);
                    $this->log->info('Session started.');
                    if ($newpassword !== null) $this->log->info('Password changed ' . (($testpassword !== null) ? 'back ' : '') . 'to "' . $newpassword . '"');
                    break;
                case self::RESPONSE_FAILED_AUTH_ERROR:
                    $this->log->err('Wrong credentials.');
                    break;
                case self::RESPONSE_FAILED_COMMAND_USE_ERROR:
                    $this->log->err('Invalid command.');
                    break;
                case self::RESPONSE_FAILED_SESSION_LIMIT_EXCEEDED:
                    $this->log->err('Session limit exceeded, server closing connection. Try again later.');
                    throw new RuntimeException ('Session limit exceeded, server closing connection. Try again later.');
                    break;
                default:
                    throw new RuntimeException('Unhandled return code ' . $code);
                    break;
            }
        } else {
            $this->log->error("No response to login");
            throw new RuntimeException('No response to login');
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

            switch ($code) {
                case self::RESPONSE_COMPLETED_END_SESSION:
//                    $this->svTRID = null;
                    $this->log->info('Session ended.');
                    break;
                case self::RESPONSE_FAILED_COMMAND_USE_ERROR:
                    $this->log->err('Invalid command.');
                    break;
                default:
                    throw new RuntimeException('Unhandled return code ' . $code);
                    break;
            }
        } else {
            $this->log->error("No response to logout");
            throw new RuntimeException('No response to logout');
        }
    }

    public function contactsCheck($contacts): array
    {
        $availability = [];

        $xml = $this->render('contact-check', ['contacts' => $contacts, 'clTRID' => $this->set_clTRID('DGT')]);
        $response = $this->send($xml);
        $handles = "";
        foreach ($contacts as $contact) $handles .= '"' . $contact["handle"] . '", ';
        $handles = rtrim($handles, ', ');
        $this->log->info('contact check sent for ' . $handles);

        if ($this->nodeExists($response->response)) {
            $code = $response->response->result["code"];
            $this->log->info('Received a response to contact check: ' . $response->response->result->msg);

            switch ($code) {
                case self::RESPONSE_COMPLETED_SUCCESS:
                    $this->log->info('Contact handles checked.');
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

                    //$this->log->info('Received a response to contact check: ' . $response->response->result->msg);
                    $this->log->info($logstring);

                    break;
                case self::RESPONSE_FAILED_COMMAND_USE_ERROR:
                    $this->log->err('Invalid command.');
                    break;
                default:
                    throw new RuntimeException('Unhandled return code ' . $code);
                    break;
            }
        } else {
            $this->log->error("No response to logout");
            throw new RuntimeException('No response to logout');
        }

        return $availability;
    }

    public function set_clTRID($prefix)
    {
        $this->clTRID = $prefix . "-" . time() . "-" . substr(md5(mt_rand()), 0, 5);
        if (strlen($this->clTRID) > 32)
            $this->clTRID = substr($this->clTRID, -32);
        return $this->clTRID;
    }
}