<?php
namespace Codeception\Lib\Connector;

use Codeception\Exception\ModuleException;
use Codeception\Lib\Connector\ZF2\PersistentServiceManager;
use Symfony\Component\BrowserKit\Client;
use Symfony\Component\BrowserKit\Request;
use Symfony\Component\BrowserKit\Response;
use Zend\Authentication\AuthenticationService;
use Zend\Http\Request as HttpRequest;
use Zend\Http\Headers as HttpHeaders;
use Zend\Mvc\Application;
use Zend\Stdlib\Parameters;
use Zend\Uri\Http as HttpUri;
use Symfony\Component\BrowserKit\Request as BrowserKitRequest;

class ZF3 extends Client
{
    /**
     * @var \Zend\Mvc\ApplicationInterface
     */
    protected $application;

    /**
     * @var array
     */
    protected $applicationConfig;

    /**
     * @var  \Zend\Http\PhpEnvironment\Request
     */
    protected $zendRequest;

    /**
     * @var PersistentServiceManager
     */
    private $persistentServiceManager;
    /**
     * @var array
     */
    protected $authData;

    /**
     * @param array $applicationConfig
     */
    public function setApplicationConfig($applicationConfig)
    {
        $this->applicationConfig = $applicationConfig;
        $this->createApplication();
    }

    public function setAuthData(array $authData): void
    {
        $this->authData = $authData;
    }

    public function getApplication()
    {
        return $this->application;
    }

    /**
     * @param Request $request
     *
     * @return Response
     * @throws \Exception
     */
    public function doRequest($request)
    {
        if (!empty($this->getInternalRequest()->getServer()['PHP_AUTH_USER'])) {
            $_SERVER['PHP_AUTH_USER'] = $this->getInternalRequest()->getServer()['PHP_AUTH_USER'];
        }

        if (!empty($this->getInternalRequest()->getServer()['PHP_AUTH_PW'])) {
            $_SERVER['PHP_AUTH_PW'] = $this->getInternalRequest()->getServer()['PHP_AUTH_PW'];
        }

        $this->createApplication();
        $zendRequest = $this->application->getRequest();

        $uri = new HttpUri($request->getUri());
        $queryString = $uri->getQuery();
        $method = strtoupper($request->getMethod());

        $zendRequest->setCookies(new Parameters($request->getCookies()));

        $query = [];
        $post = [];
        $content = $request->getContent();
        if ($queryString) {
            parse_str($queryString, $query);
        }

        if ($method !== HttpRequest::METHOD_GET) {
            $post = $request->getParameters();
        }

        $zendRequest->setServer(new Parameters($this->getInternalRequest()->getServer()));
        $zendRequest->setQuery(new Parameters($query));
        $zendRequest->setPost(new Parameters($post));
        $zendRequest->setFiles(new Parameters($request->getFiles()));
        $zendRequest->setContent($content);
        $zendRequest->setMethod($method);
        $zendRequest->setUri($uri);
        $requestUri = $uri->getPath();
        if (!empty($queryString)) {
            $requestUri .= '?' . $queryString;
        }

        $zendRequest->setRequestUri($requestUri);

        $zendRequest->setHeaders($this->extractHeaders($request));

        $this->application->run();

        // get the response *after* the application has run, because other ZF
        //     libraries like API Agility may *replace* the application's response
        //
        $zendResponse = $this->application->getResponse();

        $this->zendRequest = $zendRequest;

        $exception = $this->application->getMvcEvent()->getParam('exception');
        if ($exception instanceof \Exception) {
            throw $exception;
        }

        $response = new Response(
            $zendResponse->getBody(),
            $zendResponse->getStatusCode(),
            $zendResponse->getHeaders()->toArray()
        );

        return $response;
    }

    /**
     * @return \Zend\Http\PhpEnvironment\Request
     */
    public function getZendRequest()
    {
        return $this->zendRequest;
    }

    private function extractHeaders(BrowserKitRequest $request)
    {
        $headers = [];
        $server = $request->getServer();

        $contentHeaders = ['Content-Length' => true, 'Content-Md5' => true, 'Content-Type' => true];
        foreach ($server as $header => $val) {
            $header = html_entity_decode(implode('-', array_map('ucfirst', explode('-', strtolower(str_replace('_', '-', $header))))), ENT_NOQUOTES);

            if (strpos($header, 'Http-') === 0) {
                $headers[substr($header, 5)] = $val;
            } elseif (isset($contentHeaders[$header])) {
                $headers[$header] = $val;
            }
        }
        $zendHeaders = new HttpHeaders();
        $zendHeaders->addHeaders($headers);
        return $zendHeaders;
    }

    public function grabServiceFromContainer($service)
    {
        $serviceManager = $this->application->getServiceManager();

        if (!$serviceManager->has($service)) {
            throw new \PHPUnit\Framework\AssertionFailedError("Service $service is not available in container");
        }

        if ($service === 'Doctrine\ORM\EntityManager' && !isset($this->persistentServiceManager)) {
            if (!method_exists($serviceManager, 'addPeeringServiceManager')) {
                throw new ModuleException('Codeception\Module\ZF2', 'integration with Doctrine2 module is not compatible with ZF3');
            }
            $this->persistentServiceManager = new PersistentServiceManager($serviceManager);
        }

        return $serviceManager->get($service);
    }

    public function addServiceToContainer($name, $service)
    {
        if (!isset($this->persistentServiceManager)) {
            $serviceManager = $this->application->getServiceManager();
            if (!method_exists($serviceManager, 'addPeeringServiceManager')) {
                throw new ModuleException('Codeception\Module\ZF2', 'addServiceToContainer method is not compatible with ZF3');
            }
            $this->persistentServiceManager = new PersistentServiceManager($serviceManager);
            $serviceManager->addPeeringServiceManager($this->persistentServiceManager);
            $serviceManager->setRetrieveFromPeeringManagerFirst(true);
        }
        $this->persistentServiceManager->setAllowOverride(true);
        $this->persistentServiceManager->setService($name, $service);
        $this->persistentServiceManager->setAllowOverride(false);
    }

    private function createApplication()
    {
        $this->application = Application::init($this->applicationConfig);
        $serviceManager = $this->application->getServiceManager();

        if (isset($this->persistentServiceManager)) {
            $serviceManager->addPeeringServiceManager($this->persistentServiceManager);
            $serviceManager->setRetrieveFromPeeringManagerFirst(true);
        }

        $sendResponseListener = $serviceManager->get('SendResponseListener');
        $events = $this->application->getEventManager();
        if (class_exists('Zend\EventManager\StaticEventManager')) {
            $events->detach($sendResponseListener); //ZF2
        } else {
            $events->detach([$sendResponseListener, 'sendResponse']); //ZF3
        }
    }

//    public function authentication()
//    {
//        if (!empty($this->authData)) {
//            $serviceManager = $this->application->getServiceManager();
//
//            /** @var \Zend\Authentication\AuthenticationService $auth */
//            $authService = $serviceManager->get(AuthenticationService::class);
//            $authService->clearIdentity();
//
//            /** @var \Users\Authentication\Adapter\AdapterChain $adapters */
//            $adapters = $authService->getAdapter();
//            $adapters->authenticate();
//
//            echo "<pre>";
//            echo "<b>".__FILE__."</b><br/>";
//            var_dump($adapters->authenticate());
//            echo "</pre>";
//            die();
//
//            $authService->getAdapter()
//                ->setIdentity($this->authData['identity'])
//                ->setCredential($this->authData['credential']);
//            $result = $authService->authenticate();
//
//            echo "<pre>";
//            echo "<b>".__FILE__."</b><br/>";
//            var_dump($result);
//            echo "</pre>";
//            die();
//
//            if (!$result->isValid()) {
//                throw new ModuleException(sprintf('User "%s" is not authorized', $this->authData['identity']));
//            }
//        }
//    }
}
