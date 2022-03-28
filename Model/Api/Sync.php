<?php

namespace Yotpo\Core\Model\Api;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Yotpo\Core\Model\Api\Request as YotpoRequest;

/**
 * Class Sync - Sync entities to Yotpo API
 */
class Sync extends YotpoRequest
{

    /**
     * Sync magento entities to Yotpo API
     *
     * @param string $method
     * @param string $url
     * @param array<mixed> $data
     * @param bool $shouldRetry
     * @return DataObject
     * @throws NoSuchEntityException|LocalizedException
     */
    public function sync(string $method, string $url, array $data = [], $shouldRetry = false)
    {
        $data = $this->setEntityLog($data);
        return $this->send($method, $url, $data, 'api', $shouldRetry);
    }

    /**
     * Sync magento entities to Yotpo API using V1 API endpoint
     *
     * @param string $method
     * @param string $url
     * @param array<mixed> $data
     * @throws NoSuchEntityException
     * @return mixed
     */
    public function syncV1(string $method, string $url, array $data = [])
    {
        $data = $this->setEntityLog($data);
        return $this->sendV1ApiRequest($method, $url, $data);
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function setEntityLog(array $data = []): array
    {
        if (array_key_exists('entityLog', $data)) {
            $logHandler = '';
            switch ($data['entityLog']) {
                case 'orders':
                    $logHandler  =  \Yotpo\Core\Model\Sync\Orders\Logger\Handler::class;
                    break;
                case 'general':
                    $logHandler  =   \Yotpo\Core\Model\Logger\General\Handler::class;
                    break;
                case 'catalog':
                    $logHandler  =   \Yotpo\Core\Model\Sync\Catalog\Logger\Handler::class;
                    break;
                default:
                    break;
            }
            $data['entityLog'] = $logHandler;
        }
        return $data;
    }
}
