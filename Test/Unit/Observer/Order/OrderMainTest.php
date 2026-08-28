<?php

namespace Yotpo\Core\Test\Unit\Observer\Order;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\Orders\Processor as OrdersProcessor;
use Yotpo\Core\Observer\Order\OrderMain;

/**
 * Regression guard for the reQueueOrder()/syncOrderNow() split introduced so the shipment-save
 * observer can defer its real-time sync past commit. SalesOrderPaymentSaveAfter and
 * AdminSalesOrderAddressUpdate both still call processOrderSync() directly and must see
 * identical end-to-end behavior after the split.
 */
class OrderMainTest extends TestCase
{
    /**
     * @param OrdersProcessor $ordersProcessor
     * @param Config $yotpoConfig
     * @return OrderMain
     */
    private function createOrderMain($ordersProcessor, $yotpoConfig)
    {
        $connection = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        return new OrderMain($ordersProcessor, $yotpoConfig, $resourceConnection);
    }

    /**
     * @param int $entityId
     * @return Order
     */
    private function createOrder($entityId = 5)
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($entityId);
        $order->method('getStoreId')->willReturn(1);
        return $order;
    }

    public function testProcessOrderSyncReQueuesAndCallsProcessOrderWhenRealTimeIsActive(): void
    {
        $ordersProcessor = $this->createMock(OrdersProcessor::class);
        $ordersProcessor->expects($this->once())
            ->method('updateOrderAttribute')
            ->with([5], OrderMain::SYNCED_TO_YOTPO_ORDER, 0);
        $ordersProcessor->expects($this->once())->method('processOrder');

        $yotpoConfig = $this->createMock(Config::class);
        $yotpoConfig->method('isRealTimeOrdersSyncActive')->willReturn(true);

        $orderMain = $this->createOrderMain($ordersProcessor, $yotpoConfig);
        $orderMain->processOrderSync($this->createOrder());
    }

    public function testProcessOrderSyncReQueuesButSkipsProcessOrderWhenRealTimeIsOff(): void
    {
        $ordersProcessor = $this->createMock(OrdersProcessor::class);
        $ordersProcessor->expects($this->once())->method('updateOrderAttribute');
        $ordersProcessor->expects($this->never())->method('processOrder');

        $yotpoConfig = $this->createMock(Config::class);
        $yotpoConfig->method('isRealTimeOrdersSyncActive')->willReturn(false);

        $orderMain = $this->createOrderMain($ordersProcessor, $yotpoConfig);
        $orderMain->processOrderSync($this->createOrder());
    }
}
