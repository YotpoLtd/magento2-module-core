<?php

namespace Yotpo\Core\Http;

use Magento\Framework\DataObject;
use Yotpo\Core\Model\Config;
use Magento\Framework\Exception\NoSuchEntityException;

class YotpoRetry
{
    /**
     * @var Config
     */
    protected $config;

    const IS_SUCCESS_KEY = 'is_success';
    const STATUS_KEY = 'status';

    /**
     * Processor constructor.
     * @param Config $config
     */
    public function __construct(Config $config) {
        $this->config = $config;
    }

    /**
     * Yotpo retry requests mechanism
     *
     * @return DataObject
     */
    public function executeRequest($request)
    {
        $attemptsLeftCount = $this->getMaxAttemptsAmount();

        $attemptsLeftCount--;
        $requestResult = $request();

        while ($this->haveAdditionalAttempts($attemptsLeftCount)) {
            if (!$requestResult->getData(self::IS_SUCCESS_KEY)) {
                if ($this->config->isNetworkRetriableResponse($requestResult->getData(self::STATUS_KEY))) {
                    sleep(1);
                    $attemptsLeftCount--;
                    $requestResult = $request();
                }
            } else {
                break;
            }
        }

        return $requestResult;
    }

    /**
     * @param $attemptsLeftCount
     * @return bool
     */
    private function haveAdditionalAttempts($attemptsLeftCount)
    {
        return $attemptsLeftCount > 0;
    }

    /**
     * @return int
     */
    private function getMaxAttemptsAmount()
    {
        return $this->config->getYotpoRetryAttemptsAmount();
    }
}
