<?php

namespace Codeception\Lib\Connector;

use Exception;
use Laminas\Http\Headers as HttpHeaders;
use Laminas\Http\PhpEnvironment\Request as LaminasRequest;
use Laminas\Http\Request as HttpRequest;
use Laminas\Mvc\Application;
use Laminas\Mvc\ApplicationInterface;
use Laminas\Stdlib\ArrayUtils;
use Laminas\Stdlib\Parameters;
use Laminas\Uri\Http as HttpUri;
use Symfony\Component\BrowserKit\AbstractBrowser as Client;
use Symfony\Component\BrowserKit\Request as BrowserKitRequest;
use Symfony\Component\BrowserKit\Response;

class Laminas extends Client
{
    /** @var ApplicationInterface */
    protected $application;

    protected $applicationConfig = [];

    /** @var LaminasRequest */
    protected $laminasRequest;

    private $persistentServices = [];

    public function setApplicationConfig(array $applicationConfig)
    {
        $this->applicationConfig = $applicationConfig;

        $this->createApplication();
    }

    /**
     * @param BrowserKitRequest $request
     *
     * @return Response
     *
     * @throws Exception
     */
    public function doRequest($request)
    {
        $this->createApplication();

        $laminasRequest = $this->application->getRequest();
        $uri            = new HttpUri($request->getUri());
        $queryString    = $uri->getQuery();
        $method         = \strtoupper($request->getMethod());
        $query          = [];
        $post           = [];
        $content        = $request->getContent();

        if ($queryString) {
            \parse_str($queryString, $query);
        }

        if ($method !== HttpRequest::METHOD_GET) {
            $post = $request->getParameters();
        }

        $laminasRequest->setCookies(new Parameters($request->getCookies()));
        $laminasRequest->setServer(new Parameters($request->getServer()));
        $laminasRequest->setQuery(new Parameters($query));
        $laminasRequest->setPost(new Parameters($post));
        $laminasRequest->setFiles(new Parameters($request->getFiles()));
        $laminasRequest->setContent(\is_null($content) ? '' : $content);
        $laminasRequest->setMethod($method);
        $laminasRequest->setUri($uri);

        $requestUri = $uri->getPath();

        if (!empty($queryString)) {
            $requestUri .= '?' . $queryString;
        }

        $laminasRequest->setRequestUri($requestUri);

        $laminasRequest->setHeaders($this->extractHeaders($request));

        $this->application->run();

        // get the response *after* the application has run, because other Laminas
        //     libraries like API Agility may *replace* the application's response
        //
        $laminasResponse = $this->application->getResponse();

        $this->laminasRequest = $laminasRequest;

        $exception = $this->application->getMvcEvent()->getParam('exception');
        if ($exception instanceof Exception) {
            throw $exception;
        }

        return new Response(
            $laminasResponse->getBody(),
            $laminasResponse->getStatusCode(),
            $laminasResponse->getHeaders()->toArray()
        );
    }

    public function getLaminasRequest()
    {
        return $this->laminasRequest;
    }

    /**
     * @param string $service
     *
     * @return mixed
     */
    public function grabServiceFromContainer($service)
    {
        $serviceManager = $this->application->getServiceManager();

        if (!$serviceManager->has($service)) {
            throw new \PHPUnit\Framework\AssertionFailedError("Service $service is not available in container");
        }

        return $serviceManager->get($service);
    }

    public function persistService($name)
    {
        $service                         = $this->grabServiceFromContainer($name);
        $this->persistentServices[$name] = $service;
    }

    /**
     * @param $name
     * @param mixed  $service
     *
     * @return void
     */
    public function addServiceToContainer($name, $service)
    {
        $this->application->getServiceManager()->setAllowOverride(true);
        $this->application->getServiceManager()->setService($name, $service);
        $this->application->getServiceManager()->setAllowOverride(false);

        $this->persistentServices[$name] = $service;
    }

    private function extractHeaders(BrowserKitRequest $request)
    {
        $headers        = [];
        $server         = $request->getServer();
        $contentHeaders = ['Content-Length' => true, 'Content-Md5' => true, 'Content-Type' => true];

        foreach ($server as $header => $val) {
            $header = \html_entity_decode(
                \implode(
                    '-',
                    \array_map(
                        'ucfirst',
                        \explode(
                            '-',
                            \strtolower(\str_replace('_', '-', $header))
                        )
                    )
                ),
                ENT_NOQUOTES
            );

            if (\strpos($header, 'Http-') === 0) {
                $headers[\substr($header, 5)] = $val;
            } elseif (isset($contentHeaders[$header])) {
                $headers[$header] = $val;
            }
        }

        $httpHeaders = new HttpHeaders();
        $httpHeaders->addHeaders($headers);

        return $httpHeaders;
    }

    private function createApplication()
    {
        $this->application = Application::init(
            ArrayUtils::merge(
                $this->applicationConfig,
                [
                    'service_manager' => [
                        'services' => $this->persistentServices
                    ]
                ]
            )
        );

        $serviceManager       = $this->application->getServiceManager();
        $sendResponseListener = $serviceManager->get('SendResponseListener');
        $events               = $this->application->getEventManager();

        $events->detach([$sendResponseListener, 'sendResponse']);
    }
}
