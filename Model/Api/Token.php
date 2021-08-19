<?php

namespace Yotpo\Core\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Rest\Request;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Http\Yclient;
use Yotpo\Core\Model\Api\Response as YotpoResponse;
use Yotpo\Core\Model\Api\Logger as YotpoApiLogger;
use Magento\Store\Model\ScopeInterface;

/**
 * Class Token - Manage Yotpo auth token
 */
class Token
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
     * @var Response
     */
    protected $yotpoResponse;

    /**
     * @var Logger
     */
    protected $yotpoApiLogger;

    /**
     * Token constructor.
     * @param Config $config
     * @param Yclient $yotpoHttpclient
     * @param Response $yotpoResponse
     */
    public function __construct(
        Config $config,
        Yclient $yotpoHttpclient,
        YotpoResponse  $yotpoResponse,
        YotpoApiLogger $yotpoApiLogger
    ) {
        $this->config = $config;
        $this->yotpoHttpclient = $yotpoHttpclient;
        $this->yotpoResponse = $yotpoResponse;
        $this->yotpoApiLogger = $yotpoApiLogger;
    }

    /**
     * @param int|null $scopeId
     * @param string $scope
     * @return mixed|null
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function createAuthToken($scopeId = null, string $scope = ScopeInterface::SCOPE_STORE)
    {
        file_put_contents(BP.'/var/log/debug-yotpo-api.log', __FILE__.__LINE__.PHP_EOL, FILE_APPEND);
        $token = null;
        $endPoint = $this->config->getConfig('api_url_access_tokens', $scopeId, $scope);
        $data = [
            'secret' => $this->config->getConfig('secret', $scopeId, $scope)
        ];
        $appKey = $this->config->getConfig('app_key', $scopeId, $scope);
        $baseUrl = str_ireplace('{store_id}', $appKey, $this->config->getConfig('api', $scopeId, $scope));
        $options = ['json' => $data];
        file_put_contents(BP.'/var/log/debug-yotpo-api.log', __FILE__.__LINE__.'$baseUrl='.$baseUrl.',$endPoint='.$endPoint.', options='.json_encode($options).PHP_EOL, FILE_APPEND);

        $tokenResponse = $this->yotpoHttpclient->send(
            Request::HTTP_METHOD_POST,
            $baseUrl,
            $endPoint,
            $options
        );
        if ($this->yotpoResponse->validateResponse($tokenResponse)) {
            $response = $tokenResponse->getResponse();
            $token = $response['access_token'];
            if ($token) {
                $this->config->saveConfig('auth_token', $token);
            }
        }
        return $token;
    }
}
