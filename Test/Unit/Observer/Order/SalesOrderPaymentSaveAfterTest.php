<?php

namespace Yotpo\Core\Test\Unit\Observer\Order;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\Orders\Logger as YotpoOrdersLogger;
use Yotpo\Core\Model\Sync\Orders\Processor as OrdersProcessor;
use Yotpo\Core\Observer\Order\SalesOrderPaymentSaveAfter;

/**
 * sales_order_payment_save_after fires inside the checkout's order-save transaction, after the
 * payment provider may already have captured the payment. A lock conflict on Yotpo bookkeeping
 * there (MySQL 1213) would roll back the order, so execute() must do no DB write and no sync:
 * all of it - guest SMS consent, re-queue, real-time sync - runs in a commit callback that
 * fires only after the outermost commit, is dropped on rollback, and never throws (TS-179).
 */
class SalesOrderPaymentSaveAfterTest extends TestCase
{
    /**
     * @var string[] ordered log of the side effects the observer causes
     */
    private $calls = [];

    /**
     * @var AdapterInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $syncTableConnection;

    protected function setUp(): void
    {
        $this->calls = [];
    }

    /**
     * @param \Throwable|null $processOrderThrows
     * @return OrdersProcessor
     */
    private function createOrdersProcessor($processOrderThrows = null)
    {
        $ordersProcessor = $this->createMock(OrdersProcessor::class);
        $ordersProcessor->method('updateOrderAttribute')->willReturnCallback(
            function ($ids, $attribute, $value) {
                $this->calls[] = 'updateOrderAttribute:' . $attribute . '=' . $value . ':' . implode(',', $ids);
            }
        );
        $ordersProcessor->method('processOrder')->willReturnCallback(
            function () use ($processOrderThrows) {
                $this->calls[] = 'processOrder';
                if ($processOrderThrows) {
                    throw $processOrderThrows;
                }
            }
        );
        return $ordersProcessor;
    }

    /**
     * @param bool $realTimeActive
     * @return Config
     */
    private function createConfig($realTimeActive)
    {
        $yotpoConfig = $this->createMock(Config::class);
        $yotpoConfig->method('isRealTimeOrdersSyncActive')->willReturn($realTimeActive);
        return $yotpoConfig;
    }

    /**
     * @param OrdersProcessor $ordersProcessor
     * @param Config $yotpoConfig
     * @param YotpoOrdersLogger|null $logger
     * @param string $areaCode
     * @param mixed $smsValue
     * @return SalesOrderPaymentSaveAfter
     */
    private function createObserver(
        $ordersProcessor,
        $yotpoConfig,
        $logger = null,
        $areaCode = Area::AREA_FRONTEND,
        $smsValue = 1
    ) {
        $this->syncTableConnection = $this->createMock(AdapterInterface::class);
        $this->syncTableConnection->method('update')->willReturnCallback(
            function ($table) {
                $this->calls[] = 'update:' . $table;
                return 1;
            }
        );
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->syncTableConnection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $checkoutSession = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->addMethods(['getYotpoSmsMarketing'])
            ->getMock();
        $checkoutSession->method('getYotpoSmsMarketing')->willReturn($smsValue);

        $appState = $this->createMock(AppState::class);
        $appState->method('getAreaCode')->willReturn($areaCode);

        return new SalesOrderPaymentSaveAfter(
            $ordersProcessor,
            $yotpoConfig,
            $resourceConnection,
            $checkoutSession,
            $appState,
            $logger ?: $this->createMock(YotpoOrdersLogger::class)
        );
    }

    /**
     * @param int $entityId
     * @param bool $isGuest
     * @return Order
     */
    private function createOrder($entityId, $isGuest = true)
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getEntityId', 'getStoreId', 'getCustomerIsGuest'])
            ->getMock();
        $order->method('getId')->willReturn($entityId);
        $order->method('getEntityId')->willReturn($entityId);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getCustomerIsGuest')->willReturn($isGuest);
        return $order;
    }

    /**
     * @param Order $order
     * @param AbstractResource $resource
     * @return Observer
     */
    private function createEvent($order, $resource)
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getResource')->willReturn($resource);
        return new Observer(['event' => new Event(['payment' => $payment])]);
    }

    /**
     * A resource mock that captures every callback passed to addCommitCallback().
     *
     * @param callable[] &$callbacks
     * @return AbstractResource
     */
    private function createCapturingResource(&$callbacks)
    {
        $callbacks = [];
        $resource = $this->createMock(AbstractResource::class);
        $resource->method('addCommitCallback')->willReturnCallback(
            function ($callback) use (&$callbacks, &$resource) {
                $callbacks[] = $callback;
                return $resource;
            }
        );
        return $resource;
    }

    /**
     * A real AbstractResource subclass on a fake connection that tracks the transaction level
     * like a PDO adapter does, so Magento's own commit()/rollBack() and CallbackPool logic decide
     * when commit callbacks run.
     *
     * @return AbstractResource
     */
    private function createRealResource()
    {
        $level = 0;
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('beginTransaction')->willReturnCallback(function () use (&$level, &$adapter) {
            $level++;
            return $adapter;
        });
        $adapter->method('commit')->willReturnCallback(function () use (&$level, &$adapter) {
            $level--;
            return $adapter;
        });
        $adapter->method('rollBack')->willReturnCallback(function () use (&$level, &$adapter) {
            $level = 0;
            return $adapter;
        });
        $adapter->method('getTransactionLevel')->willReturnCallback(function () use (&$level) {
            return $level;
        });

        return new class ($adapter) extends AbstractResource {
            /**
             * @var AdapterInterface
             */
            private $adapter;

            /**
             * @param AdapterInterface $adapter
             */
            public function __construct($adapter)
            {
                $this->adapter = $adapter;
                parent::__construct();
            }

            /**
             * @return void
             */
            protected function _construct()
            {
            }

            /**
             * @return AdapterInterface
             */
            public function getConnection()
            {
                return $this->adapter;
            }
        };
    }

    public function testExecuteWritesNothingAndDoesNotSync(): void
    {
        $ordersProcessor = $this->createOrdersProcessor();
        $ordersProcessor->expects($this->never())->method('updateOrderAttribute');
        $ordersProcessor->expects($this->never())->method('processOrder');

        $observer = $this->createObserver($ordersProcessor, $this->createConfig(true));
        $this->syncTableConnection->expects($this->never())->method('update');

        $callbacks = [];
        $resource = $this->createCapturingResource($callbacks);
        $observer->execute($this->createEvent($this->createOrder(5), $resource));

        $this->assertCount(1, $callbacks, 'execute() must register exactly one commit callback');
        $this->assertSame([], $this->calls, 'execute() must not cause any side effect');
    }

    public function testCallbackWritesSmsConsentThenReQueuesThenSyncs(): void
    {
        $observer = $this->createObserver($this->createOrdersProcessor(), $this->createConfig(true));

        $callbacks = [];
        $observer->execute($this->createEvent($this->createOrder(5), $this->createCapturingResource($callbacks)));
        $callbacks[0]();

        $this->assertSame(
            [
                'updateOrderAttribute:yotpo_accepts_sms_marketing=1:5',
                'updateOrderAttribute:synced_to_yotpo_order=0:5',
                'update:yotpo_orders_sync',
                'processOrder',
            ],
            $this->calls
        );
    }

    public function testSmsValueIsCapturedAtExecuteTime(): void
    {
        // A missing session value is stored as 0, same as before the fix.
        $observer = $this->createObserver(
            $this->createOrdersProcessor(),
            $this->createConfig(false),
            null,
            Area::AREA_FRONTEND,
            null
        );

        $callbacks = [];
        $observer->execute($this->createEvent($this->createOrder(5), $this->createCapturingResource($callbacks)));
        $callbacks[0]();

        $this->assertSame('updateOrderAttribute:yotpo_accepts_sms_marketing=0:5', $this->calls[0]);
    }

    public function testReQueuesButDoesNotSyncWhenRealTimeSyncIsOff(): void
    {
        $observer = $this->createObserver($this->createOrdersProcessor(), $this->createConfig(false));

        $callbacks = [];
        $observer->execute($this->createEvent($this->createOrder(5), $this->createCapturingResource($callbacks)));
        $callbacks[0]();

        $this->assertContains('updateOrderAttribute:synced_to_yotpo_order=0:5', $this->calls);
        $this->assertContains('update:yotpo_orders_sync', $this->calls);
        $this->assertNotContains('processOrder', $this->calls);
    }

    public function testNoSmsConsentWriteForAdminAreaOrRegisteredCustomer(): void
    {
        $observer = $this->createObserver(
            $this->createOrdersProcessor(),
            $this->createConfig(true),
            null,
            Area::AREA_ADMINHTML
        );
        $callbacks = [];
        $observer->execute($this->createEvent($this->createOrder(5), $this->createCapturingResource($callbacks)));
        $callbacks[0]();

        $observer = $this->createObserver($this->createOrdersProcessor(), $this->createConfig(true));
        $observer->execute($this->createEvent($this->createOrder(6, false), $this->createCapturingResource($callbacks)));
        $callbacks[0]();

        foreach ($this->calls as $call) {
            $this->assertStringNotContainsString('yotpo_accepts_sms_marketing', $call);
        }
        $this->assertSame(2, count(array_keys($this->calls, 'processOrder')));
    }

    public function testOrderWithoutEntityIdRegistersNothing(): void
    {
        $observer = $this->createObserver($this->createOrdersProcessor(), $this->createConfig(true));

        $callbacks = [];
        $observer->execute($this->createEvent($this->createOrder(0), $this->createCapturingResource($callbacks)));

        $this->assertSame([], $callbacks);
        $this->assertSame([], $this->calls);
    }

    public function testTwoSavesOfOneOrderRegisterOneCallbackAndALaterSaveRegistersAgain(): void
    {
        $observer = $this->createObserver($this->createOrdersProcessor(), $this->createConfig(true));
        $order = $this->createOrder(5);

        $callbacks = [];
        $resource = $this->createCapturingResource($callbacks);
        $observer->execute($this->createEvent($order, $resource));
        $observer->execute($this->createEvent($order, $resource));
        $this->assertCount(1, $callbacks, 'a second save in the same transaction must not register again');

        $callbacks[0]();

        // The observer is a shared instance: after the callback has run, a later save of the
        // same order (e.g. an invoice in a queue consumer) must sync again.
        $observer->execute($this->createEvent($order, $resource));
        $this->assertCount(2, $callbacks);

        // A different order is never blocked by another order's pending entry.
        $observer->execute($this->createEvent($this->createOrder(6), $resource));
        $this->assertCount(3, $callbacks);
    }

    /**
     * @return array<string, array{\Throwable}>
     */
    public static function throwableProvider(): array
    {
        return [
            'exception' => [new \RuntimeException('SQLSTATE[40001]: 1213 Deadlock found')],
            'error' => [new \Error('boom')],
        ];
    }

    /**
     * @dataProvider throwableProvider
     * @param \Throwable $throwable
     */
    public function testFailureInCallbackIsLoggedAndNotRethrown(\Throwable $throwable): void
    {
        $logger = $this->createMock(YotpoOrdersLogger::class);
        $logger->expects($this->once())
            ->method('errorLog')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('Order ID: 5'),
                    $this->stringContains(get_class($throwable)),
                    $this->stringContains($throwable->getMessage())
                )
            );

        $observer = $this->createObserver(
            $this->createOrdersProcessor($throwable),
            $this->createConfig(true),
            $logger
        );

        $callbacks = [];
        $resource = $this->createCapturingResource($callbacks);
        $order = $this->createOrder(5);
        $observer->execute($this->createEvent($order, $resource));

        // Must not throw: Magento's commit() catches only \Exception, so an \Error escaping the
        // callback would surface to the checkout after the order was committed.
        $callbacks[0]();

        // The dedupe entry was cleared even though the work failed.
        $observer->execute($this->createEvent($order, $resource));
        $this->assertCount(2, $callbacks);
    }

    public function testWorkRunsOnlyAfterOutermostCommitOnARealResource(): void
    {
        $observer = $this->createObserver($this->createOrdersProcessor(), $this->createConfig(true));
        $resource = $this->createRealResource();

        $resource->beginTransaction(); // order save
        $resource->beginTransaction(); // nested payment save
        $observer->execute($this->createEvent($this->createOrder(5), $resource));

        $resource->commit(); // inner commit - still inside the outer transaction
        $this->assertSame([], $this->calls, 'nothing may run before the outermost commit');

        $resource->commit(); // outermost commit
        $this->assertSame(
            [
                'updateOrderAttribute:yotpo_accepts_sms_marketing=1:5',
                'updateOrderAttribute:synced_to_yotpo_order=0:5',
                'update:yotpo_orders_sync',
                'processOrder',
            ],
            $this->calls
        );
    }

    public function testWorkIsDroppedOnRollbackOnARealResource(): void
    {
        $observer = $this->createObserver($this->createOrdersProcessor(), $this->createConfig(true));
        $resource = $this->createRealResource();

        $resource->beginTransaction();
        $observer->execute($this->createEvent($this->createOrder(5), $resource));
        $resource->rollBack(); // e.g. the 1213 deadlock elsewhere in the order save

        // A following, unrelated transaction on the same connection must not run the dropped work.
        $resource->beginTransaction();
        $resource->commit();

        $this->assertSame([], $this->calls);
    }
}

