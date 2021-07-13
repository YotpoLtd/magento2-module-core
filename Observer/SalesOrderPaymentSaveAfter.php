<?php

namespace Yotpo\Core\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Safe\Exceptions\DatetimeException;
use Yotpo\Core\Model\Sync\Orders\Processor as OrdersProcessor;
use Yotpo\Core\Model\Config;
use Magento\Checkout\Model\Session as CheckoutSession;

/**
 * Class SalesOrderPaymentSaveAfter
 * Observer is called when order is created/updated
 */
class SalesOrderPaymentSaveAfter implements ObserverInterface
{
    /**
     * @var OrdersProcessor
     */
    protected $ordersProcessor;

    /**
     * @var Config
     */
    protected $yotpoConfig;

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * SalesOrderSaveAfter constructor.
     * @param OrdersProcessor $ordersProcessor
     * @param Config $yotpoConfig
     * @param CheckoutSession $checkoutSession
     */
    public function __construct(
        OrdersProcessor $ordersProcessor,
        Config $yotpoConfig,
        CheckoutSession $checkoutSession
    ) {
        $this->ordersProcessor = $ordersProcessor;
        $this->yotpoConfig = $yotpoConfig;
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * @param Observer $observer
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws DatetimeException
     */
    public function execute(Observer $observer)
    {
        $acceptsSmsMarketing = $this->checkoutSession->getYotpoSmsMarketing();
        $order = $observer->getEvent()->getPayment()->getOrder();
        if ($order->getCustomerIsGuest()) {
            $this->ordersProcessor->updateOrderAttribute(
                [$order->getEntityId()],
                'yotpo_accepts_sms_marketing',
                $acceptsSmsMarketing ?: 0
            );
            $this->checkoutSession->setYotpoSmsMarketing(0);
        }
        if ($order && $this->yotpoConfig->isOrdersSyncActive($order->getStoreId())) {
            $this->ordersProcessor->processOrder($order);
        }
    }
}
