<?php

namespace Yotpo\Core\Observer\Order;

use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Yotpo\Core\Model\Sync\Orders\Processor as OrdersProcessor;
use Yotpo\Core\Model\Config;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\State as AppState;

/**
 * Class SalesOrderShipmentSaveAfter
 * Observer is called when shipment is created/updated
 */
class SalesOrderShipmentSaveAfter extends OrderMain implements ObserverInterface
{
    /**
     * @param Observer $observer
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getShipment()->getOrder();

        if ($order->getEntityId()) {
            $this->processOrderSync($order);
        }
    }
}
