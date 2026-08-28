<?php

namespace Yotpo\Core\Test\Unit\Model\Sync\Orders;

use Magento\Sales\Model\Order;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Yotpo\Core\Helper\Data as CoreHelper;
use Yotpo\Core\Model\Sync\Orders\Data;

/**
 * Covers the per-order scoping of the line item product ids.
 *
 * Data is a DI singleton shared by every order in a cron batch, so the ids collected while
 * building a payload must belong to the order currently being prepared - see
 * Orders\Processor::syncOrder(), which uses them to gate the order on a product sync.
 */
class DataTest extends TestCase
{
    /**
     * Stubs everything prepareData() reaches out to except prepareLineItems(), which each
     * test configures itself, leaving prepareData() real.
     *
     * @return Data&MockObject
     */
    private function createData()
    {
        $data = $this->getMockBuilder(Data::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getYotpoOrderStatus',
                'getYotpoPaymentStatus',
                'prepareCustomerData',
                'prepareLineItems',
                'prepareFulfillments',
            ])
            ->getMock();

        $data->method('getYotpoOrderStatus')->willReturn('success');
        $data->method('getYotpoPaymentStatus')->willReturn('paid');
        $data->method('prepareCustomerData')->willReturn([]);
        $data->method('prepareFulfillments')->willReturn([]);

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.test/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $coreHelper = $this->createMock(CoreHelper::class);
        $coreHelper->method('formatDate')->willReturn('2026-08-27T00:00:00Z');

        $this->setProperty($data, 'storeManager', $storeManager);
        $this->setProperty($data, 'coreHelper', $coreHelper);

        return $data;
    }

    /**
     * @return Order&MockObject
     */
    private function createOrder()
    {
        $order = $this->createMock(Order::class);
        $order->method('getStatus')->willReturn('processing');
        $order->method('getBillingAddress')->willReturn(null);
        $order->method('getShippingAddress')->willReturn(null);
        $order->method('getPayment')->willReturn(null);

        return $order;
    }

    /**
     * @param object $object
     * @param string $name
     * @param mixed $value
     * @return void
     */
    private function setProperty($object, string $name, $value): void
    {
        (new \ReflectionProperty(Data::class, $name))->setValue($object, $value);
    }

    public function testGetLineItemsIdsRemovesDuplicates(): void
    {
        $data = $this->createData();
        $data->method('prepareLineItems')->willReturn([]);
        $this->setProperty($data, 'lineItemsProductIds', [5, 5, 7, 5]);

        $this->assertSame([5, 7], $data->getLineItemsIds());
    }

    public function testGetLineItemsIdsReturnsSequentiallyIndexedList(): void
    {
        $data = $this->createData();
        $data->method('prepareLineItems')->willReturn([]);
        $this->setProperty($data, 'lineItemsProductIds', [10, 10, 20]);

        // array_unique alone preserves the original keys, leaving a gappy array.
        $this->assertSame([0, 1], array_keys($data->getLineItemsIds()));
    }

    public function testPrepareDataDiscardsProductIdsFromPreviousOrders(): void
    {
        $data = $this->createData();
        $data->method('prepareLineItems')->willReturn([]);
        $this->setProperty($data, 'lineItemsProductIds', [99, 100]);

        $data->prepareData($this->createOrder(), 'update', []);

        $this->assertSame(
            [],
            $data->getLineItemsIds(),
            'prepareData() must clear ids collected for earlier orders in the batch'
        );
    }

    public function testPrepareDataKeepsOnlyTheCurrentOrdersProductIds(): void
    {
        $data = $this->createData();
        $this->setProperty($data, 'lineItemsProductIds', [99, 100]);

        // Stands in for prepareLineItems(), which appends - it never assigns.
        $data->method('prepareLineItems')->willReturnCallback(
            function () use ($data) {
                $this->setProperty($data, 'lineItemsProductIds', array_merge($data->getLineItemsIds(), [42]));
                return [];
            }
        );

        $data->prepareData($this->createOrder(), 'update', []);

        $this->assertSame([42], $data->getLineItemsIds());
    }
}
