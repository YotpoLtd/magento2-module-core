<?php

namespace Yotpo\Core\Model\Sync\Orders;

use Magento\Framework\DataObject;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Safe\Exceptions\DatetimeException;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\Orders\Data as OrdersData;
use Yotpo\Core\Model\Sync\Orders\Logger as YotpoOrdersLogger;
use function Safe\date;
use Yotpo\Core\Model\Api\Sync as YotpoCoreSync;
use Yotpo\Core\Helper\Data as CoreHelperData;
use Yotpo\Core\Model\Sync\Catalog\Processor as CatalogProcessor;

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
        CatalogProcessor $catalogProcessor
    ) {
        $this->yotpoCoreSync = $yotpoCoreSync;
        $this->orderFactory = $orderFactory;
        $this->yotpoOrdersLogger = $yotpoOrdersLogger;
        $this->helperData = $helperData;
        $this->catalogProcessor = $catalogProcessor;
        parent::__construct($appEmulation, $resourceConnection, $yotpoCoreConfig, $data);
    }

    /**
     * Process orders
     *
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws DatetimeException
     */
    public function process()
    {
        /** @phpstan-ignore-next-line */
        foreach ($this->config->getAllStoreIds(false) as $storeId) {
            $this->emulateFrontendArea((int)$storeId);
            if (!$this->config->isOrdersSyncActive()) {
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
     * @throws DatetimeException
     */
    public function processOrder($order)
    {
        $storeId = $order->getStoreId();
        $this->emulateFrontendArea((int)$storeId);
        if (!$this->config->isOrdersSyncActive()) {
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
     * @throws DatetimeException
     */
    public function processOrders()
    {
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
        foreach ($orderCollection->getItems() as $order) {
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
                    if (!$this->config->canResync($responseCode)) {
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
                $yotpoTableData = $response ? $this->prepareYotpoTableData($response) : false;
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
        if ($yotpoTableFinalData) {
            $this->insertOrUpdateYotpoTableData($yotpoTableFinalData);
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
     * @throws DatetimeException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function processSingleEntity($magentoOrder)
    {
        $magentoOrderId = $magentoOrder->getEntityId();
        $mappedOrderStatuses = $this->data->getMappedOrderStatuses();
        if (!isset($mappedOrderStatuses[$magentoOrder->getStatus()])) {
            $this->yotpoOrdersLogger->info('Missing order status mapping for Order# ' . $magentoOrderId, []);
            return;
        }
        $customerId = $magentoOrder->getCustomerId();
        $currentTime = date('Y-m-d H:i:s');
        $yotpoTableFinalData = [];
        $magentoOrders = [];
        $customerIds = [];
        $ordersToUpdate[] = $magentoOrderId;
        $couponCodes[] = $magentoOrder->getCouponCode();
        if ($customerId) {
            $customerIds[] = $customerId;
        }

        try {
            $this->updateOrderAttribute($ordersToUpdate, self::SYNCED_TO_YOTPO_ORDER, 0);
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
                    if (!$this->config->canResync($responseCode)) {
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

            $this->data->prepareShipmentStatuses($ordersToUpdate);

            $customerIds ? $this->data->prepareCustomAttributes($customerIds) :
                $this->data->prepareGuestUsersCustomAttributes($ordersToUpdate);

            $this->data->prepareCouponCodes($couponCodes);

            $response = $this->syncOrder($magentoOrder, $isYotpoSyncedOrder, $yotpoSyncedOrders);

            $yotpoTableData = $response ? $this->prepareYotpoTableData($response) : false;

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
        $orderData = $this->data->prepareData($order, $dataType);
        if (!$orderData) {
            $this->yotpoOrdersLogger->info('Orders sync - no new data to sync', []);
            return [];
        }
        $this->yotpoOrdersLogger->info('Orders sync - data prepared', []);
        $productIds = $this->data->getLineItemsIds();
        if ($productIds) {
            $this->checkAndSyncProducts($productIds);
        }
        if ($isYotpoSyncedOrder) {
            $yotpoOrderId = $yotpoSyncedOrders[$orderId]['yotpo_id'];
            $url = $this->config->getEndpoint('orders_update', ['{yotpo_order_id}'], [$yotpoOrderId]);
            $method = $this->config::METHOD_PATCH;
        } else {
            $url = $this->config->getEndpoint('orders');
            $method = $this->config::METHOD_POST;
        }

        $orderData['entityLog'] = 'orders';
        $response = $this->yotpoCoreSync->sync($method, $url, $orderData);
        if ($response->getData('is_success')) {
            if ($realTImeSync) {
                $orderIds[] = $orderId;
                $this->updateOrderAttribute($orderIds, self::SYNCED_TO_YOTPO_ORDER, 1);
            }
            $this->yotpoOrdersLogger->info('Orders sync - success', $orderData);
        } elseif ($response->getData('status') == 409) {//order already exists in Yotpo and not in custom table
            $response = $this->yotpoCoreSync->sync(
                'GET',
                $this->config->getEndpoint('orders'),
                ['external_ids' => $incrementId]
            );
        }
        return $response;
    }

    /**
     * Check and sync the products if not already synced
     *
     * @param array <mixed> $productIds
     * @return void
     */
    public function checkAndSyncProducts($productIds)
    {
        $unSyncedProductIds = $this->data->getUnSyncedProductIds($productIds);
        if ($unSyncedProductIds) {
            $this->catalogProcessor->process($unSyncedProductIds);
        }
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
            [$attributeName => $value],
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
}
