<?php

namespace Yotpo\Core\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ResponseFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Webapi\Rest\Request;
use Yotpo\Core\Model\Api\Logger as YotpoApiLogger;
use Yotpo\Core\Model\Api\Response as YotpoResponse;

/**
 * Class Yclient to manage API client communication
 */
class Yclient
{

    /**
     * @var ResponseFactory
     */
    protected $responseFactory;

    /**
     * @var ClientFactory
     */
    protected $clientFactory;

    /**
     * @var YotpoResponse
     */
    protected $yotpoResponse;

    /**
     * @var YotpoApiLogger
     */
    protected $yotpoApiLogger;

    /**
     * @var array <mixed>
     */
    protected $handlers = [];

    /**
     * Yclient constructor.
     * @param ClientFactory $clientFactory
     * @param ResponseFactory $responseFactory
     * @param YotpoResponse $yotpoResponse
     * @param YotpoApiLogger $yotpoApiLogger
     */
    public function __construct(
        ClientFactory $clientFactory,
        ResponseFactory $responseFactory,
        YotpoResponse $yotpoResponse,
        YotpoApiLogger $yotpoApiLogger
    ) {
        $this->clientFactory = $clientFactory;
        $this->responseFactory = $responseFactory;
        $this->yotpoResponse = $yotpoResponse;
        $this->yotpoApiLogger = $yotpoApiLogger;
    }

    /**
     * Do API request with provided params
     *
     * @param string $baseUrl
     * @param string $uriEndpoint
     * @param array<mixed> $options
     * @param string $requestMethod
     *
     * @return mixed
     */
    private function doRequest(
        string $baseUrl,
        string $uriEndpoint,
        array $options = [],
        string $requestMethod = Request::HTTP_METHOD_GET
    ) {
        $this->setCustomLogHandler($options);
        if (array_key_exists('entityLog', $options)) {
            unset($options['entityLog']);
        }

        /** @var Client $client */
        $client = $this->clientFactory->create(['config' => [
            'base_uri' => $baseUrl
        ]]);
        try {
            $logMessage = 'API Log';
            $logData = [];
            $logData[] = 'API URL = ' . $baseUrl . $uriEndpoint;
            $logData[] = 'API METHOD = ' . $requestMethod;
            $logData[] = $options;
            $this->yotpoApiLogger->info($logMessage, $logData);
            $logData = [];
            $response = $client->request(
                $requestMethod,
                $uriEndpoint,
                $options
            );
            $responseBody = $response->getBody();
            $logData[] = 'response code = ' . $response->getStatusCode();
            $responseContent = $responseBody->getContents();
            $responseBody->rewind();
            $logData[] = 'response = ' . $responseContent;
            $this->yotpoApiLogger->info($logMessage, $logData);
        } catch (GuzzleException $exception) {
            /** @var Response $response */
            $response = $this->responseFactory->create([
                'status' => $exception->getCode(),
                'reason' => $exception->getMessage()
            ]);
            $exceptionData = [];
            $exceptionMessage = 'API Error';
            $exceptionData[] = 'API URL = ' . $baseUrl . $uriEndpoint;
            $exceptionData[] = $options;
            $exceptionData[] = 'response code = ' . $exception->getCode();
            $exceptionData[] = 'response = ' . $exception->getMessage();
            $this->yotpoApiLogger->info($exceptionMessage, $exceptionData);
        }
        return $response;
    }

    /**
     * @param string $method
     * @param string $baseUrl
     * @param string $uriEndpoint
     * @param array<mixed> $options
     * @return mixed
     */
    public function send(
        string $method,
        string $baseUrl,
        string $uriEndpoint,
        Array $options = []
    ) {
        $response = $this->doRequest($baseUrl, $uriEndpoint, $options, $method);
        $status = $response->getStatusCode();
        $responseBody = $response->getBody();
        $responseReason = $response->getReasonPhrase();
        $responseContent = $responseBody->getContents();
        $this->yotpoApiLogger->info($responseContent, []);
        $responseBody->rewind();
        $responseObject = new DataObject();
        $responseObject->setData('status', $status);
        $responseObject->setData(
            'is_success',
            $this->yotpoResponse->validateResponse($responseObject)
        );
        $responseObject->setData('reason', $responseReason);
        $responseObject->setData('response', json_decode($responseContent, true));
        return $responseObject;
    }

    /**
     * Set custom handler for each entity
     * @param array <mixed> $options
     * @return void
     */
    public function setCustomLogHandler(array $options)
    {
        $customHandlerClass = \Yotpo\Core\Model\Api\Logger\Handler::class;
        $handlerInstance    = '';
        if (array_key_exists('entityLog', $options)) {
            $customHandlerClass = $options['entityLog'];
        }
        foreach ($this->yotpoApiLogger->getHandlers() as $handler) {
            if (get_class($handler) == $customHandlerClass) {
                $handlerInstance = $handler;
            }
            $this->handlers[get_class($handler)] = $handler;
        }
        if (!$handlerInstance && isset($this->handlers[$customHandlerClass])) {
            $handlerInstance = $this->handlers[$customHandlerClass];
        }
        if ($handlerInstance) {
            $this->yotpoApiLogger->setHandlers([$handlerInstance]);
        }
    }

    /**
     * @return DataObject
     */
    public function getEmptyResponse()
    {
        $responseObject = new DataObject();
        $responseObject->setData('status', null);
        $responseObject->setData('reason', null);
        $responseObject->setData('response', []);
        $responseObject->setData('is_success', false);
        return $responseObject;
    }
}
