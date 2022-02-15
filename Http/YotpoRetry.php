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

    /**
     * Processor constructor.
     * @param Config $config
     */
    public function __construct(Config $config) {
        self::__construct($config);
    }

    const IS_SUCCESS_MESSAGE_KEY = 'is_success';
    const STATUS_CODE_KEY = 'status';
    const RESPONSE_KEY = 'response';

    /**
     * Yotpo retry requests mechanism
     *
     * @return DataObject
     * @throws NoSuchEntityException
     * @throws Exception
     */
    public function retryRequest($request) {
        $numOfAttempts = $this->config->getYotpoRetryAttempts();
        $attempts = 0;

        do {
            try {
                $requestResult = $request();
                if ($requestResult->getData(self::IS_SUCCESS_MESSAGE_KEY)) {
                    return $requestResult;
                } elseif ($this->config->canResync($requestResult->getData(self::STATUS_CODE_KEY))) {
                    $attempts++;
                    continue;
                }
            } catch (Exception $exception) {
                $attempts++;
                if ($attempts == $numOfAttempts) {
                    $dataObject = new DataObject;
                    $dataObject->setData(self::IS_SUCCESS_MESSAGE_KEY, false);
                    $dataObject->setData(self::RESPONSE_KEY, false);
                    return $dataObject;
                }

                sleep(1);
                continue;
            }

            break;
        } while($attempts < $numOfAttempts);

        return null;
    }
}