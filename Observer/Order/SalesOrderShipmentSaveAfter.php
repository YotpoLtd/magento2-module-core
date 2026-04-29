<?php

namespace Yotpo\Core\Observer\Order;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

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
        $shipment = $observer->getEvent()->getShipment();

        if (!$shipment) {
            return;
        }

        $order = $shipment->getOrder();

        if ($order && $order->getEntityId()) {
            $this->processOrderSync($order);
        }
    }
}
