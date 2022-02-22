<?php
namespace Yotpo\Core\Model\Sync\Catalog;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use Yotpo\Core\Model\Config as CoreConfig;
use Yotpo\Core\Model\Sync\Catalog\Logger as YotpoCoreCatalogLogger;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Yotpo\Core\Model\Sync\Catalog\Data as CatalogData;
use Yotpo\Core\Model\Api\Sync as CoreSync;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Yotpo\Core\Model\Sync\Category\Processor\ProcessByProduct as CategorySyncProcessor;
use Magento\Quote\Model\Quote;
use Yotpo\Core\Model\Sync\Data\Main as SyncDataMain;
use Yotpo\Core\Model\Sync\Catalog\Processor\Main;
use Yotpo\Core\Api\ProductSyncRepositoryInterface;

/**
 * Class Processor - Process catalog sync
 */
class Processor extends Main
{
    /**
     * @var CatalogData
     */
    protected $catalogData;

    /**
     * @var SyncDataMain
     */
    protected $syncDataMain;

    /**
     * @var DateTime
     */
    protected $dateTime;

    /**
     * @var CategorySyncProcessor
     */
    protected $categorySyncProcessor;

    /**
     * @var ProductSyncRepositoryInterface
     */
    protected $productSyncRepositoryInterface;

    /**
     * @var array<mixed>
     */
    protected $retryItems = [];

    /**
     * @var bool
     */
    protected $isCommandLineSync = false;

    /**
     * @var bool
     */
    protected $isImmediateRetry = false;

    /**
     * Processor constructor.
     * @param AppEmulation $appEmulation
     * @param CoreConfig $coreConfig
     * @param YotpoCoreCatalogLogger $yotpoCatalogLogger
     * @param CatalogData $catalogData
     * @param CoreSync $coreSync
     * @param ResourceConnection $resourceConnection
     * @param CollectionFactory $collectionFactory
     * @param DateTime $dateTime
     * @param YotpoResource $yotpoResource ,
     * @param CategorySyncProcessor $categorySyncProcessor
     * @param ProductSyncRepositoryInterface $productSyncRepositoryInterface
     * @param SyncDataMain $syncDataMain
     */
    public function __construct(
        AppEmulation $appEmulation,
        CoreConfig $coreConfig,
        YotpoCoreCatalogLogger $yotpoCatalogLogger,
        CatalogData $catalogData,
        CoreSync $coreSync,
        ResourceConnection $resourceConnection,
        CollectionFactory $collectionFactory,
        DateTime $dateTime,
        YotpoResource $yotpoResource,
        CategorySyncProcessor $categorySyncProcessor,
        ProductSyncRepositoryInterface $productSyncRepositoryInterface,
        SyncDataMain $syncDataMain
    ) {
        parent::__construct(
            $appEmulation,
            $resourceConnection,
            $coreConfig,
            $yotpoCatalogLogger,
            $yotpoResource,
            $collectionFactory,
            $coreSync
        );
        $this->catalogData = $catalogData;
        $this->dateTime = $dateTime;
        $this->categorySyncProcessor = $categorySyncProcessor;
        $this->productSyncRepositoryInterface = $productSyncRepositoryInterface;
        $this->syncDataMain = $syncDataMain;
    }

    /**
     * Process the Catalog Api
     * @param array <mixed> $forceSyncProducts
     * @param array <mixed> $storeIds
     * @return bool
     */
    public function process($forceSyncProducts = [], $storeIds = [])
    {
        try {
            $allStores = array_unique($storeIds) ?: (array)$this->coreConfig->getAllStoreIds(false);
            $unSyncedStoreIds = [];
            foreach ($allStores as $storeId) {
                if ($this->isCommandLineSync) {
                    // phpcs:ignore
                    echo 'Catalog process started for store - ' .
                        $this->coreConfig->getStoreName($storeId) . PHP_EOL;
                }
                $this->emulateFrontendArea($storeId);
                try {
                    $disabled = false;
                    if (!$this->coreConfig->isEnabled()) {
                        $disabled = true;
                        $this->yotpoCatalogLogger->info(
                            __(
                                'Product Sync - Yotpo is Disabled - Magento Store ID: %1, Name: %2',
                                $storeId,
                                $this->coreConfig->getStoreName($storeId)
                            )
                        );
                        if ($this->isCommandLineSync) {
                            // phpcs:ignore
                            echo 'Yotpo is disabled for store - ' .
                                $this->coreConfig->getStoreName($storeId) . PHP_EOL;
                        }
                    }
                    if ($this->isSyncingAsMainEntity() && !$this->coreConfig->isCatalogSyncActive()) {
                        $disabled = true;
                        $this->yotpoCatalogLogger->info(
                            __(
                                'Product Sync - Disabled - Magento Store ID: %1, Name: %2',
                                $storeId,
                                $this->coreConfig->getStoreName($storeId)
                            )
                        );
                        if ($this->isCommandLineSync) {
                            // phpcs:ignore
                            echo 'Catalog sync is disabled for store - ' .
                                $this->coreConfig->getStoreName($storeId) . PHP_EOL;
                        }
                    }
                    if ($disabled) {
                        $this->stopEnvironmentEmulation();
                        continue;
                    }
                    $this->productSyncLimit = $this->coreConfig->getConfig('product_sync_limit');
                    $this->yotpoCatalogLogger->info(
                        __(
                            'Product Sync - Start - Magento Magento Store ID: %1, Name: %2',
                            $storeId,
                            $this->coreConfig->getStoreName($storeId)
                        )
                    );
                    if ($this->isSyncingAsMainEntity()) {
                        $this->processDeleteData();
                        $this->processUnAssignData();
                    }
                    $this->retryItems[$storeId] = [];
                    if ($this->productSyncLimit > 0) {
                        $forceSyncProductIds = $forceSyncProducts[$storeId] ?? $forceSyncProducts;
                        $collection = $this->getCollectionForSync($forceSyncProductIds);
                        $this->isImmediateRetry = false;
                        $this->syncItems($collection->getItems(), $storeId);
                    } else {
                        $this->yotpoCatalogLogger->info(
                            __('Product Sync - Stopped - Magento Store ID: %1', $storeId)
                        );
                    }
                } catch (\Exception $e) {
                    $unSyncedStoreIds[] = $storeId;
                    $this->yotpoCatalogLogger->info(
                        __(
                            'Product Sync has stopped with exception: %1, Magento Store ID: %2, Name: %3',
                            $e->getMessage(),
                            $storeId,
                            $this->coreConfig->getStoreName($storeId)
                        )
                    );
                }
                $this->stopEnvironmentEmulation();
            }
            $this->stopEnvironmentEmulation();
            if (!$this->isSyncingAsMainEntity() && count($unSyncedStoreIds) > 0) {
                return false;
            } else {
                return true;
            }
        } catch (\Exception $e) {
            $this->yotpoCatalogLogger->info(
                __('Catalog sync::process() - Exception: ', [$e->getTraceAsString()])
            );
            return false;
        }
    }

    /**
     * @param array <mixed> $collectionItems
     * @param int $storeId
     * @param boolean $isVisibleVariantsSync
     * @return void
     * @throws NoSuchEntityException
     */
    public function syncItems($collectionItems, $storeId, $isVisibleVariantsSync = false)
    {
        if (!count($collectionItems)) {
            $this->yotpoCatalogLogger->info(
                __(
                    'Product Sync complete : No Data, Magento Store ID: %1, Name: %2',
                    $storeId,
                    $this->coreConfig->getStoreName($storeId)
                )
            );
            return;
        }

        $syncedToYotpoProductAttributeId = $this->catalogData->getAttributeId(CoreConfig::CATALOG_SYNC_ATTR_CODE);
        $items = $this->getSyncItems($collectionItems, $isVisibleVariantsSync);
        $parentItemsIds = $items['parent_ids'];
        $yotpoSyncTableItemsData = $items['yotpo_data'];
        $parentItemsData = $items['parent_data'];

        $syncTableRecordsUpdated = [];
        $externalIds = [];
        $visibleVariantsData = $isVisibleVariantsSync ? [] : $items['visible_variants'];
        $visibleVariantsDataValues = array_values($visibleVariantsData);

        $itemsToBeSyncedToYotpo = $items['sync_data'];
        foreach ($itemsToBeSyncedToYotpo as $itemEntityId => $yotpoFormatItemData) {
            $itemRowId = $yotpoFormatItemData['row_id'];
            unset($yotpoFormatItemData['row_id']);

            $attributeDataToUpdate = $this->prepareAttributeDataToUpdate($storeId, $itemRowId, $syncedToYotpoProductAttributeId);

            if ($yotpoSyncTableItemsData
                && array_key_exists($itemEntityId, $yotpoSyncTableItemsData)
                && $this->isSyncingAsMainEntity()
                && !$this->shouldItemBeResynced($yotpoSyncTableItemsData[$itemEntityId])
            ) {
                $this->updateProductSyncAttribute($attributeDataToUpdate);
                continue;
            }

            $apiRequestParams = $this->getApiParams($itemEntityId, $yotpoSyncTableItemsData, $parentItemsIds, $parentItemsData, $isVisibleVariantsSync);

            if (!$apiRequestParams) {
                $parentProductId = $parentItemsIds[$itemEntityId] ?? 0;
                if ($parentProductId) {
                    continue;
                }
            }

            $this->yotpoCatalogLogger->info(
                __(
                    'Data ready to sync - Method: %1 - Magento Store ID: %2, Name: %3',
                    $apiRequestParams['method'],
                    $storeId,
                    $this->coreConfig->getStoreName($storeId)
                )
            );

            $response = $this->processRequest($apiRequestParams, $yotpoFormatItemData);

            $yotpoIdKey = $isVisibleVariantsSync ? 'visible_variant_yotpo_id' : 'yotpo_id';
            $yotpoIdValue = $apiRequestParams['yotpo_id'] ?: 0;
            $responseCode = $response->getData('status');
            $syncDataRecordToUpdate = $this->prepareSyncTableDataToUpdate($itemEntityId, $yotpoIdKey, $yotpoIdValue, $storeId, $responseCode);
            if (!$isVisibleVariantsSync) {
                $syncDataRecordToUpdate['yotpo_id_parent'] = $apiRequestParams['yotpo_id_parent'] ?: 0;
            }
            if ($this->coreConfig->canUpdateCustomAttributeForProducts($syncDataRecordToUpdate['response_code'])) {
                if ($this->isSyncingAsMainEntity()) {
                    $this->updateProductSyncAttribute($attributeDataToUpdate);
                }
            }

            $returnResponse = $this->processResponse(
                $apiRequestParams,
                $response,
                $syncDataRecordToUpdate,
                $yotpoFormatItemData,
                $externalIds,
                $isVisibleVariantsSync
            );

            $processedSyncDataRecordToUpdate = $returnResponse['temp_sql'];
            $externalIds = $returnResponse['external_id'];

            if (isset($this->retryItems[$storeId][$itemEntityId])) {
                unset($this->retryItems[$storeId][$itemEntityId]);
            }

            if (count($returnResponse['four_not_four_data'])) {
                foreach ($returnResponse['four_not_four_data'] as $retryId) {
                    if ($this->isImmediateRetry($response, $this->entity, $isVisibleVariantsSync.$retryId, $storeId)) {
                        $this->retryItems[$storeId][$retryId] = $retryId;
                    }
                }
            }

            //push to parentData array if parent product is
            // being the part of current collection
            if (!$isVisibleVariantsSync) {
                $parentItemsData = $this->pushParentData((int)$itemEntityId, $processedSyncDataRecordToUpdate, $parentItemsData, $parentItemsIds);
            }

            if ($processedSyncDataRecordToUpdate) {
                $this->updateSyncTable($processedSyncDataRecordToUpdate);
                $syncTableRecordsUpdated[] = $processedSyncDataRecordToUpdate;
            }

            if ($this->isCommandLineSync && !$this->isImmediateRetry) {
                // phpcs:ignore
                echo 'Catalog process completed for productid - ' . $itemEntityId . PHP_EOL;
            }
        }
        $dataToSent = [];
        if (count($syncTableRecordsUpdated)) {
            $dataToSent = array_merge($dataToSent, $this->catalogData->filterDataForCatSync($syncTableRecordsUpdated));
        }

        if ($externalIds) {
            $yotpoExistingProducts = $this->processExistData(
                $externalIds,
                $parentItemsData,
                $parentItemsIds,
                $isVisibleVariantsSync
            );
            if ($yotpoExistingProducts && $this->isSyncingAsMainEntity()) {
                $dataToSent = array_merge(
                    $dataToSent,
                    $this->catalogData->filterDataForCatSync($yotpoExistingProducts)
                );
            }
        }

        if ($this->isSyncingAsMainEntity()) {
            $this->coreConfig->saveConfig('catalog_last_sync_time', $this->getCurrentTime());
            $dataForCategorySync = [];
            if ($dataToSent && !$isVisibleVariantsSync) {
                $dataForCategorySync = $this->getProductsForCategorySync(
                    $dataToSent,
                    $collectionItems,
                    $dataForCategorySync
                );
            }
            if (count($dataForCategorySync) > 0) {
                $this->categorySyncProcessor->process($dataForCategorySync);
            }
        }

        $reSyncYotpoKey = $isVisibleVariantsSync ? 'visible_variant_yotpo_id' : 'yotpo_id';
        if (isset($this->retryItems[$storeId]) && count($this->retryItems[$storeId]) > 0) {
            $this->update(
                'yotpo_product_sync',
                [$reSyncYotpoKey => 0, 'response_code' => coreConfig::CUSTOM_RESPONSE_DATA],
                ['product_id' . ' IN (?)' => $this->retryItems[$storeId], 'store_id = ?' => $storeId]
            );
            $collection = $this->getCollectionForSync($this->retryItems[$storeId]);
            $this->isImmediateRetry = true;
            $this->syncItems($collection->getItems(), $storeId, $isVisibleVariantsSync);
        }

        if ($visibleVariantsDataValues && !$isVisibleVariantsSync) {
            $this->syncItems($visibleVariantsDataValues, $storeId, true);
        }
    }

    /**
     * Notify to API that the product is deleted from Magento Catalog
     * @return void
     * @throws NoSuchEntityException
     */
    protected function processDeleteData()
    {
        $storeId = $this->coreConfig->getStoreId();
        $data = $this->getToDeleteCollection($storeId);
        $dataCount = count($data);
        if ($dataCount > 0) {
            foreach ($data as $itemId => $itemData) {
                $tempDeleteQry = [
                    'product_id' => $itemId,
                    'is_deleted_at_yotpo' => 0,
                    'store_id' => $storeId
                ];
                if (!$itemData['yotpo_id']) {
                    $tempDeleteQry['is_deleted_at_yotpo'] = 1;
                    $this->updateSyncTable($tempDeleteQry);
                    continue;
                }

                $apiParam = [];
                if (isset($itemData['yotpo_id_parent'])) {
                    $apiParam = $itemData;
                }

                $params = $this->getDeleteApiParams($itemData, 'yotpo_id');
                $itemDataRequest = ['is_discontinued' => true];
                $response = $this->processRequest($params, $itemDataRequest);
                $returnResponse = $this->processResponse($params, $response, $tempDeleteQry, $itemData);

                if ($this->isImmediateRetryResponse($response->getData('status'))) {
                    $response = $this->processDeleteRetry($params, $apiParam, $itemData, $itemId);
                    $returnResponse = $this->processResponse($params, $response, $tempDeleteQry, $itemData);
                }

                $this->updateSyncTable($returnResponse['temp_sql']);
            }
            $this->updateProductSyncLimit($dataCount);
        }
    }

    /**
     * @return void
     * @throws NoSuchEntityException
     */
    protected function processUnAssignData()
    {
        $storeId = $this->coreConfig->getStoreId();
        $data = $this->getUnAssignedCollection($storeId);
        $dataCount = count($data);
        if ($dataCount > 0) {
            foreach ($data as $itemId => $itemData) {
                $tempDeleteQry = [
                    'product_id' => $itemId,
                    'is_deleted_at_yotpo' => 0,
                    'yotpo_id_unassign' => $itemData['yotpo_id_unassign'],
                    'store_id' => $storeId
                ];

                $apiParam = [];
                if (isset($itemData['yotpo_id_parent'])) {
                    $apiParam = $itemData;
                }

                $params = $this->getDeleteApiParams($itemData, 'yotpo_id_unassign');
                $itemDataRequest = ['is_discontinued' => true];
                $response = $this->processRequest($params, $itemDataRequest);
                $returnResponse = $this->processResponse($params, $response, $tempDeleteQry, $itemData);

                if ($this->isImmediateRetryResponse($response->getData('status'))) {
                    $response = $this->processDeleteRetry($params, $apiParam, $itemData, $itemId);
                    $returnResponse = $this->processResponse($params, $response, $tempDeleteQry, $itemData);
                }

                $this->updateSyncTable($returnResponse['temp_sql']);
            }
            $this->updateProductSyncLimit($dataCount);
        }
    }

    /**
     * @param array <mixed> $items
     * @param boolean $isVariantsDataIncluded
     * @return array <mixed>
     * @throws NoSuchEntityException
     */
    protected function getSyncItems($items, $isVariantsDataIncluded): array
    {
        return $this->catalogData->getSyncItems($items, $isVariantsDataIncluded);
    }

    /**
     * Get current time
     *
     * @return string
     */
    protected function getCurrentTime()
    {
        return $this->dateTime->gmtDate();
    }

    /**
     * Push Yotpo Id to Parent data
     * @param int $productId
     * @param array<string, int|string> $tempSqlArray
     * @param array<int|string, mixed> $parentData
     * @param array<int, int> $parentIds
     * @return array<int|string, mixed>
     */
    protected function pushParentData($productId, $tempSqlArray, $parentData, $parentIds)
    {
        $yotpoId = 0;
        if (isset($tempSqlArray['yotpo_id'])
            && $tempSqlArray['yotpo_id']) {
            if (!isset($parentData[$productId])) {
                $parentId = $this->findParentId($productId, $parentIds);
                if ($parentId) {
                    $yotpoId = $tempSqlArray['yotpo_id'];
                }
            }
        }
        if ($yotpoId) {
            $parentData[$productId] = [
                'product_id' => $productId,
                'yotpo_id' => $yotpoId
            ];
        }

        return $parentData;
    }

    /**
     * Find it parent ID is exist in the synced data
     * @param int $productId
     * @param array<int, int> $parentIds
     * @return false|int|string
     */
    protected function findParentId($productId, $parentIds)
    {
        return array_search($productId, $parentIds);
    }

    /**
     * Fetch Existing data and update the product_sync table
     *
     * @param array<int, int|string> $externalIds
     * @param array<int|string, mixed> $parentData
     * @param array<int, int> $parentIds
     * @param boolean $visibleVariants
     * @return array<mixed>
     * @throws NoSuchEntityException
     */
    protected function processExistData(
        array $externalIds,
        array $parentData,
        array $parentIds,
        $visibleVariants = false
    ) {
        $sqlData = $filters = [];
        if (count($externalIds) > 0) {
            foreach ($externalIds as $externalId) {
                if (isset($parentIds[$externalId]) && $parentIds[$externalId] && !$visibleVariants) {
                    if (isset($parentData[$parentIds[$externalId]]) &&
                        isset($parentData[$parentIds[$externalId]]['yotpo_id']) &&
                        $parentYotpoId = $parentData[$parentIds[$externalId]]['yotpo_id']) {
                        $filters['variants'][$parentYotpoId][] = $externalId;
                    } else {
                        $filters['products'][] = $externalId;
                    }
                } else {
                    $filters['products'][] = $externalId;
                }
            }
            foreach ($filters as $key => $filter) {
                if (($key === 'products') && (count($filter) > 0)) {
                    $requestIds = implode(',', $filter);
                    $url = $this->coreConfig->getEndpoint('products');
                    $sqlData = $this->existDataRequest($url, $requestIds, $sqlData, 'products', $visibleVariants);
                }

                if (($key === 'variants') && (count($filter) > 0)) {
                    foreach ($filter as $parent => $variant) {

                        $requestIds = implode(',', (array)$variant);
                        $url = $this->coreConfig->getEndpoint(
                            'variant',
                            ['{yotpo_product_id}'],
                            [$parent]
                        );
                        $sqlData = $this->existDataRequest($url, $requestIds, $sqlData, 'variants', $visibleVariants);
                    }
                }
            }
            if (count($sqlData)) {
                $this->insertOnDuplicate(
                    'yotpo_product_sync',
                    $sqlData
                );
            }
        }
        return $sqlData;
    }

    /**
     * Process the existing data from Yotpo
     *
     * @param string $url
     * @param string $requestIds
     * @param array<int, string|int> $sqlData
     * @param string $type
     * @param boolean $visibleVariants
     * @return array<int, mixed>
     * @throws NoSuchEntityException
     */
    protected function existDataRequest($url, $requestIds, $sqlData, $type = 'products', $visibleVariants = false)
    {
        $products = $this->getExistingProductsFromAPI($url, $requestIds, $type);
        if ($products) {
            foreach ($products as $product) {
                $parentId = 0;
                if (isset($product['yotpo_product_id']) && $product['yotpo_product_id']) {
                    $parentId = $product['yotpo_product_id'];
                }
                $yotpoIdKey = $visibleVariants ? 'visible_variant_yotpo_id' : 'yotpo_id';

                $responseCode = '200';
                $sqlData[] = $this->prepareSyncTableDataToUpdate($product['external_id'], $yotpoIdKey, $product['yotpo_id'], $this->coreConfig->getStoreId(), $responseCode);
            }
        }
        return $sqlData;
    }

    /**
     * Prepare products to sync their category data
     * @param array<mixed> $data
     * @param array<mixed> $collectionItems
     * @param array<mixed> $dataForCategorySync
     * @return array<mixed>
     */
    protected function getProductsForCategorySync($data, $collectionItems, array $dataForCategorySync): array
    {
        $storeId = $this->coreConfig->getStoreId();
        $retryItems = [];
        if ($this->retryItems && isset($this->retryItems[$storeId])) {
            $retryItems = $this->retryItems[$storeId];
        }
        foreach ($data as $dataItem) {
            foreach ($collectionItems as $item) {
                if ($item->getId() == $dataItem['product_id'] &&
                    !isset($retryItems[$item->getId()])
                ) {
                    $dataForCategorySync[$dataItem['yotpo_id']] = $item;
                    break;
                }
            }
        }
        return $dataForCategorySync;
    }

    /**
     * @return void
     */
    public function retryProductSync()
    {
        $this->isCommandLineSync = true;
        $productIds = [];
        $storeIds = [];
        $productByStore = [];
        $items = $this->productSyncRepositoryInterface->getByResponseCodes();
        foreach ($items as $item) {
            $productIds[] = $item['product_id'];
            $storeIds[] = $item['store_id'];
            $productByStore[$item['store_id']][] = $item['product_id'];
        }
        if ($productIds) {
            $this->process($productByStore, array_unique($storeIds));
        } else {
            // phpcs:ignore
            echo 'No catalog data to process.' . PHP_EOL;
        }
    }

    /**
     * Check and sync the products if not already synced
     *
     * @param array <mixed> $productIds
     * @param array <mixed> $visibleItems
     * @param int|null $storeId
     * @return bool
     */
    public function syncProducts($productIds, $visibleItems, $storeId)
    {
        $unSyncedProductIds = $this->getUnSyncedProductIds($productIds, $visibleItems, $storeId);
        if ($unSyncedProductIds) {
            $this->setNormalSyncFlag(false);
            $unSyncedProductIds = [$storeId => $unSyncedProductIds];
            $sync = $this->process($unSyncedProductIds, [$storeId]);
            $this->emulateFrontendArea($storeId);
            return $sync;
        }
        return true;
    }

    /**
     * Get the productIds od the products that are not synced
     *
     * @param array <mixed> $productIds
     * @param array <mixed> $visibleItems
     * @param int|null $storeId
     * @return mixed
     */
    public function getUnSyncedProductIds($productIds, $visibleItems, $storeId)
    {
        $itemsMap = [];
        foreach ($visibleItems as $visibleItem) {
            $product = $visibleItem->getProduct();
            if (!$product) {
                continue;
            }
            $itemsMap[$product->getId()] = $product;
        }
        return $this->syncDataMain->getProductIds($productIds, $storeId, $itemsMap);
    }

    /**
     * @param array $yotpoSyncTableItemData
     * @return bool
     */
    private function shouldItemBeResynced($yotpoSyncTableItemData)
    {
        return $this->coreConfig->canResync($yotpoSyncTableItemData['response_code'], $yotpoSyncTableItemData, $this->isCommandLineSync);
    }

    /**
     * @param integer $storeId
     * @param integer $itemRowId
     * @param integer $syncedToYotpoProductAttributeId
     * @return array
     */
    private function prepareAttributeDataToUpdate($storeId, $itemRowId, $syncedToYotpoProductAttributeId)
    {
        return [
            'attribute_id' => $syncedToYotpoProductAttributeId,
            'store_id' => $storeId,
            'value' => 1,
            $this->entityIdFieldValue => $itemRowId
        ];
    }

    /**
     * @param integer $itemEntityId
     * @param string $yotpoIdKey
     * @param integer $yotpoIdValue
     * @param integer $storeId
     * @param integer $responseCode
     * @param integer $yotpoParentId
     * @return array
     */
    private function prepareSyncTableDataToUpdate($itemEntityId, $yotpoIdKey, $yotpoIdValue, $storeId, $responseCode, $yotpoParentId = null)
    {
        $lastSyncTime = $this->getCurrentTime();
        return [
            'product_id' => $itemEntityId,
            $yotpoIdKey => $yotpoIdValue,
            'yotpo_id_parent' => $yotpoParentId,
            'store_id' => $storeId,
            'synced_to_yotpo' => $lastSyncTime,
            'response_code' => $responseCode
        ];
    }

    /**
     * @param array $attributeDataToUpdate
     * @return void
     */
    private function updateProductSyncAttribute($attributeDataToUpdate) {
        $this->insertOnDuplicate(
            'catalog_product_entity_int',
            [$attributeDataToUpdate]
        );
    }

    /**
     * @param array $syncDataRecord
     * @return void
     */
    private function updateSyncTable($syncDataRecord) {
        $this->insertOnDuplicate(
            'yotpo_product_sync',
            [$syncDataRecord]
        );
    }
}
