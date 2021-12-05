<?php

namespace Yotpo\Core\Observer\Order;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use Yotpo\Core\Model\Sync\Orders\Processor as OrdersProcessor;
use Yotpo\Core\Model\Config;
use Magento\Sales\Model\OrderRepository;

/**
 * Class SalesOrderSaveAfter
 * Observer is called when order is created/updated
 */
class AdminSalesOrderAddressUpdate extends OrderMain implements ObserverInterface
{

    /**
     * @var OrderRepository
     */
    protected $orderRepository;

    /**
     * AdminSalesOrderAddressUpdate constructor.
     * @param OrdersProcessor $ordersProcessor
     * @param Config $yotpoConfig
     * @param ResourceConnection $resourceConnection
     * @param OrderRepository $orderRepository
     */
    public function __construct(
        OrdersProcessor $ordersProcessor,
        Config $yotpoConfig,
        ResourceConnection $resourceConnection,
        OrderRepository $orderRepository
    ) {
        $this->orderRepository = $orderRepository;
        parent::__construct($ordersProcessor, $yotpoConfig, $resourceConnection);
    }

    /**
     * @param Observer $observer
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            $order = $this->orderRepository->get($observer->getOrderId());
        } catch (NoSuchEntityException $e) {
            $order = null;
        }
        if ($order && $order->getEntityId()) {
            /** @phpstan-ignore-next-line */
            $this->processOrderSync($order);
        }
    }
}
