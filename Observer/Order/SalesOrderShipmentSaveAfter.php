<?php

namespace Yotpo\Core\Observer\Order;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Class SalesOrderShipmentSaveAfter
 * Observer is called when a shipment is created, so the order is re-synced with fulfillment data
 */
class SalesOrderShipmentSaveAfter extends OrderMain implements ObserverInterface
{
    /**
     * @param Observer $observer
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @return void
     */
    public function execute(Observer $observer)
    {
        $shipment = $observer->getEvent()->getShipment();
        if (!$shipment) {
            return;
        }
        $order = $shipment->getOrder();
        if ($order && $order->getEntityId()) {
            $order->setData(self::SYNCED_TO_YOTPO_ORDER, 0);
            $this->processOrderSync($order);
        }
    }
}
