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
 * Class Plesk
 *
 * @package digitall
 */
class Plesk
{
    /**
     * @var string
     */
    private $uri;

    /**
     * @var Environment Twig template system
     */
    private $tpl;

    /**
     * Plesk constructor.
     *
     * @param $credentials
     *
     * @throws Exception
     */
    public function __construct($credentials)
    {
        $this->log = new Logger('log-plesk');
        //ErrorHandler::register($this->log);
        try {
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/logs/client/debug-plesk.log', Logger::DEBUG));
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/logs/client/info-plesk.log', Logger::INFO));
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/logs/client/warning-plesk.log', Logger::WARNING));
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/logs/client/error-plesk.log', Logger::ERROR));
        } catch (Exception $e) {
            die('Cannot instantiate logger:' . $e->getMessage());
        }


        $this->credentials = $credentials;
        $this->uri = $credentials['uri'];
        $this->key = $credentials['key'];

        $this->client = new Client([
            'base_uri' => $this->credentials['uri'],
            'verify' => false,
            'cookies' => true
        ]);

        $tpl_loader = new FilesystemLoader(__DIR__ . '/tpl/plesk');
        $tpl_cfg = [];
        //$tpl_cfg['cache'] = __DIR__ . '/cache';
        $this->tpl = new Environment($tpl_loader, $tpl_cfg);

        $this->log->info('Plesk client created');

    }

    public function __destruct()
    {
        $this->log->info('Plesk client destroyed');
    }


//    public function createDomain($domain, $customer)
//    {
//        die();
//        $xml = $this->render('domain-create', [
//            'domain' => $domain,
//            'customer' => $customer
//        ]);
//        var_dump($response);
//        //$response = $this->send('/domains',$xml);
//    }
    /**
     * @param string $name
     *
     * @return bool|SimpleXMLElement
     */
    public function getDomain(string $name)
    {
        $xml = $this->render('domain-get', [
            'name' => $name
        ]);
        $response = $this->send($xml);
        $return = false;
        if ($this->nodeExists($response->site->get->result)) $return = $response->site->get->result;
        return $return;
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
     * Sends a message to the server, receives and parses the response to a structure
     *
     * @param string $xml Message to transmit
     *
     * @return SimpleXMLElement Structure to return
     */
    private function send(string $xml, bool $debug = false): SimpleXMLElement
    {
        $this->log->debug('Sending... ' . preg_replace('!\s+!', ' ', $xml));
        file_put_contents(__dir__ . '/logs/plesk/' . microtime(true) . '-send.xml', $xml);
        try {
            /* @var $client Client */
            if (isset($xml)) {
                $res = $this->client->request('POST', '/enterprise/control/agent.php',
                    [
                        'body' => $xml,
                        'headers' => [
                            'KEY' => $this->key
                        ]
                    ]);
            } else {
                throw new \http\Exception\RuntimeException('Empty request');
            }
        } catch (GuzzleException $e) {
            throw new RuntimeException('Error during transmission: ' . $e->getMessage());
        }
        $xml_received = $res->getBody();
        file_put_contents(__dir__ . '/logs/plesk/' . microtime(true) . '-received.xml', $xml_received);
        if ($debug) echo($xml_received);
        $this->log->debug('Receiving... ' . preg_replace('!\s+!', ' ', $xml_received));
        return simplexml_load_string($xml_received);
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
        return (is_countable($obj)) && (count($obj) !== 0);
    }

    /**
     * @param string $name
     * @param array  $domain
     *
     * @return int
     */
    public function createAlias(string $name, array $domain)
    {
        $xml = $this->render('alias-create', [
            'name' => $name,
            'id' => $domain['id']
        ]);
        $response = $this->send($xml);

        if ($response->{'site-alias'}->create->result->status == "ok") {
            $this->log->info('Domain alias "' . $name . '" of domain "' . $domain['name'] . '" created');
            return (int)$response->{'site-alias'}->create->result->id;
        }

        if (
            $response->{'site-alias'}->create->result->status == 'error' &&
            $response->{'site-alias'}->create->result->errcode == '1007'
        ) {
            $this->log->err(
                'Domain alias "' . $name . '" of domain "' . $domain['name'] . '" not created: ' .
                (string)$response->{'site-alias'}->create->result->errtext
            );
            return false;
        }

        throw new RuntimeException(
            'Cannot create site alias, status returned was' .
            $response->{'site-alias'}->create->result->status
        );

    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function deleteAlias(string $name)
    {
        $xml = $this->render('alias-delete', [
            'name' => $name,
        ]);
        $response = $this->send($xml);

        return $response->{'site-alias'}->delete->result->status == 'ok';

    }

    /**
     * @param $domain
     * @param $host
     * @param $type
     * @param $value
     *
     * @return bool|int
     */
    public function addDNSRecord($domain, $host, $type, $value)
    {
        $xml = $this->render('dns-record-add', [
            'site_id' => $domain['id'],
            'host' => $host,
            'type' => $type,
            'value' => $value
        ]);

        $response = $this->send($xml);

        return ($response->dns->add_rec->result->status == 'ok') ? (int)$response->dns->add_rec->result->id : false;
    }

    /**
     * @param $id
     *
     * @return bool
     */
    public function delDNSRecord($id)
    {
        $xml = $this->render('dns-record-del', [
            'id' => $id
        ]);

        $response = $this->send($xml);

        return $response->dns->del_rec->result->status == 'ok';
    }
}