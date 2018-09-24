<?php

namespace Codeception\Module;

use Codeception\Lib\Framework;
use Codeception\TestInterface;
use Codeception\Configuration;
use Codeception\Lib\Interfaces\DoctrineProvider;
use Codeception\Lib\Interfaces\PartedModule;
use Codeception\Util\ReflectionHelper;
use Doctrine\ORM\EntityManager;
use Zend\Console\Console;
use Zend\EventManager\StaticEventManager;
use Codeception\Lib\Connector\ZF3 as ZF3Connector;

/**
 * This module allows you to run tests inside Zend Framework 2 and Zend Framework 3.
 *
 * File `init_autoloader` in project's root is required by Zend Framework 2.
 * Uses `tests/application.config.php` config file by default.
 *
 * Note: services part and Doctrine integration is not compatible with ZF3 yet
 *
 * ## Status
 *
 * * Maintainer: **Naktibalda**
 * * Stability: **stable**
 *
 * ## Config
 *
 * * config: relative path to config file (default: `tests/application.config.php`)
 *
 * ## Public Properties
 *
 * * application -  instance of `\Zend\Mvc\ApplicationInterface`
 * * db - instance of `\Zend\Db\Adapter\AdapterInterface`
 * * client - BrowserKit client
 *
 * ## Parts
 *
 * * services - allows to use grabServiceFromContainer and addServiceToContainer with WebDriver or PhpBrowser modules.
 *
 * Usage example:
 *
 * ```yaml
 * actor: AcceptanceTester
 * modules:
 *     enabled:
 *         - ZF3:
 *             part: services
 *         - Doctrine2:
 *             depends: ZF2
 *         - WebDriver:
 *             url: http://your-url.com
 *             browser: phantomjs
 * ```
 */
class ZF3 extends Framework implements DoctrineProvider, PartedModule
{
    protected $config
        = [
            'config' => 'tests/application.config.php',
        ];

    /**
     * @var \Zend\Mvc\ApplicationInterface
     */
    public $application;

    /**
     * @var \Zend\Db\Adapter\AdapterInterface
     */
    public $db;

    /**
     * @var \Codeception\Lib\Connector\ZF3
     */
    public $client;

    protected $peeringServiceManager;

    protected $applicationConfig;

    protected $queries = 0;
    protected $time = 0;

    /**
     * @var array Used to collect domains while recursively traversing route tree
     */
    private $domainCollector = [];

    public function createClient()
    {
        if (! empty($this->client)) {
            $result = $this->client->destroyApplication();

            $this->debug(
                sprintf(
                    '[ZF3] Destroyed application, stats: %s',
                    var_export($result, true)));

            unset($this->client);
        }

        $this->debug('[ZF3] Application created');

        $this->client = new ZF3Connector();
        $this->client->setApplicationConfig($this->applicationConfig);
    }

    public function _initialize()
    {
        $initAutoloaderFile = Configuration::projectDir()
            . 'init_autoloader.php';
        if (file_exists($initAutoloaderFile)) {
            require $initAutoloaderFile;
        }

        $this->applicationConfig = require Configuration::projectDir()
            . $this->config['config'];
        if (isset($this->applicationConfig['module_listener_options']['config_cache_enabled'])) {
            $this->applicationConfig['module_listener_options']['config_cache_enabled']
                = false;
        }
        Console::overrideIsConsole(false);

        $this->createClient();
    }

    public function _before(TestInterface $test)
    {
        $this->createClient();

        $_SERVER['REQUEST_URI'] = '';
    }

    public function _after(TestInterface $test)
    {
        $_SESSION = [];
        $_GET     = [];
        $_POST    = [];
        $_COOKIE  = [];

        if (! empty($this->client)) {
            $result = $this->client->destroyApplication();

            $this->debug(
                sprintf(
                    '[ZF3] Destroyed application, stats: %s',
                    var_export($result, true)));

            unset($this->client);
        }

        parent::_after($test);
    }

    public function _afterSuite()
    {
        if (! empty($this->client)) {
            $result = $this->client->destroyApplication();

            $this->debug(
                sprintf(
                    '[ZF3] Destroyed application, stats: %s',
                    var_export($result, true)));
        }

        $this->debug('[ZF3] Application destroyed');

        unset($this->client);
    }

    public function _getEntityManager()
    {
        if (! $this->client) {
            $this->createClient();
        }

        return $this->grabServiceFromContainer(EntityManager::class);
    }

    /**
     * Grabs a service from ZF2 container.
     * Recommended to use for unit testing.
     *
     * ``` php
     * <?php
     * $em = $I->grabServiceFromContainer('Doctrine\ORM\EntityManager');
     * ?>
     * ```
     *
     * @param $service
     *
     * @return mixed
     * @part services
     */
    public function grabServiceFromContainer($service)
    {
        return $this->client->grabServiceFromContainer($service);
    }

    /**
     * Adds service to ZF2 container
     *
     * @param string $name
     * @param object $service
     *
     * @part services
     */
    public function addServiceToContainer($name, $service)
    {
        $this->client->addServiceToContainer($name, $service);
    }

    /**
     * Opens web page using route name and parameters.
     *
     * ``` php
     * <?php
     * $I->amOnRoute('posts.create');
     * $I->amOnRoute('posts.show', array('id' => 34));
     * ?>
     * ```
     *
     * @param       $routeName
     * @param array $params
     */
    public function amOnRoute($routeName, array $params = [])
    {
        $router = $this->client->grabServiceFromContainer('router');

        $url = $router->assemble($params, ['name' => $routeName]);

        $this->amOnPage($url);
    }

    /**
     * Checks that current url matches route.
     *
     * ``` php
     * <?php
     * $I->seeCurrentRouteIs('posts.index');
     * $I->seeCurrentRouteIs('posts.show', ['id' => 8]));
     * ?>
     * ```
     *
     * @param       $routeName
     * @param array $params
     */
    public function seeCurrentRouteIs($routeName, array $params = [])
    {
        $router = $this->client->grabServiceFromContainer('router');
        $url    = $router->assemble($params, ['name' => $routeName]);
        $this->seeCurrentUrlEquals($url);
    }

    protected function getInternalDomains()
    {
        /**
         * @var Zend\Mvc\Router\Http\TreeRouteStack
         */
        $router                = $this->client->grabServiceFromContainer(
            'router');
        $this->domainCollector = [];
        $this->addInternalDomainsFromRoutes($router->getRoutes());

        return array_unique($this->domainCollector);
    }

    private function addInternalDomainsFromRoutes($routes)
    {
        foreach ($routes as $name => $route) {
            if ($route instanceof \Zend\Mvc\Router\Http\Hostname
                || $route instanceof \Zend\Router\Http\Hostname) {
                $this->addInternalDomain($route);
            } elseif ($route instanceof \Zend\Mvc\Router\Http\Part
                || $route instanceof \Zend\Router\Http\Part) {
                $parentRoute = ReflectionHelper::readPrivateProperty(
                    $route, 'route');
                if ($parentRoute instanceof \Zend\Mvc\Router\Http\Hostname
                    || $parentRoute instanceof \Zend\Mvc\Router\Http\Hostname) {
                    $this->addInternalDomain($parentRoute);
                }
                // this is necessary to instantiate child routes
                try {
                    $route->assemble([], []);
                } catch (\Exception $e) {
                }
                $this->addInternalDomainsFromRoutes($route->getRoutes());
            }
        }
    }

    private function addInternalDomain($route)
    {
        $regex                    = ReflectionHelper::readPrivateProperty(
            $route, 'regex');
        $this->domainCollector [] = '/^' . $regex . '$/';
    }

    public function _parts()
    {
        return ['services'];
    }
}
