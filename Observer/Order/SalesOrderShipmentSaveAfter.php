<?php

namespace Yotpo\Core\Observer\Order;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\Orders\Logger as YotpoOrdersLogger;
use Yotpo\Core\Model\Sync\Orders\Processor as OrdersProcessor;

/**
 * Class SalesOrderShipmentSaveAfter
 * Observer is called when a shipment is created, so the order is re-synced with fulfillment data.
 *
 * The re-queue (synced_to_yotpo_order -> 0, response_code -> '000') runs synchronously - it only
 * touches sync bookkeeping. The real-time sync itself is deferred to the shipment's transaction
 * commit callback, because this observer runs on sales_order_shipment_save_after, while the
 * transaction is still open and the shipment's line items are not yet persisted.
 */
class SalesOrderShipmentSaveAfter extends OrderMain implements ObserverInterface
{
    /**
     * @var YotpoOrdersLogger
     */
    protected $yotpoOrdersLogger;

    /**
     * Order ids already scheduled for a deferred real-time sync in this request, so two
     * shipments saved for the same order in one request don't schedule the sync twice.
     *
     * @var array<int|string, bool>
     */
    private $realTimeSyncQueued = [];

    /**
     * @param OrdersProcessor $ordersProcessor
     * @param Config $yotpoConfig
     * @param ResourceConnection $resourceConnection
     * @param YotpoOrdersLogger $yotpoOrdersLogger
     */
    public function __construct(
        OrdersProcessor $ordersProcessor,
        Config $yotpoConfig,
        ResourceConnection $resourceConnection,
        YotpoOrdersLogger $yotpoOrdersLogger
    ) {
        $this->yotpoOrdersLogger = $yotpoOrdersLogger;
        parent::__construct($ordersProcessor, $yotpoConfig, $resourceConnection);
    }

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
        if (!$order || !$order->getEntityId()) {
            return;
        }

        $order->setData(self::SYNCED_TO_YOTPO_ORDER, 0);
        $this->reQueueOrder($order);

        if (!$this->yotpoConfig->isRealTimeOrdersSyncActive($order->getStoreId())) {
            return;
        }

        $orderId = $order->getId();
        if (isset($this->realTimeSyncQueued[$orderId])) {
            return;
        }
        $this->realTimeSyncQueued[$orderId] = true;

        $shipment->getResource()->addCommitCallback(function () use ($order) {
            try {
                $this->syncOrderNow($order);
            } catch (\Throwable $e) {
                $this->yotpoOrdersLogger->errorLog(
                    'Deferred real-time order sync failed - Order ID: ' . $order->getId() .
                    ' - ' . $e->getMessage(),
                    []
                );
            }
        });
    }
}
