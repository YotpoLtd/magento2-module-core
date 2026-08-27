<?php

namespace Yotpo\Core\Test\Unit\Model\Sync\Orders;

use Magento\Sales\Model\Order;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Yotpo\Core\Helper\Data as CoreHelper;
use Yotpo\Core\Model\Config;
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
        $order->method('getId')->willReturn(5);
        $order->method('getStatus')->willReturn('processing');
        $order->method('getBillingAddress')->willReturn(null);
        $order->method('getShippingAddress')->willReturn(null);
        $order->method('getPayment')->willReturn(null);
        $order->method('getUpdatedAt')->willReturn('2026-08-27 00:00:00');
        $order->method('getIncrementId')->willReturn('000000005');

        return $order;
    }

    /**
     * Stubs everything prepareFulfillments() reaches out to except the flag-resolution logic
     * under test, leaving prepareFulfillments() itself real. getYotpoOrderStatus() is fixed to
     * 'success' and prepareLineItems() to a sentinel, so the status-based branch's output is
     * deterministic and comparable across separate instances (e.g. one built for config "on",
     * one for config "off").
     *
     * @param string $configReturnValue
     * @return Data&MockObject
     */
    private function createDataForFulfillments($configReturnValue)
    {
        $data = $this->getMockBuilder(Data::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getYotpoOrderStatus',
                'getYotpoPaymentStatus',
                'prepareCustomerData',
                'prepareLineItems',
            ])
            ->getMock();

        $data->method('getYotpoOrderStatus')->willReturn(Data::YOTPO_STATUS_SUCCESS);
        $data->method('prepareLineItems')->willReturn(['SENTINEL']);

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.test/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $coreHelper = $this->createMock(CoreHelper::class);
        $coreHelper->method('formatDate')->willReturn('2026-08-27T00:00:00Z');

        $config = $this->createMock(Config::class);
        $config->method('getConfig')
            ->with('is_fulfillment_based_on_shipment')
            ->willReturn($configReturnValue);

        $this->setProperty($data, 'storeManager', $storeManager);
        $this->setProperty($data, 'coreHelper', $coreHelper);
        $this->setProperty($data, 'config', $config);

        return $data;
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

    /**
     * Covers the fulfillment-flag read path: prepareFulfillments() (Data.php:444-448) reads the
     * stored is_fulfillment_based_on_shipment value with no fallback at all. An order that has
     * never had an opinion recorded - stored NULL, e.g. via
     * Main::prepareYotpoTableDataForMissingProducts() - must be treated the same as an order
     * syncing for the first time, i.e. it must fall back to current config.
     */
    public function testPrepareFulfillmentsWithNullStoredFlagMatchesFirstSyncBehavior(): void
    {
        $order = $this->createOrder();

        $firstSyncResult = $this->createDataForFulfillments('1')
            ->prepareFulfillments($order, 'create', []);
        $nullStoredFlagResult = $this->createDataForFulfillments('1')->prepareFulfillments(
            $order,
            'update',
            [$order->getId() => ['yotpo_id' => 123, 'is_fulfillment_based_on_shipment' => null]]
        );

        $this->assertSame($firstSyncResult, $nullStoredFlagResult);
    }

    /**
     * Same as above for an empty-string stored value - fetchAssoc() never returns one for this
     * boolean column today, but the read path should not special-case NULL only.
     */
    public function testPrepareFulfillmentsWithEmptyStringStoredFlagMatchesFirstSyncBehavior(): void
    {
        $order = $this->createOrder();

        $firstSyncResult = $this->createDataForFulfillments('1')
            ->prepareFulfillments($order, 'create', []);
        $emptyStoredFlagResult = $this->createDataForFulfillments('1')->prepareFulfillments(
            $order,
            'update',
            [$order->getId() => ['yotpo_id' => 123, 'is_fulfillment_based_on_shipment' => '']]
        );

        $this->assertSame($firstSyncResult, $emptyStoredFlagResult);
    }

    /**
     * Regression guard - a genuinely stored '0' is a real pin, not an unset value, and must keep
     * winning over current config even after the fallback is added.
     */
    public function testPrepareFulfillmentsKeepsStoredZeroFlagEvenWhenConfigIsOn(): void
    {
        $order = $this->createOrder();

        $groundTruthConfigOff = $this->createDataForFulfillments('0')
            ->prepareFulfillments($order, 'create', []);
        $storedZeroResult = $this->createDataForFulfillments('1')->prepareFulfillments(
            $order,
            'update',
            [$order->getId() => ['yotpo_id' => 123, 'is_fulfillment_based_on_shipment' => '0']]
        );

        $this->assertSame($groundTruthConfigOff, $storedZeroResult);
    }
}
