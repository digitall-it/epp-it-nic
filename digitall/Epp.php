<?php

namespace digitall;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;


/**
 * Class Epp
 * @package digitall
 */
class Epp
{
    /**
     * @var  $_credentials
     * @var Client $_client
     */
    private $_credentials;
    private $client;

    /**
     * Epp constructor.
     * @param $name
     */
    public function __construct($name, $credentials)
    {

        try {
            $this->log = new Logger('log-' . $name);
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/info-' . $name . '.log', Logger::INFO));
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/warning-' . $name . '.log', Logger::WARNING));
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/error-' . $name . '.log', Logger::ERROR));
        } catch (Exception $e) {
            die('Cannot instantiate logger:' . $e->getMessage());
        }


        $this->_credentials = $credentials;

        $this->client = new Client([
            'base_uri' => $this->_credentials["uri"],
            'verify' => false
        ]);

        $tpl_loader = new FilesystemLoader(__DIR__ . '/xml');
        $tpl_cfg = array();
        //$tpl_cfg['cache'] = __DIR__ . '/cache';
        $this->tpl = new Environment($tpl_loader, $tpl_cfg);

        $this->log->info("EPP client created");
    }

    /**
     *
     */
    public function __destruct()
    {
        $this->log->info('EPP client destroyed');
    }

    /**
     * @throws Exception
     */
    public function hello()
    {

//echo $twig->render('index.html', ['name' => 'Fabien']);

        try {
            $xml = $this->tpl->render('hello.xml');
        } catch (LoaderError $e) {
            throw new Exception('Cannot load template file during rendering of hello template: ' . $e->getMessage());
        } catch (RuntimeError $e) {
            throw new Exception('Runtime error during rendering of hello template: ' . $e->getMessage());
        } catch (SyntaxError $e) {
            throw new Exception('Syntax error during rendering of hello template: ' . $e->getMessage());
        };

        $this->log->info("hello sent");

        try {
            /* @var $client Client */
            $res = $this->client->request('POST', '',
                [
                    'body' => $xml
                ]);
        } catch (GuzzleException $e) {
            throw new Exception('Error during transmission: ' . $e->getMessage());
        }

        $xml = simplexml_load_string($res->getBody());
        $greeting = count($xml->greeting) != 0;
        if ($greeting) {
            $this->log->info("Received a greeting response");
        } else {
            //$this->log->error("No greeting response after hello");
            //die('No greeting response after hello');
            throw new Exception("No greeting response after hello");
        }

    }
}