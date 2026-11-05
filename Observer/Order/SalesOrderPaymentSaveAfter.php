<?php

namespace Yotpo\Core\Observer\Order;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Yotpo\Core\Model\Sync\Orders\Logger as YotpoOrdersLogger;
use Yotpo\Core\Model\Sync\Orders\Processor as OrdersProcessor;
use Yotpo\Core\Model\Config;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\State as AppState;

/**
 * Class SalesOrderPaymentSaveAfter
 * Observer is called when order is created/updated
 *
 * sales_order_payment_save_after fires while the order-save transaction is still open (during
 * checkout, after the payment provider may already have captured the payment). Nothing here may
 * write to the database or call the Yotpo API inside that transaction: a lock conflict on Yotpo
 * sync bookkeeping (e.g. a MySQL 1213 deadlock) would roll back the whole order. All the work -
 * the guest SMS consent, the re-queue and the real-time sync - is deferred to a commit callback
 * that runs only after the outermost transaction commits, and is dropped if it rolls back.
 *
 * A failure in the deferred work is logged and never rethrown. What cron recovers depends on the
 * order's synced_to_yotpo_order flag. A new order still reaches Yotpo, because the flag defaults
 * to 0. An order that had already synced has the flag at 1, and only reQueueOrder() resets it. If
 * the work fails before or inside reQueueOrder(), or a rolled-back save left the order's dedupe
 * entry behind in this process, the flag stays 1 and cron does not pick up the change.
 */
class SalesOrderPaymentSaveAfter extends OrderMain implements ObserverInterface
{

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var AppState
     */
    protected $appState;

    /**
     * @var YotpoOrdersLogger
     */
    protected $yotpoOrdersLogger;

    /**
     * Order ids that already have a deferred sync registered and not yet run, so several payment
     * saves of the same order in one transaction register the work once. The entry is cleared
     * when the callback runs, because this observer is a shared instance in long-running
     * processes (queue consumers, cron) and a later save of the same order must sync again.
     *
     * @var array<int|string, bool>
     */
    private $deferredSyncQueued = [];

    /**
     * SalesOrderPaymentSaveAfter constructor.
     * @param OrdersProcessor $ordersProcessor
     * @param Config $yotpoConfig
     * @param ResourceConnection $resourceConnection
     * @param CheckoutSession $checkoutSession
     * @param AppState $appState
     * @param YotpoOrdersLogger $yotpoOrdersLogger
     */
    public function __construct(
        OrdersProcessor $ordersProcessor,
        Config $yotpoConfig,
        ResourceConnection $resourceConnection,
        CheckoutSession $checkoutSession,
        AppState $appState,
        YotpoOrdersLogger $yotpoOrdersLogger
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->appState = $appState;
        $this->yotpoOrdersLogger = $yotpoOrdersLogger;
        parent::__construct($ordersProcessor, $yotpoConfig, $resourceConnection);
    }

    /**
     * @param Observer $observer
     * @throws LocalizedException
     * @return void
     */
    public function execute(Observer $observer)
    {
        $payment = $observer->getEvent()->getPayment();
        $order = $payment->getOrder();
        if (!$order || !$order->getEntityId()) {
            return;
        }

        $orderId = $order->getEntityId();
        if (isset($this->deferredSyncQueued[$orderId])) {
            return;
        }

        // Captured now, while the request context (area, checkout session) is the one that
        // triggered the save - it may differ by the time the commit callback runs.
        try {
            $areaCode = $this->appState->getAreaCode();
        } catch (LocalizedException $e) {
            // No area is set (a CLI script, for example), so there is no checkout session to
            // read the SMS choice from. Letting the exception escape would roll back the order.
            $areaCode = null;
        }
        $writeSmsConsent = $areaCode !== null
            && $areaCode !== \Magento\Framework\App\Area::AREA_ADMINHTML
            && $order->getCustomerIsGuest();
        $acceptsSmsMarketing = $writeSmsConsent ? $this->checkoutSession->getYotpoSmsMarketing() : null;

        $this->deferredSyncQueued[$orderId] = true;
        $payment->getResource()->addCommitCallback(
            function () use ($order, $orderId, $writeSmsConsent, $acceptsSmsMarketing) {
                $this->runDeferredSync($order, $orderId, $writeSmsConsent, $acceptsSmsMarketing);
            }
        );
    }

    /**
     * Runs after the order-save transaction has committed. Never throws: Magento's commit()
     * catches only \Exception, so an \Error escaping here would surface to the checkout.
     *
     * @param Order $order
     * @param int|string $orderId
     * @param bool $writeSmsConsent
     * @param mixed $acceptsSmsMarketing
     * @return void
     */
    private function runDeferredSync($order, $orderId, $writeSmsConsent, $acceptsSmsMarketing)
    {
        unset($this->deferredSyncQueued[$orderId]);
        try {
            // Written first: the sync reads it back from sales_order
            // (Data::prepareGuestUsersCustomAttributes()).
            if ($writeSmsConsent) {
                $this->ordersProcessor->updateOrderAttribute(
                    [$orderId],
                    'yotpo_accepts_sms_marketing',
                    $acceptsSmsMarketing ?: 0
                );
            }
            $this->reQueueOrder($order);
            $this->syncOrderNow($order);
        } catch (\Throwable $e) {
            $this->yotpoOrdersLogger->errorLog(
                'Deferred payment-save order sync failed - Order ID: ' . $orderId .
                ' - ' . get_class($e) . ': ' . $e->getMessage(),
                []
            );
        }
    }
}
