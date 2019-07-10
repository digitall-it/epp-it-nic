<?php

namespace digitall;

use Exception;
use GuzzleHttp\Client;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;

class Epp
{
    public function __construct($name)
    {

        try {
            $this->log = new Logger('log-' . $name);
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/info-' . $name . '.log', Logger::INFO));
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/warning-' . $name . '.log', Logger::WARNING));
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/error-' . $name . '.log', Logger::ERROR));
        } catch (Exception $e) {
            die('Cannot instantiate logger:' . $e->getMessage());
        }


        $this->credentials = null;

        $tpl_loader = new FilesystemLoader(__DIR__ . '/xml');
        $this->tpl = new Environment($tpl_loader, [
            'cache' => __DIR__ . '/cache',
        ]);

        $this->log->info("epp object created");
    }

    public function __destruct()
    {
        $this->log->info('epp object destroyed');
    }

    public function setCredentials($credentials)
    {
        $this->_credentials = $credentials;

        $this->client = new Client([
            'base_uri' => $credentials["uri"],
            'verify' => false
        ]);

    }

    public function hello()
    {

//echo $twig->render('index.html', ['name' => 'Fabien']);

        try {
            $xml = $this->tpl->render('hello.xml');
        } catch (LoaderError $e) {
            die('Cannot load template during rendering of hello template: ' . $e->getMessage());
        } catch (RuntimeError $e) {
            die('Runtime error during rendering of hello template: ' . $e->getMessage());
        } catch (SyntaxError $e) {
            die('Syntax error during rendering of hello template: ' . $e->getMessage());
        }
        //$client->

        return $xml;

    }
}