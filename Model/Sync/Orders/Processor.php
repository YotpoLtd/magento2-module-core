<?php

namespace Yotpo\Core\Model\Sync\Orders;

use Magento\Framework\DataObject;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\Orders\Data as OrdersData;
use Yotpo\Core\Model\Sync\Orders\Logger as YotpoOrdersLogger;
use Yotpo\Core\Model\Api\Sync as YotpoCoreSync;
use Yotpo\Core\Helper\Data as CoreHelperData;
use Yotpo\Core\Model\Sync\Catalog\Processor as CatalogProcessor;
use Yotpo\Core\Api\OrdersSyncRepositoryInterface;

/**
 * Class Processor - Process orders sync
 */
class Processor extends Main
{
    /**
     * Custom attribute name
     */
    const SYNCED_TO_YOTPO_ORDER = 'synced_to_yotpo_order';

    /**
     * @var YotpoCoreSync
     */
    protected $yotpoCoreSync;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var Logger
     */
    protected $yotpoOrdersLogger;

    /**
     * @var CoreHelperData
     */
    protected $helperData;

    /**
     * @var CatalogProcessor
     */
    protected $catalogProcessor;

    /**
     * @var int
     */
    protected $currentStoreId;

    /**
     * @var OrdersSyncRepositoryInterface
     */
    protected $ordersSyncRepositoryInterface;

    /**
     * @var bool
     */
    protected $isCommandLineSync = false;

    /**
     * Processor constructor.
     * @param AppEmulation $appEmulation
     * @param ResourceConnection $resourceConnection
     * @param Config $yotpoCoreConfig
     * @param YotpoCoreSync $yotpoCoreSync
     * @param OrderFactory $orderFactory
     * @param Data $data
     * @param Logger $yotpoOrdersLogger
     * @param CoreHelperData $helperData
     * @param CatalogProcessor $catalogProcessor
     * @param OrdersSyncRepositoryInterface $ordersSyncRepositoryInterface
     */
    public function __construct(
        AppEmulation $appEmulation,
        ResourceConnection $resourceConnection,
        Config $yotpoCoreConfig,
        YotpoCoreSync $yotpoCoreSync,
        OrderFactory $orderFactory,
        OrdersData $data,
        YotpoOrdersLogger $yotpoOrdersLogger,
        CoreHelperData $helperData,
        CatalogProcessor $catalogProcessor,
        OrdersSyncRepositoryInterface $ordersSyncRepositoryInterface
    ) {
        $this->yotpoCoreSync = $yotpoCoreSync;
        $this->orderFactory = $orderFactory;
        $this->yotpoOrdersLogger = $yotpoOrdersLogger;
        $this->helperData = $helperData;
        $this->catalogProcessor = $catalogProcessor;
        $this->ordersSyncRepositoryInterface = $ordersSyncRepositoryInterface;
        parent::__construct($appEmulation, $resourceConnection, $yotpoCoreConfig, $data);
    }

    /**
     * Process orders
     *
     * @param array<mixed> $retryOrderIds
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function process($retryOrderIds = [])
    {
        $orderCollection = null;
        $storeIds = [];

        if ($retryOrderIds) {
            $orderCollection = $this->getOrderCollection($retryOrderIds);
        }

        $orderByStore = [];
        if ($orderCollection) {
            foreach ($orderCollection->getItems() as $order) {
                $storeIds[] = $order->getStoreId();
                $orderByStore[$order->getStoreId()][] = $order;
            }
        }
        $storeIds = array_unique($storeIds) ?: $this->config->getAllStoreIds(false);
        /** @phpstan-ignore-next-line */
        foreach ($storeIds as $storeId) {
            if ($this->isCommandLineSync) {
                // phpcs:ignore
                echo 'Orders process started for store - ' .
                    $this->config->getStoreName($storeId) . PHP_EOL;
            }
            $this->emulateFrontendArea((int)$storeId);
            $this->currentStoreId = $this->config->getStoreId();
            if (!$this->config->isOrdersSyncActive()) {
                if ($this->isCommandLineSync) {
                    // phpcs:ignore
                    echo 'Orders sync is disabled for store - ' .
                        $this->config->getStoreName($storeId) . PHP_EOL;
                }
                $this->stopEnvironmentEmulation();
                continue;
            }
            $this->yotpoOrdersLogger->infoLog('Process orders for store : ' . $storeId, []);
            $orders = $orderByStore[$storeId] ?? [];
            $this->processOrders($orders);
            $this->stopEnvironmentEmulation();
        }
    }

    /**
     * Process single order
     *
     * @param Order $order
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function processOrder($order)
    {
        $storeId = $order->getStoreId();
        $this->emulateFrontendArea((int)$storeId);
        if ($this->config->isSyncResetInProgress($storeId, 'order')) {
            return;
        }
        $this->currentStoreId = $this->config->getStoreId();
        $this->yotpoOrdersLogger->infoLog(
            __(
                'Process order for Magento Store ID: %1, Name: %2',
                $storeId,
                $this->config->getStoreName((int)$storeId)
            ),
            []
        );
        $this->processSingleEntity($order);
        $this->stopEnvironmentEmulation();
    }

    /**
     * Process Orders
     *
     * @param array<mixed> $orderItems
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @return void
     */
    public function processOrders($orderItems = [])
    {
        $orders = [];
        $magentoOrders = [];
        $orderIds = [];
        $customerIds = [];
        $couponCodes = [];
        $guestOrderIds = [];
        $currentTime = date('Y-m-d H:i:s');

        if (!$orderItems) {
            $orderCollection = $this->getOrderCollection();
            $orderItems = $orderCollection->getitems();
        }

        $ordersWithMissedProducts = [];
        foreach ($orderItems as $order) {
            $storeId = $order->getStoreId();
            if ($this->config->isSyncResetInProgress($storeId, 'order')) {
                $this->yotpoOrdersLogger->infoLog(
                    __(
                        'Order sync is skipped because order sync reset is in progress
                         - Magento Store ID: %1, Name: %2',
                        $storeId,
                        $this->config->getStoreName($storeId)
                    )
                );
                continue;
            }
            $productsMissing = $this->checkMissingProducts($order);
            if ($productsMissing) {
                $this->yotpoOrdersLogger->infoLog('Products not exist for order  : ' . $order->getId(), []);
                $ordersWithMissedProducts[] = $order->getId();
                $this->updateOrderAttribute([$order->getId()], self::SYNCED_TO_YOTPO_ORDER, 1);
                continue;
            }
            $orders[] = $order;
            $orderId = $order->getEntityId();
            $magentoOrders[$orderId] = $order;
            $orderIds[] = $orderId;
            if ($order->getCustomerId()) {
                $customerIds[] = $order->getCustomerId();
            } else {
                $guestOrderIds[] = $order->getEntityId();
            }
            $couponCodes[] = $order->getCouponCode();
        }
        if ($orderIds) {
            $this->data->prepareShipmentStatuses($orderIds);
            $this->data->prepareParentData($orders);
            if ($customerIds) {
                $this->data->prepareCustomAttributes($customerIds);
            }
            if ($guestOrderIds) {
                $this->data->prepareGuestUsersCustomAttributes($guestOrderIds);
            }
        }
        if ($couponCodes) {
            $this->data->prepareCouponCodes(array_unique($couponCodes));
        }
        if ($magentoOrders) {
            $yotpoSyncedOrders = $this->getYotpoSyncedOrders($magentoOrders);
            foreach ($magentoOrders as $magentoOrder) {
                try {
                    $magentoOrderId = $magentoOrder->getEntityId();
                    $isYotpoSyncedOrder = false;
                    if ($yotpoSyncedOrders && array_key_exists($magentoOrderId, $yotpoSyncedOrders)) {
                        $responseCode = $yotpoSyncedOrders[$magentoOrderId]['response_code'];
                        if (!$this->config->canResync(
                            $responseCode,
                            $yotpoSyncedOrders[$magentoOrderId]['yotpo_id'],
                            $this->isCommandLineSync
                        )) {
                            $this->updateOrderAttribute([$magentoOrderId], self::SYNCED_TO_YOTPO_ORDER, 1);
                            $this->yotpoOrdersLogger->infoLog('Order sync cannot be done for orderId: '
                                . $magentoOrderId . ', due to response code: ' . $responseCode, []);
                            continue;
                        } else {
                            $isYotpoSyncedOrder = true;
                        }
                    }
                    /** @var Order $magentoOrder */
                    $response = $this->syncOrder($magentoOrder, $isYotpoSyncedOrder, $yotpoSyncedOrders);
                    $yotpoTableData = $response ?
                        $this->prepareYotpoTableData($response, $isYotpoSyncedOrder, $yotpoSyncedOrders, $magentoOrderId)
                        : false;
                    if ($yotpoTableData) {
                        if (!$yotpoTableData['yotpo_id'] && array_key_exists($magentoOrderId, $yotpoSyncedOrders)) {
                            $yotpoTableData['yotpo_id'] = $yotpoSyncedOrders[$magentoOrderId]['yotpo_id'];
                        }
                        $yotpoTableData['order_id'] = $magentoOrderId;
                        $yotpoTableData['synced_to_yotpo'] = $currentTime;
                        $this->insertOrUpdateYotpoTableData($yotpoTableData);
                        if ($this->config->canUpdateCustomAttribute($yotpoTableData['response_code'])) {
                            $this->updateOrderAttribute($magentoOrderId, self::SYNCED_TO_YOTPO_ORDER, 1);
                        }
                    }
                } catch (\Exception $e) {
                    $magentoOrderId = method_exists($magentoOrder, 'getEntityId') ? $magentoOrder->getEntityId() : null;
                    $this->yotpoOrdersLogger->infoLog(
                        __(
                            'Exception raised within processOrders - magentoOrderId: %1, Exception Message: %2',
                            $magentoOrderId,
                            $e->getMessage()
                        )
                    );
                    $this->updateOrderAttribute([$magentoOrder->getId()], self::SYNCED_TO_YOTPO_ORDER, 1);
                }
            }
        }
        foreach ($ordersWithMissedProducts as $orderId) {
            $yotpoTableDataForMissingProducts = $this->prepareYotpoTableDataForMissingProducts($orderId, $currentTime);
            $this->insertOrUpdateYotpoTableData($yotpoTableDataForMissingProducts);
        }
        $this->updateLastSyncDate($currentTime);
        $this->updateTotalOrdersSynced();
    }

    /**
     * Process single order entity
     *
     * @param Order $magentoOrder
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function processSingleEntity($magentoOrder)
    {
        $magentoOrderId = $magentoOrder->getEntityId();
        $yotpoTableFinalData = [];
        $productsMissing = $this->checkMissingProducts($magentoOrder);
        $ordersToUpdate[] = $magentoOrderId;
        $currentTime = date('Y-m-d H:i:s');
        if ($productsMissing) {
            $this->yotpoOrdersLogger->infoLog('Products not exist for order  : ' . $magentoOrderId, []);
            $yotpoTableFinalData[] = $this->prepareYotpoTableDataForMissingProducts($magentoOrderId, $currentTime);
            $this->insertOrUpdateYotpoTableData($yotpoTableFinalData);
            $this->updateOrderAttribute($ordersToUpdate, self::SYNCED_TO_YOTPO_ORDER, 1);
            $this->updateLastSyncDate($currentTime);
            $this->updateTotalOrdersSynced();
            return;
        }
        $mappedOrderStatuses = $this->data->getMappedOrderStatuses();
        if (!isset($mappedOrderStatuses[$magentoOrder->getStatus()])) {
            $this->yotpoOrdersLogger->infoLog(
                'Missing order status mapping for Order# ' . $magentoOrderId.' - Status : '. $magentoOrder->getStatus(),
                []
            );
            return;
        }
        $customerId = $magentoOrder->getCustomerId();

        $magentoOrders = [];
        $orders[] = $magentoOrder;
        $customerIds = [];
        $couponCodes[] = $magentoOrder->getCouponCode();
        if ($customerId) {
            $customerIds[] = $customerId;
        }

        try {
            $this->yotpoOrdersLogger->infoLog('Order attribute updated to 0 for order : ' . $magentoOrderId, []);
            if (!$this->config->isRealTimeOrdersSyncActive()) {
                return;
            }
            $magentoOrders[$magentoOrderId] = $magentoOrder;
            $yotpoSyncedOrders = $this->getYotpoSyncedOrders($magentoOrders);
            $isYotpoSyncedOrder = false;
            if ($yotpoSyncedOrders) {
                if (array_key_exists($magentoOrderId, $yotpoSyncedOrders)) {
                    $responseCode = $yotpoSyncedOrders[$magentoOrderId]['response_code'];
                    if (!$this->config->canResync($responseCode, $yotpoSyncedOrders[$magentoOrderId]['yotpo_id'])) {
                        $this->updateOrderAttribute($magentoOrderId, self::SYNCED_TO_YOTPO_ORDER, 1);
                        $this->yotpoOrdersLogger->infoLog('Order sync cannot be done for orderId: '
                            . $magentoOrderId . ', due to response code: ' . $responseCode, []);
                        return;
                    } else {
                        $isYotpoSyncedOrder = true;
                    }
                }
            }
            $this->data->prepareParentData($orders);
            $this->data->prepareShipmentStatuses($ordersToUpdate);
            $customerIds ? $this->data->prepareCustomAttributes($customerIds) :
                $this->data->prepareGuestUsersCustomAttributes($ordersToUpdate);
            $this->data->prepareCouponCodes($couponCodes);
            $response = $this->syncOrder($magentoOrder, $isYotpoSyncedOrder, $yotpoSyncedOrders);
            $yotpoTableData = $response ?
                $this->prepareYotpoTableData(
                    $response,
                    $isYotpoSyncedOrder,
                    $yotpoSyncedOrders,
                    $magentoOrder->getId()
                ) : false;
            $this->yotpoOrdersLogger->infoLog('Last sync date updated for order : '
                . $magentoOrderId, []);
            if ($yotpoTableData) {
                if (!$yotpoTableData['yotpo_id'] && array_key_exists($magentoOrderId, $yotpoSyncedOrders)) {
                    $yotpoTableData['yotpo_id'] = $yotpoSyncedOrders[$magentoOrderId]['yotpo_id'];
                }
                $yotpoTableData['order_id'] = $magentoOrderId;
                $yotpoTableData['synced_to_yotpo'] = $currentTime;
                $this->insertOrUpdateYotpoTableData($yotpoTableData);
                if ($this->config->canUpdateCustomAttribute($yotpoTableData['response_code'])) {
                    $this->updateOrderAttribute($magentoOrderId, self::SYNCED_TO_YOTPO_ORDER, 1);
                }
                $this->yotpoOrdersLogger->infoLog('Order attribute updated to 1 for order : '
                    . $magentoOrderId, []);
            }
            $this->updateLastSyncDate($currentTime);
            $this->updateTotalOrdersSynced();
        } catch (NoSuchEntityException | LocalizedException $e) {
            $this->yotpoOrdersLogger->addError($e->getMessage());
        }
    }

    /**
     * Get Order collection
     *
     * @param array <mixed> $retryOrderIds
     * @return OrderCollection <mixed>
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getOrderCollection($retryOrderIds = [])
    {
        $storeId = $this->config->getStoreId();
        $batchSize = $this->config->getConfig('orders_sync_limit');
        $timeLimit = $this->config->getConfig('orders_sync_time_limit');
        $formattedDate = $this->helperData->formatOrderItemDate($timeLimit);
        $mappedOrderStatuses = $this->data->getMappedOrderStatuses();

        $orderCollection = $this->orderFactory->create();
        $orderCollection
            ->addFieldToFilter('store_id', ['eq' => $storeId])
            ->addFieldToFilter('created_at', ['from' => $formattedDate]);
        if (!$retryOrderIds) {
            $orderCollection
                ->addFieldToFilter(self::SYNCED_TO_YOTPO_ORDER, ['eq' => 0]);
        } else {
            $orderCollection->addFieldToFilter('entity_id', ['in' => $retryOrderIds]);
        }
        if ($mappedOrderStatuses) {
            $orderCollection->addFieldToFilter('status', ['in' => array_keys($mappedOrderStatuses)]);
        }

        $orderCollection->getSelect()->limit($batchSize);

        return $orderCollection;
    }

    /**
     * Calls order sync api
     *
     * @param Order $order
     * @param bool $isYotpoSyncedOrder
     * @param array<mixed> $yotpoSyncedOrders
     * @return array<mixed>|DataObject
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function syncOrder($order, $isYotpoSyncedOrder, $yotpoSyncedOrders)
    {
        $incrementId = $order->getIncrementId();
        $orderId = $order->getEntityId();
        $dataType = $isYotpoSyncedOrder ? 'update' : 'create';
        $this->yotpoOrdersLogger->infoLog(
            __(
                'Orders sync, starting sync - Order ID: %1, Increment ID: %2',
                $orderId,
                $incrementId
            )
        );
        $orderData = $this->data->prepareData($order, $dataType, $yotpoSyncedOrders);
        if (!$orderData) {
            $this->yotpoOrdersLogger->infoLog('Orders sync - no new data to sync', []);
            return [];
        }
        $this->yotpoOrdersLogger->infoLog('Orders sync - data prepared - Order ID - ' . $orderId, []);
        $productIds = $this->data->getLineItemsIds();
        $storeId = $order->getStoreId();
        if ($productIds) {
            $visibleItems = $order->getAllVisibleItems();
            $isProductSyncSuccess = $this->catalogProcessor->syncProducts($productIds, $visibleItems, $storeId);
            if (!$isProductSyncSuccess) {
                $this->yotpoOrdersLogger->infoLog('Products sync failed - Order ID - ' . $order->getId(), []);
                return [];
            }
        }
        $yotpoOrderId = null;
        if ($isYotpoSyncedOrder && $yotpoSyncedOrders[$orderId]['yotpo_id']) {
            $yotpoOrderId = $yotpoSyncedOrders[$orderId]['yotpo_id'];
        } else {
            $response = $this->yotpoCoreSync->sync(
                'GET',
                $this->config->getEndpoint('orders'),
                ['external_ids' => $incrementId, 'entityLog' => 'orders'],
                true
            );
            if ($response->getData('is_success')) {
                $yotpoOrderId = $this->getYotpoIdFromResponse($response);
            }
        }
        if ($yotpoOrderId) {
            $url = $this->config->getEndpoint('orders_update', ['{yotpo_order_id}'], [$yotpoOrderId]);
            $method = $this->config::METHOD_PATCH;
        } else {
            $url = $this->config->getEndpoint('orders');
            $method = $this->config::METHOD_POST;
        }
        $orderData['entityLog'] = 'orders';
        $response = $this->yotpoCoreSync->sync($method, $url, $orderData, true);
        $immediateRetry = false;
        if ($response->getData('is_success')) {
            if ($yotpoOrderId) {
                $response->setData('yotpo_id', $yotpoOrderId);
            }

            $this->yotpoOrdersLogger->infoLog('Orders sync - success - ' . $orderId, []);
        } elseif ($response->getData('status') == 409) {//order already exists in Yotpo and not in custom table
            $response = $this->yotpoCoreSync->sync(
                'GET',
                $this->config->getEndpoint('orders'),
                ['external_ids' => $incrementId, 'entityLog' => 'orders'],
                true
            );
        } elseif ($this->isImmediateRetry($response, $this->entity, $orderId, $order->getStoreId())) {
            $missingProducts = $this->getMissingProductIdsFromNotFoundResponse($response);
            if ($missingProducts) {
                $this->catalogProcessor->removeProductFromSyncTable($missingProducts, [$storeId]);
            }
            $immediateRetry = true;
            $this->setImmediateRetryAlreadyDone($this->entity, $orderId, $order->getStoreId());
            if (array_key_exists($orderId, $yotpoSyncedOrders)) {
                unset($yotpoSyncedOrders[$orderId]);
            }
            $response = $this->syncOrder($order, false, $yotpoSyncedOrders);
        }
        if ($this->isCommandLineSync && !$immediateRetry) {
            // phpcs:ignore
            echo 'Order process completed for orderId - ' . $orderId . PHP_EOL;
        }
        return $response;
    }

    /**
     * Update custom attribute - synced_to_yotpo_order
     *
     * @param array<int> | int $orderIds
     * @param string $attributeName
     * @param int $value
     * @return void
     */
    public function updateOrderAttribute($orderIds, $attributeName, $value)
    {
        if (!is_array($orderIds)) {
            $orderIds = [$orderIds];
        }
        $this->update(
            'sales_order',
            [$attributeName => $value, 'updated_at' => new \Zend_Db_Expr('updated_at')],
            ['entity_id' . ' IN (?)' => $orderIds]
        );
    }

    /**
     * Updates the last sync date to the database
     *
     * @param string $currentTime
     * @return void
     * @throws NoSuchEntityException
     */
    public function updateLastSyncDate($currentTime)
    {
        $this->config->saveConfig('orders_last_sync_time', $currentTime);
    }

    /**
     * Update total orders synced config value
     *
     * @return void
     * @throws NoSuchEntityException
     */
    public function updateTotalOrdersSynced()
    {
        $totalCount = $this->data->getTotalSyncedOrders();
        $this->config->saveConfig('orders_total_synced', $totalCount);
    }

    /**
     * @param Order $order
     * @return bool
     */
    public function checkMissingProducts(Order $order)
    {
        $missingProducts = [];
        $totalItems = $order->getAllVisibleItems();
        foreach ($totalItems as $orderItem) {
            if (!$orderItem->getProduct()) {
                $missingProducts[] = $orderItem->getProductId();
            }
        }
        return count($missingProducts) == count($totalItems);
    }

    /**
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @return void
     */
    public function retryOrdersSync()
    {
        $this->isCommandLineSync = true;
        $orderIds = [];
        $items = $this->ordersSyncRepositoryInterface->getByResponseCodes();
        foreach ($items as $item) {
            $orderIds[] = $item['order_id'];
        }
        if ($orderIds) {
            $this->process($orderIds);
        } else {
            // phpcs:ignore
            echo 'No order data to process.' . PHP_EOL;
        }
    }
}
