<?php

namespace Yotpo\Core\Test\Unit\Observer\Order;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use PHPUnit\Framework\TestCase;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\Orders\Logger as YotpoOrdersLogger;
use Yotpo\Core\Model\Sync\Orders\Processor as OrdersProcessor;
use Yotpo\Core\Observer\Order\SalesOrderShipmentSaveAfter;

/**
 * The shipment-save observer must not read shipment data until the shipment's transaction has
 * actually committed, because sales_order_shipment_save_after fires while sales_shipment_item
 * rows do not exist yet. The re-queue is synchronous (touches only sync bookkeeping); the
 * real-time API call is deferred to a commit callback, with a dedupe guard so two shipments on
 * the same order in one request don't schedule it twice.
 */
class SalesOrderShipmentSaveAfterTest extends TestCase
{
    /**
     * @param OrdersProcessor $ordersProcessor
     * @param Config $yotpoConfig
     * @param YotpoOrdersLogger $logger
     * @return SalesOrderShipmentSaveAfter
     */
    private function createObserver($ordersProcessor, $yotpoConfig, $logger)
    {
        $connection = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        return new SalesOrderShipmentSaveAfter($ordersProcessor, $yotpoConfig, $resourceConnection, $logger);
    }

    /**
     * @param int $entityId
     * @return Order
     */
    private function createOrder($entityId)
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($entityId);
        $order->method('getEntityId')->willReturn($entityId);
        $order->method('getStoreId')->willReturn(1);
        return $order;
    }

    /**
     * @param Order $order
     * @param callable|null &$capturedCallback set to the closure passed to addCommitCallback,
     *        or left untouched if it's never called
     * @return Shipment
     */
    private function createShipment($order, &$capturedCallback = null)
    {
        $resource = $this->createMock(AbstractResource::class);
        $resource->method('addCommitCallback')->willReturnCallback(function ($callback) use (&$capturedCallback) {
            $capturedCallback = $callback;
        });

        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getOrder')->willReturn($order);
        $shipment->method('getResource')->willReturn($resource);
        return $shipment;
    }

    /**
     * @param Shipment $shipment
     * @return Observer
     */
    private function createObserverEvent($shipment)
    {
        $event = new Event(['shipment' => $shipment]);
        return new Observer(['event' => $event]);
    }

    public function testReQueuesSynchronouslyWithoutCallingProcessOrder(): void
    {
        $ordersProcessor = $this->createMock(OrdersProcessor::class);
        $ordersProcessor->expects($this->once())
            ->method('updateOrderAttribute')
            ->with([5], 'synced_to_yotpo_order', 0);
        $ordersProcessor->expects($this->never())->method('processOrder');

        $yotpoConfig = $this->createMock(Config::class);
        $yotpoConfig->method('isRealTimeOrdersSyncActive')->willReturn(true);

        $order = $this->createOrder(5);
        $shipment = $this->createShipment($order);

        $observer = $this->createObserver($ordersProcessor, $yotpoConfig, $this->createMock(YotpoOrdersLogger::class));
        $observer->execute($this->createObserverEvent($shipment));
    }

    public function testCommitCallbackSyncsAfterCommit(): void
    {
        $ordersProcessor = $this->createMock(OrdersProcessor::class);
        $ordersProcessor->expects($this->once())->method('processOrder');

        $yotpoConfig = $this->createMock(Config::class);
        $yotpoConfig->method('isRealTimeOrdersSyncActive')->willReturn(true);

        $order = $this->createOrder(5);
        $capturedCallback = null;
        $shipment = $this->createShipment($order, $capturedCallback);

        $observer = $this->createObserver($ordersProcessor, $yotpoConfig, $this->createMock(YotpoOrdersLogger::class));
        $observer->execute($this->createObserverEvent($shipment));

        $this->assertIsCallable($capturedCallback, 'addCommitCallback() must be called during execute()');
        $capturedCallback();
    }

    public function testRealTimeOffSkipsRegisteringTheCallbackEntirely(): void
    {
        // The setting is checked before registering anything, not inside the deferred
        // callback - no point scheduling work that would just no-op after commit.
        $ordersProcessor = $this->createMock(OrdersProcessor::class);
        $ordersProcessor->expects($this->never())->method('processOrder');

        $yotpoConfig = $this->createMock(Config::class);
        $yotpoConfig->method('isRealTimeOrdersSyncActive')->willReturn(false);

        $order = $this->createOrder(5);
        $capturedCallback = null;
        $shipment = $this->createShipment($order, $capturedCallback);

        $observer = $this->createObserver($ordersProcessor, $yotpoConfig, $this->createMock(YotpoOrdersLogger::class));
        $observer->execute($this->createObserverEvent($shipment));

        $this->assertNull($capturedCallback, 'addCommitCallback() must not be called when real-time sync is off');
    }

    public function testTwoShipmentsForTheSameOrderInOneRequestOnlyScheduleTheSyncOnce(): void
    {
        $ordersProcessor = $this->createMock(OrdersProcessor::class);
        $yotpoConfig = $this->createMock(Config::class);
        $yotpoConfig->method('isRealTimeOrdersSyncActive')->willReturn(true);

        $order = $this->createOrder(5);
        $firstCallback = null;
        $secondCallback = null;
        $firstShipment = $this->createShipment($order, $firstCallback);
        $secondShipment = $this->createShipment($order, $secondCallback);

        $observer = $this->createObserver($ordersProcessor, $yotpoConfig, $this->createMock(YotpoOrdersLogger::class));
        $observer->execute($this->createObserverEvent($firstShipment));
        $observer->execute($this->createObserverEvent($secondShipment));

        $this->assertIsCallable($firstCallback, 'the first shipment must schedule the deferred sync');
        $this->assertNull($secondCallback, 'a second shipment for the same order must not schedule it again');
    }

    public function testDeferredFailureIsCaughtAndLoggedNotPropagated(): void
    {
        $ordersProcessor = $this->createMock(OrdersProcessor::class);
        $ordersProcessor->method('processOrder')->willThrowException(new \RuntimeException('boom'));

        $yotpoConfig = $this->createMock(Config::class);
        $yotpoConfig->method('isRealTimeOrdersSyncActive')->willReturn(true);

        $logger = $this->createMock(YotpoOrdersLogger::class);
        $logger->expects($this->once())
            ->method('errorLog')
            ->with($this->stringContains('boom'));

        $order = $this->createOrder(5);
        $capturedCallback = null;
        $shipment = $this->createShipment($order, $capturedCallback);

        $observer = $this->createObserver($ordersProcessor, $yotpoConfig, $logger);
        $observer->execute($this->createObserverEvent($shipment));

        // Must not throw - the whole point is that a deferred sync failure can't escape into
        // Magento's commit-callback machinery and disrupt anything else.
        $capturedCallback();
    }
}
