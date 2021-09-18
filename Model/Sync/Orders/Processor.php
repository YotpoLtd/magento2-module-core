<?php

namespace Yotpo\Core\Model\Sync\Orders;

use Magento\Framework\DataObject;
use Magento\Sales\Model\Order;
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
use Magento\Framework\App\State as AppState;

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
     * @var AppState
     */
    protected $appState;

    /**
     * @var int
     */
    protected $currentStoreId;

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
     * @param AppState $appState
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
        AppState $appState
    ) {
        $this->yotpoCoreSync = $yotpoCoreSync;
        $this->orderFactory = $orderFactory;
        $this->yotpoOrdersLogger = $yotpoOrdersLogger;
        $this->helperData = $helperData;
        $this->catalogProcessor = $catalogProcessor;
        $this->appState = $appState;
        parent::__construct($appEmulation, $resourceConnection, $yotpoCoreConfig, $data);
    }

    /**
     * Process orders
     *
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function process()
    {
        /** @phpstan-ignore-next-line */
        foreach ($this->config->getAllStoreIds(false) as $storeId) {
            $this->emulateFrontendArea((int)$storeId);
            $this->currentStoreId = $this->config->getStoreId();
            if (!$this->config->isOrdersSyncActive()) {
                $this->stopEnvironmentEmulation();
                continue;
            }
            $this->yotpoOrdersLogger->info('Process orders for store : ' . $storeId, []);
            $this->processOrders();
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
        $this->currentStoreId = $this->config->getStoreId();
        if (!$this->config->isOrdersSyncActive()) {
            $this->stopEnvironmentEmulation();
            return;
        }
        $this->yotpoOrdersLogger->info('Process order for the store : ' . $storeId, []);
        $this->processSingleEntity($order);
        $this->stopEnvironmentEmulation();
    }

    /**
     * Process orders
     *
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function processOrders()
    {
        $orders = [];
        $ordersToUpdate = [];
        $magentoOrders = [];
        $yotpoTableFinalData = [];
        $orderIds = [];
        $customerIds = [];
        $couponCodes = [];
        $guestOrderIds = [];
        $storeId = $this->config->getStoreId();
        $currentTime = date('Y-m-d H:i:s');
        $batchSize = $this->config->getConfig('orders_sync_limit');
        $timeLimit = $this->config->getConfig('orders_sync_time_limit');
        $formattedDate = $this->helperData->formatOrderItemDate($timeLimit);

        $mappedOrderStatuses = $this->data->getMappedOrderStatuses();

        $orderCollection = $this->orderFactory->create();
        $orderCollection
            ->addFieldToFilter('store_id', ['eq' => $storeId])
            ->addFieldToFilter(self::SYNCED_TO_YOTPO_ORDER, ['eq' => 0])
            ->addFieldToFilter('created_at', ['from' => $formattedDate]);
        if ($mappedOrderStatuses) {
            $orderCollection->addFieldToFilter('status', ['in' => array_keys($mappedOrderStatuses)]);
        }

        $orderCollection->getSelect()->limit($batchSize);
        $ordersWithMissedProducts = [];
        foreach ($orderCollection->getItems() as $order) {
            /** @phpstan-ignore-next-line */
            $productsMissing = $this->checkMissingProducts($order);
            if ($productsMissing) {
                $this->yotpoOrdersLogger->info('Products not exist for order  : ' . $order->getId(), []);
                $ordersWithMissedProducts[] = $order->getId();
                $ordersToUpdate[] = $order->getId();
                continue;
            }
            $orders[] = $order;
            $orderId = $order->getEntityId();
            $magentoOrders[$orderId] = $order;
            $orderIds[] = $orderId;
            $order->getCustomerId() ?
                $customerIds[] = $order->getCustomerId() :
                $guestOrderIds[] = $order->getEntityId();
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
                $magentoOrderId = $magentoOrder->getEntityId();
                $isYotpoSyncedOrder = false;
                if ($yotpoSyncedOrders && array_key_exists($magentoOrderId, $yotpoSyncedOrders)) {
                    $responseCode = $yotpoSyncedOrders[$magentoOrderId]['response_code'];
                    if (!$this->config->canResync($responseCode, $yotpoSyncedOrders[$magentoOrderId]['yotpo_id'])) {
                        $ordersToUpdate[] = $magentoOrderId;
                        $this->yotpoOrdersLogger->info('Order sync cannot be done for orderId: '
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
                    $yotpoTableFinalData[] = $yotpoTableData;
                    if ($this->config->canUpdateCustomAttribute($yotpoTableData['response_code'])) {
                        $ordersToUpdate[] = $magentoOrderId;
                    }
                }
            }
        }
        foreach ($ordersWithMissedProducts as $orderId) {
            $yotpoTableFinalData[] = $this->prepareYotpoTableDataForMissingProducts($orderId, $currentTime);
        }
        if ($yotpoTableFinalData) {
            $this->insertOrUpdateYotpoTableData($yotpoTableFinalData);
        }
        if ($ordersToUpdate) {
            $this->updateOrderAttribute($ordersToUpdate, self::SYNCED_TO_YOTPO_ORDER, 1);
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
            $this->yotpoOrdersLogger->info('Products not exist for order  : ' . $magentoOrderId, []);
            $yotpoTableFinalData[] = $this->prepareYotpoTableDataForMissingProducts($magentoOrderId, $currentTime);
            $this->insertOrUpdateYotpoTableData($yotpoTableFinalData);
            $this->updateOrderAttribute($ordersToUpdate, self::SYNCED_TO_YOTPO_ORDER, 1);
            $this->updateLastSyncDate($currentTime);
            $this->updateTotalOrdersSynced();
            return;
        }
        $mappedOrderStatuses = $this->data->getMappedOrderStatuses();
        if (!isset($mappedOrderStatuses[$magentoOrder->getStatus()])) {
            $this->yotpoOrdersLogger->info('Missing order status mapping for Order# ' . $magentoOrderId, []);
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
            $this->yotpoOrdersLogger->info('Order attribute updated to 0 for order : ' . $magentoOrderId, []);
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
                        $ordersToUpdate[] = $magentoOrderId;
                        $this->updateOrderAttribute($ordersToUpdate, self::SYNCED_TO_YOTPO_ORDER, 1);
                        $this->yotpoOrdersLogger->info('Order sync cannot be done for orderId: '
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
            $this->yotpoOrdersLogger->info('Last sync date updated for order : '
                . $magentoOrderId, []);
            if ($yotpoTableData) {
                if (!$yotpoTableData['yotpo_id'] && array_key_exists($magentoOrderId, $yotpoSyncedOrders)) {
                    $yotpoTableData['yotpo_id'] = $yotpoSyncedOrders[$magentoOrderId]['yotpo_id'];
                }
                $yotpoTableData['order_id'] = $magentoOrderId;
                $yotpoTableData['synced_to_yotpo'] = $currentTime;
                $yotpoTableFinalData[] = $yotpoTableData;
            }

            if ($yotpoTableFinalData) {
                $this->insertOrUpdateYotpoTableData($yotpoTableFinalData);
                if ($this->config->canUpdateCustomAttribute($yotpoTableData['response_code'])) {
                    $this->updateOrderAttribute($ordersToUpdate, self::SYNCED_TO_YOTPO_ORDER, 1);
                }
                $this->yotpoOrdersLogger->info('Order attribute updated to 1 for order : '
                    . $magentoOrderId, []);
            }

            $this->updateLastSyncDate($currentTime);
            $this->updateTotalOrdersSynced();
        } catch (NoSuchEntityException | LocalizedException $e) {
            $this->yotpoOrdersLogger->addError($e->getMessage());
        }
    }

    /**
     * Calls order sync api
     *
     * @param Order $order
     * @param bool $isYotpoSyncedOrder
     * @param array<mixed> $yotpoSyncedOrders
     * @param bool $realTImeSync
     * @return array<mixed>|DataObject
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function syncOrder($order, $isYotpoSyncedOrder, $yotpoSyncedOrders, $realTImeSync = false)
    {
        $orderIds = [];
        $incrementId = $order->getIncrementId();
        $orderId = $order->getEntityId();
        $dataType = $isYotpoSyncedOrder ? 'update' : 'create';
        $orderData = $this->data->prepareData($order, $dataType, $yotpoSyncedOrders);
        if (!$orderData) {
            $this->yotpoOrdersLogger->info('Orders sync - no new data to sync', []);
            return [];
        }
        $this->yotpoOrdersLogger->info('Orders sync - data prepared - Order ID - ' . $orderId, []);
        $productIds = $this->data->getLineItemsIds();
        if ($productIds) {
            $isProductSyncSuccess = $this->checkAndSyncProducts($productIds, $order);
            if (!$isProductSyncSuccess) {
                $this->yotpoOrdersLogger->info('Products sync failed - Order ID - ' . $order->getId(), []);
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
                ['external_ids' => $incrementId, 'entityLog' => 'orders']
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
        $response = $this->yotpoCoreSync->sync($method, $url, $orderData);
        if ($response->getData('is_success')) {
            if ($yotpoOrderId) {
                $response->setData('yotpo_id', $yotpoOrderId);
            }
            if ($realTImeSync) {
                $orderIds[] = $orderId;
                $this->updateOrderAttribute($orderIds, self::SYNCED_TO_YOTPO_ORDER, 1);
            }
            $this->yotpoOrdersLogger->info('Orders sync - success - ' . $orderId, []);
        } elseif ($response->getData('status') == 409) {//order already exists in Yotpo and not in custom table
            $response = $this->yotpoCoreSync->sync(
                'GET',
                $this->config->getEndpoint('orders'),
                ['external_ids' => $incrementId, 'entityLog' => 'orders']
            );
        }
        return $response;
    }

    /**
     * Check and sync the products if not already synced
     *
     * @param array <mixed> $productIds
     * @param Order $order
     * @return bool
     */
    public function checkAndSyncProducts($productIds, $order)
    {
        $unSyncedProductIds = $this->data->getUnSyncedProductIds($productIds, $order);
        if ($unSyncedProductIds) {
            $sync = $this->catalogProcessor->process($unSyncedProductIds, $order);
            $this->emulateFrontendArea($this->currentStoreId);
            return $sync;
        }
        return true;
    }

    /**
     * Update custom attribute - synced_to_yotpo_order
     *
     * @param array<int> $orderIds
     * @param string $attributeName
     * @param int $value
     * @return void
     */
    public function updateOrderAttribute($orderIds, $attributeName, $value)
    {
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
}
