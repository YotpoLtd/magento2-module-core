<?php

namespace Yotpo\Core\Observer\Order;

use Magento\Sales\Model\Order;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\Orders\Processor as OrdersProcessor;
use Magento\Framework\App\ResourceConnection;

/**
 * OrderMain - Main class to manage order changes
 */
class OrderMain
{
    const SYNCED_TO_YOTPO_ORDER = 'synced_to_yotpo_order';

    /**
     * @var OrdersProcessor
     */
    protected $ordersProcessor;

    /**
     * @var Config
     */
    protected $yotpoConfig;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * AdminSalesOrderAddressUpdate constructor.
     * @param OrdersProcessor $ordersProcessor
     * @param Config $yotpoConfig
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        OrdersProcessor $ordersProcessor,
        Config $yotpoConfig,
        ResourceConnection $resourceConnection
    ) {
        $this->ordersProcessor = $ordersProcessor;
        $this->yotpoConfig = $yotpoConfig;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @param Order $order
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @return void
     */
    public function processOrderSync($order)
    {
        $this->ordersProcessor->updateOrderAttribute(
            [$order->getId()],
            self::SYNCED_TO_YOTPO_ORDER,
            0
        );
        $this->updateOrderSyncTable($order->getId());
        if ($this->yotpoConfig->isOrdersSyncActive($order->getStoreId())) {
            $this->ordersProcessor->processOrder($order);
        }
    }

    /**
     * @param int|null $orderId
     * @return void
     */
    public function updateOrderSyncTable($orderId)
    {
        if (!$orderId) {
            return;
        }
        $connection = $this->resourceConnection->getConnection();
        $cond = [
            'order_id = ? ' => $orderId
        ];
        $connection->update(
            $this->resourceConnection->getTableName('yotpo_orders_sync'),
            ['response_code' => Config::CUSTOM_RESPONSE_DATA],
            $cond
        );
    }
}
