<?php

namespace Yotpo\Core\Test\Unit\Model\Sync\Orders;

use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\Orders\Data as OrdersData;
use Yotpo\Core\Model\Sync\Orders\Logger as YotpoOrdersLogger;
use Yotpo\Core\Model\Sync\Orders\Processor;

/**
 * Real-time sync is an optimisation, not the authoritative source of truth. Only the cron path
 * (processOrders()) may record a terminal, non-retryable response code for an order - a
 * real-time attempt (processSingleEntity(), reached via the shipment/payment/address-update
 * observers) must leave the order queued for cron's own attempt instead, since real-time runs at
 * an inherently less reliable moment.
 */
class ProcessorTest extends TestCase
{
    /**
     * Stubs everything processSingleEntity() reaches out to except the write-guard logic under
     * test. getYotpoSyncedOrders() returns [] (a brand-new order, never synced before) so the
     * "already-stored terminal code" branch is not exercised here - this test is about the
     * response from THIS attempt.
     *
     * @param string $responseCode
     * @return Processor&MockObject
     */
    private function createProcessor($responseCode)
    {
        $processor = $this->getMockBuilder(Processor::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'checkMissingProducts',
                'getYotpoSyncedOrders',
                'syncOrder',
                'prepareYotpoTableData',
                'updateOrderAttribute',
                'insertOrUpdateYotpoTableData',
                'updateLastSyncDate',
                'updateTotalOrdersSynced',
            ])
            ->getMock();

        $processor->method('checkMissingProducts')->willReturn(false);
        $processor->method('getYotpoSyncedOrders')->willReturn([]);
        $processor->method('syncOrder')->willReturn(new \Magento\Framework\DataObject(['is_success' => true]));
        $processor->method('prepareYotpoTableData')->willReturn([
            'response_code' => $responseCode,
            'yotpo_id' => null,
        ]);

        $data = $this->createMock(OrdersData::class);
        $data->method('getMappedOrderStatuses')->willReturn(['processing' => 'pending']);

        // canResync()/canUpdateCustomAttribute() are left real - they are the exact logic this
        // fix has to interact with correctly, not something to mock away.
        $config = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isRealTimeOrdersSyncActive'])
            ->getMock();
        $config->method('isRealTimeOrdersSyncActive')->willReturn(true);

        (new \ReflectionProperty(Processor::class, 'data'))->setValue($processor, $data);
        (new \ReflectionProperty(Processor::class, 'config'))->setValue($processor, $config);
        (new \ReflectionProperty(Processor::class, 'yotpoOrdersLogger'))
            ->setValue($processor, $this->createMock(YotpoOrdersLogger::class));

        return $processor;
    }

    /**
     * @return Order&MockObject
     */
    private function createOrder()
    {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(7);
        $order->method('getId')->willReturn(7);
        $order->method('getStatus')->willReturn('processing');
        $order->method('getCustomerId')->willReturn(null);
        $order->method('getCouponCode')->willReturn(null);
        return $order;
    }

    public function testSuccessIsPersistedAndOrderMarkedSynced(): void
    {
        $processor = $this->createProcessor('200');
        $processor->expects($this->once())->method('insertOrUpdateYotpoTableData');
        $processor->expects($this->once())->method('updateOrderAttribute')
            ->with(7, Processor::SYNCED_TO_YOTPO_ORDER, 1);

        $processor->processSingleEntity($this->createOrder());
    }

    public function testNonRetryableFailureIsNotPersisted(): void
    {
        $processor = $this->createProcessor('400');
        $processor->expects($this->never())->method('insertOrUpdateYotpoTableData');
        $processor->expects($this->never())->method('updateOrderAttribute');

        $processor->processSingleEntity($this->createOrder());
    }

    public function testRetryableFailureIsStillPersistedButNotMarkedSynced(): void
    {
        // 500 is network-retriable - canResync() already treats it as safe to write, and
        // cron will still pick it up again regardless, so this must not be suppressed.
        $processor = $this->createProcessor('500');
        $processor->expects($this->once())->method('insertOrUpdateYotpoTableData');
        $processor->expects($this->never())->method('updateOrderAttribute');

        $processor->processSingleEntity($this->createOrder());
    }
}
