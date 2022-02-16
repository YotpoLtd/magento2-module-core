<?php
namespace Yotpo\Core\Model\Api;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Http\Yclient;
use Magento\Framework\Webapi\Rest\Request as WebRequest;

/**
 * Class Request - Manage Yotpo API requests
 */
class Request
{

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Yclient
     */
    protected $yotpoHttpclient;

    /**
     * @var Token
     */
    protected $yotpoToken;

    /**
     * @var Response
     */
    protected $yotpoResponse;

    /**
     * @var int
     */
    protected $retryRequestInvalidToken = 2;

    /**
     * Request constructor.
     * @param Config $config
     * @param Yclient $yotpoHttpclient
     * @param Token $yotpoToken
     * @param Response $yotpoResponse
     */
    public function __construct(
        Config $config,
        Yclient $yotpoHttpclient,
        Token $yotpoToken,
        Response $yotpoResponse
    ) {
        $this->config = $config;
        $this->yotpoHttpclient = $yotpoHttpclient;
        $this->yotpoToken = $yotpoToken;
        $this->yotpoResponse = $yotpoResponse;
    }

    /**
     * @param string $method
     * @param string $endPoint
     * @param array<mixed> $data
     * @param string $baseUrlKey
     * @return DataObject
     * @throws NoSuchEntityException|LocalizedException
     */
    public function send(
        string $method,
        string $endPoint,
        array $data = [],
        string $baseUrlKey = 'api',
        $shouldRetry = false
    ): DataObject {
        $appKey = $this->config->getConfig('app_key');
        $baseUrl = str_ireplace('{store_id}', $appKey, $this->config->getConfig($baseUrlKey));

        $options = [];
        if (array_key_exists('entityLog', $data)) {
            $options['entityLog']   =   $data['entityLog'];
            unset($data['entityLog']);
        }
        if ($method == WebRequest::HTTP_METHOD_GET) {
            $options['query']   =   $data;
        } else {
            $options['json']    =   $data;
        }
        $options['headers'] = $this->prepareHeaders();
        $response = $this->yotpoHttpclient->send($method, $baseUrl, $endPoint, $options, $shouldRetry);
        $successFullResponse = $response->getData('is_success');
        if (!$successFullResponse && $this->yotpoResponse->invalidToken($response) && $this->retryRequestInvalidToken) {
            $this->getAuthToken(true); //generate new token
            $this->retryRequestInvalidToken--;
            $this->send($method, $endPoint, $data);
        }
        return $response;
    }

    /**
     * create auth token if not exists
     *
     * @param bool $forceCreate
     * @return string
     * @throws NoSuchEntityException
     */
    public function getAuthToken(bool $forceCreate = false): string
    {
        $token = $this->config->getConfig('auth_token');
        if (!$token || $forceCreate) {
            $token = $this->yotpoToken->createAuthToken();
            $this->config->saveConfig('auth_token', $token);
        }
        return $token ?: '';
    }

    /**
     * @return array<mixed>
     * @throws NoSuchEntityException
     */
    public function prepareHeaders(): array
    {
        $headers = [];
        $headers['X-Yotpo-Token'] = $this->getAuthToken();
        $headers['X-Yotpo-User-Agent'] = 'magento-extension/' . $this->config->getModuleVersion();
        return $headers;
    }

    /**
     * Trigger not v3 APIs
     *
     * @param string $method
     * @param string $endPoint
     * @param array<string, string> $data
     * @return DataObject
     * @throws NoSuchEntityException
     */
    public function sendV1ApiRequest(
        string $method,
        string $endPoint,
        array $data = []
    ): DataObject {
        $appKey = $this->config->getConfig('app_key');
        $baseUrl = $this->config->getConfig('apiV1');
        $endPoint = str_ireplace('{store_id}', $appKey, $endPoint);

        if (array_key_exists('entityLog', $data)) {
            $options['entityLog']   =   $data['entityLog'];
            unset($data['entityLog']);
        }

        if (array_key_exists('utoken', $data)) {
            $data['utoken'] = $this->getAuthToken();
        }

        $options['json']    =   $data;
        $options['headers'] = $this->prepareHeaders();

        $response = $this->yotpoHttpclient->send($method, $baseUrl, $endPoint, $options);
        $successFullResponse = $response->getData('is_success');
        if (!$successFullResponse && $this->yotpoResponse->invalidToken($response) && $this->retryRequestInvalidToken) {
            $this->getAuthToken(true); //generate new token
            $this->retryRequestInvalidToken--;
            $this->sendV1ApiRequest($method, $endPoint, $data);
        }
        return $response;
    }

    /**
     * @return DataObject
     */
    public function getEmptyResponse()
    {
        return $this->yotpoHttpclient->getEmptyResponse();
    }
}
