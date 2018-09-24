<?php
namespace Codeception\Lib\Connector;

use Codeception\Exception\ModuleException;
use Codeception\Lib\Connector\ZF3\PersistentServiceManager;
use Doctrine\ORM\EntityManager;
use Symfony\Component\BrowserKit\Client;
use Symfony\Component\BrowserKit\Request;
use Symfony\Component\BrowserKit\Response;
use Zend\Authentication\AuthenticationService;
use Zend\Http\Request as HttpRequest;
use Zend\Http\Headers as HttpHeaders;
use Codeception\Lib\Connector\ZF3\Application;
use Zend\ServiceManager\ServiceManager;
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
    }

    public function setAuthData(array $authData): void
    {
        $this->authData = $authData;
    }

    /**
     * @param Request $request
     *
     * @return Response
     * @throws \Exception
     */
    public function doRequest($request)
    {
        $this->destroyApplication();
        
        if (!empty($this->getInternalRequest()->getServer()['PHP_AUTH_USER'])) {
            $_SERVER['PHP_AUTH_USER'] = $this->getInternalRequest()->getServer()['PHP_AUTH_USER'];
        }

        if (!empty($this->getInternalRequest()->getServer()['PHP_AUTH_PW'])) {
            $_SERVER['PHP_AUTH_PW'] = $this->getInternalRequest()->getServer()['PHP_AUTH_PW'];
        }

        $zendRequest = $this->getApplication()->getRequest();

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

        $this->getApplication()->run();

        // get the response *after* the application has run, because other ZF
        //     libraries like API Agility may *replace* the application's response
        //
        $zendResponse = $this->getApplication()->getResponse();

        $this->zendRequest = $zendRequest;

        $exception = $this->getApplication()->getMvcEvent()->getParam('exception');
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
        $serviceManager = $this->getApplication()->getServiceManager();

        if (!$serviceManager->has($service)) {
            throw new \PHPUnit\Framework\AssertionFailedError("Service $service is not available in container");
        }

        return $serviceManager->get($service);
    }

    public function addServiceToContainer($name, $service)
    {
//        if (!isset($this->persistentServiceManager)) {
//            $serviceManager = $this->getApplication()->getServiceManager();
//
//            $this->persistentServiceManager = new PersistentServiceManager($serviceManager);
//        }
//
//        $this->persistentServiceManager->setAllowOverride(true);
//        $this->persistentServiceManager->setService($name, $service);
//        $this->persistentServiceManager->setAllowOverride(false);

        $this->getApplication()->getServiceManager()->setAllowOverride(true);
        $this->getApplication()->getServiceManager()->setService($name, $service);
    }

    public function destroyApplication()
    {
        $serviceManager = $this->application->getServiceManager();

        /** @var EntityManager $entityManager */
        $entityManager = $serviceManager->get(EntityManager::class);

        $query = $entityManager->getConnection()->prepare('SELECT count(*) as connections FROM pg_stat_activity WHERE pid <> pg_backend_pid() AND state = :state');
        $query->execute([
            'state' => 'idle',
        ]);

        $result = $query->fetchAll()[0];

        $return = [
            'connections' => $result['connections'],
        ];

        $entityManager->getConnection()->close();
        $entityManager->close();

        unset($this->application);
        
        return $return;
    }

    private function createApplication()
    {
        $this->application = Application::init($this->applicationConfig);
        $serviceManager = $this->application->getServiceManager();

        $sendResponseListener = $serviceManager->get('SendResponseListener');
        $events = $this->application->getEventManager();
        $events->detach([$sendResponseListener, 'sendResponse']);
    }

    public function getApplication()
    {
        if (empty($this->application)) {
            $this->createApplication();
        }

        return $this->application;
    }
}
