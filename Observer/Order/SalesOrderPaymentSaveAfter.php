<?php

namespace Yotpo\Core\Observer\Order;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Yotpo\Core\Model\Sync\Orders\Processor as OrdersProcessor;
use Yotpo\Core\Model\Config;
use Magento\Checkout\Model\Session as CheckoutSession;

/**
 * Class SalesOrderPaymentSaveAfter
 * Observer is called when order is created/updated
 */
class SalesOrderPaymentSaveAfter extends OrderMain implements ObserverInterface
{

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * SalesOrderSaveAfter constructor.
     * @param OrdersProcessor $ordersProcessor
     * @param Config $yotpoConfig
     * @param ResourceConnection $resourceConnection
     * @param CheckoutSession $checkoutSession
     */
    public function __construct(
        OrdersProcessor $ordersProcessor,
        Config $yotpoConfig,
        ResourceConnection $resourceConnection,
        CheckoutSession $checkoutSession
    ) {
        $this->checkoutSession = $checkoutSession;
        parent::__construct($ordersProcessor, $yotpoConfig, $resourceConnection);
    }

    /**
     * @param Observer $observer
     * @throws LocalizedException
     * @throws NoSuchEntityException
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
        if ($order->getEntityId()) {
            $this->processOrderSync($order);
        }
    }
}
