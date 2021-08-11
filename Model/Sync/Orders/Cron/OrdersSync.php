<?php

namespace Yotpo\Core\Model\Sync\Orders\Cron;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Yotpo\Core\Model\Sync\Orders\Processor as OrdersProcessor;

/**
 * Class OrdersSync - Process orders using cron job
 */
class OrdersSync
{
    /**
     * @var OrdersProcessor
     */
    protected $ordersProcessor;

    /**
     * OrdersSync constructor.
     * @param OrdersProcessor $ordersProcessor
     */
    public function __construct(
        OrdersProcessor $ordersProcessor
    ) {
        $this->ordersProcessor = $ordersProcessor;
    }

    /**
     * Process orders sync
     *
     * @return void
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function processOrders()
    {
        $this->ordersProcessor->process();
    }
}
