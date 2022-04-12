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
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Yotpo retry requests mechanism
     * @param \Closure $request
     * @return DataObject
     */
    public function executeRequest($request)
    {
        $attemptsLeftCount = $this->getMaxAttemptsAmount();

        $attemptsLeftCount--;
        $requestResult = $request();

        while ($this->haveAdditionalAttempts($attemptsLeftCount)) {
            if ($this->shouldRetryRequest($requestResult)) {
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                sleep(1);
                $attemptsLeftCount--;
                $requestResult = $request();
            } else {
                break;
            }
        }

        return $requestResult;
    }

    /**
     * @param int $attemptsLeftCount
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

    /**
     * @param DataObject $requestResult
     * @return bool
     */
    private function shouldRetryRequest($requestResult)
    {
        return !$requestResult->getData(self::IS_SUCCESS_KEY) &&
               $this->config->isNetworkRetriableResponse($requestResult->getData(self::STATUS_KEY));
    }
}
