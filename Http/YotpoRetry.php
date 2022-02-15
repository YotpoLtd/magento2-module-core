<?php

namespace Yotpo\Core\Http;

use Magento\Framework\DataObject;
use Yotpo\Core\Model\Config;
use Magento\Framework\Exception\NoSuchEntityException;
use Exception;

class YotpoRetry
{
    /**
     * @var Config
     */
    protected $config;

    const IS_SUCCESS_KEY = 'is_success';
    const STATUS_KEY = 'status';
    const RESPONSE_KEY = 'response';

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
     * @throws NoSuchEntityException
     * @throws Exception
     */
    public function retryRequest($request) {
        $yotpoRetryAttemptsAmount = $this->config->getYotpoRetryAttemptsAmount();
        $attemptedCount = 0;

        do {
            try {
                $attemptedCount++;
                $requestResult = $request();

                if ($requestResult->getData(self::IS_SUCCESS_KEY)) {
                    return $requestResult;
                } elseif ($this->config->isNetworkRetriableResponse($requestResult->getData(self::STATUS_KEY))) {
                    if ($attemptedCount == $yotpoRetryAttemptsAmount) {
                        return $requestResult;
                    }

                    sleep(1);
                    continue;
                } else {
                    return $requestResult;
                }
            } catch (Exception $exception) {
                if ($attemptedCount == $yotpoRetryAttemptsAmount) {
                    $message = $exception->getMessage();
                    return $this->buildUnsuccessfulResponse($message);
                }

                sleep(1);
                continue;
            }
        } while($attemptedCount < $yotpoRetryAttemptsAmount);

        return $this->buildUnsuccessfulResponse('failed');
    }

    /**
     * @param string $message
     * @return DataObject
     */
    public function buildUnsuccessfulResponse(string $message): DataObject
    {
        $dataObject = new DataObject;
        $dataObject->setData(self::IS_SUCCESS_KEY, false);
        $dataObject->setData(self::RESPONSE_KEY, $message);
        return $dataObject;
    }
}
