<?php
namespace Yotpo\Core\Model\Sync\Catalog;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Yotpo\Core\Model\Config as CoreConfig;
use Yotpo\Core\Model\Sync\Catalog\Logger as YotpoCoreCatalogLogger;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Yotpo\Core\Model\Sync\Catalog\Data as CatalogData;
use Yotpo\Core\Model\Api\Sync as CoreSync;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Yotpo\Core\Model\Sync\Catalog\Processor\CatalogRequestHandler;
use Yotpo\Core\Model\Sync\Category\Processor\ProcessByProduct as CategorySyncProcessor;
use Yotpo\Core\Model\Sync\Data\Main as SyncDataMain;
use Yotpo\Core\Model\Sync\Catalog\Processor\Main;
use Yotpo\Core\Api\ProductSyncRepositoryInterface;
use Yotpo\Core\Model\Sync\CollectionsProducts\Services\CollectionsProductsService;
use Yotpo\Core\Model\Sync\Catalog\Services\ProductsSyncService;

/**
 * Class Processor - Process catalog sync
 */
class Processor extends Main
{
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
     * @var CollectionsProductsService
     */
    protected $collectionsProductsService;

    /**
     * @var ProductsSyncService
     */
    protected $productsSyncService;

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
     * @param CollectionsProductsService $collectionsProductsService
     * @param ProductsSyncService $productsSyncService
     * @param CatalogRequestHandler $catalogRequestHandler
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
        SyncDataMain $syncDataMain,
        CollectionsProductsService $collectionsProductsService,
        ProductsSyncService $productsSyncService,
        CatalogRequestHandler $catalogRequestHandler
    ) {
        parent::__construct(
            $appEmulation,
            $resourceConnection,
            $coreConfig,
            $yotpoCatalogLogger,
            $yotpoResource,
            $collectionFactory,
            $coreSync,
            $catalogData,
            $catalogRequestHandler
        );
        $this->dateTime = $dateTime;
        $this->categorySyncProcessor = $categorySyncProcessor;
        $this->productSyncRepositoryInterface = $productSyncRepositoryInterface;
        $this->syncDataMain = $syncDataMain;
        $this->collectionsProductsService = $collectionsProductsService;
        $this->productsSyncService = $productsSyncService;
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
                    if ($this->coreConfig->isSyncResetInProgress($storeId, 'catalog')) {
                        $disabled = true;
                        $this->yotpoCatalogLogger->infoLog(
                            __(
                                'Product sync is skipped because catalog sync reset is in progress
                         - Magento Store ID: %1, Name: %2',
                                $storeId,
                                $this->coreConfig->getStoreName($storeId)
                            )
                        );
                    }
                    if (!$this->coreConfig->isEnabled()) {
                        $disabled = true;
                        $this->yotpoCatalogLogger->infoLog(
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
                        $this->yotpoCatalogLogger->infoLog(
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
                    $this->yotpoCatalogLogger->infoLog(
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

                        $hasFailedCreatingAnyProduct = $this->syncItems($collection->getItems(), $storeId);
                        if ($hasFailedCreatingAnyProduct) {
                            $unSyncedStoreIds[] = $storeId;
                        }
                    } else {
                        $this->yotpoCatalogLogger->infoLog(
                            __('Product Sync - Stopped - Magento Store ID: %1', $storeId)
                        );
                    }
                } catch (\Exception $e) {
                    $unSyncedStoreIds[] = $storeId;
                    $this->yotpoCatalogLogger->infoLog(
                        __(
                            'Product Sync has stopped with exception: %1, Magento Store ID: %2, Name: %3, Trace: %4',
                            $e->getMessage(),
                            $storeId,
                            $this->coreConfig->getStoreName($storeId),
                            $e->getTraceAsString()
                        )
                    );
                }
                $this->stopEnvironmentEmulation();
            }
            $this->stopEnvironmentEmulation();
            if (count($unSyncedStoreIds) > 0) {
                return false;
            } else {
                return true;
            }
        } catch (\Exception $e) {
            $this->yotpoCatalogLogger->infoLog(
                __('Catalog sync::process() - Exception: ', [$e->getTraceAsString()])
            );
            return false;
        }
    }

    /**
     * @param array <mixed> $collectionItems
     * @param int $storeId
     * @param boolean $isVisibleVariantsSync
     * @return bool|void
     * @throws NoSuchEntityException
     */
    public function syncItems($collectionItems, $storeId, $isVisibleVariantsSync = false)
    {
        if (!count($collectionItems)) {
            $this->yotpoCatalogLogger->infoLog(
                __(
                    'Product Sync complete : No Data, Magento Store ID: %1, Name: %2',
                    $storeId,
                    $this->coreConfig->getStoreName($storeId)
                )
            );
            return;
        }

        $hasFailedCreatingAnyProduct = false;
        $syncedToYotpoProductAttributeId = $this->catalogData->getAttributeId(CoreConfig::CATALOG_SYNC_ATTR_CODE);
        $items = $this->getSyncItems($collectionItems, $isVisibleVariantsSync);
        foreach ($items['failed_variants_ids'] as $failedVariantId) {
            $this->updateProductSyncAttribute($storeId, $failedVariantId);
        }
        $parentItemsIds = $items['parents_ids'];
        $yotpoSyncTableItemsData = $items['yotpo_data'];

        $yotpoIdKey = $isVisibleVariantsSync ? 'visible_variant_yotpo_id' : 'yotpo_id';
        $syncTableRecordsUpdated = [];
        $visibleVariantsData = $isVisibleVariantsSync ? [] : $items['visible_variants'];
        $visibleVariantsDataValues = array_values($visibleVariantsData);

        $itemsToBeSyncedToYotpo = $items['sync_data'];
        foreach ($itemsToBeSyncedToYotpo as $itemEntityId => $yotpoFormatItemData) {
            $itemRowId = $yotpoFormatItemData['row_id'];
            try {
                unset($yotpoFormatItemData['row_id']);
                if ($yotpoSyncTableItemsData
                    && array_key_exists($itemEntityId, $yotpoSyncTableItemsData)
                    && $this->isSyncingAsMainEntity()
                    && !$this->shouldItemBeResynced($yotpoSyncTableItemsData[$itemEntityId])
                ) {
                    $this->updateProductSyncAttribute($storeId, $itemRowId);
                    continue;
                }
                if (!$isVisibleVariantsSync && isset($parentItemsIds[$itemEntityId])) {
                    $parentItemId = $parentItemsIds[$itemEntityId];

                    if (!$this->isProductParentYotpoIdFound($yotpoSyncTableItemsData, $parentItemId)) {
                        $parentProductData = $this->getCollectionForSync([$parentItemId])->getItems();
                        if (!$parentProductData) {
                            $this->yotpoCatalogLogger->infoLog(
                                __(
                                    'Skipping variant sync, parent product not found - Store ID: %1, Store Name: %2, Item Entity ID: %3, Parent Entity ID: %4',
                                    $storeId,
                                    $this->coreConfig->getStoreName($storeId),
                                    $itemRowId,
                                    $parentItemId
                                )
                            );

                            $this->updateProductSyncAttribute($storeId, $itemRowId);
                            continue;
                        }

                        $updatedParentYotpoId = $this->forceParentProductSyncToYotpo(
                            $storeId,
                            $itemEntityId,
                            $parentItemId,
                            $parentProductData,
                            $yotpoSyncTableItemsData,
                            $parentItemsIds,
                            $yotpoFormatItemData
                        );

                        if (!$updatedParentYotpoId) {
                            continue;
                        }
                    } elseif ($this->isProductParentYotpoIdChanged($itemEntityId, $parentItemId, $yotpoSyncTableItemsData)) {
                        $parentProductYotpoId = $yotpoSyncTableItemsData[$parentItemId]['yotpo_id'];
                        $yotpoSyncTableItemsData[$itemEntityId]['yotpo_id_parent'] = $parentProductYotpoId;

                        $this->yotpoCatalogLogger->infoLog(
                            __(
                                'Yotpo ID of parent product changed - Store ID: %1, Store Name: %2, Parent Entity ID: %3, New Yotpo ID: %4',
                                $storeId,
                                $this->coreConfig->getStoreName($storeId),
                                $parentItemId,
                                $parentProductYotpoId
                            )
                        );
                    }
                }

                $apiRequestParams = $this->getApiParams(
                    $itemEntityId,
                    $yotpoSyncTableItemsData,
                    $parentItemsIds,
                    $isVisibleVariantsSync
                );

                if (!$apiRequestParams) {
                    $parentProductId = $parentItemsIds[$itemEntityId] ?? 0;
                    if ($parentProductId) {
                        continue;
                    }
                }

                $this->yotpoCatalogLogger->infoLog(
                    __(
                        'Data ready to sync - Method: %1 - Magento Store ID: %2, Name: %3',
                        $apiRequestParams['method'],
                        $storeId,
                        $this->coreConfig->getStoreName($storeId)
                    )
                );

                $currentItemYotpoId = $apiRequestParams['yotpo_id'];
                $responseObject = $this->handleRequest($itemEntityId, $yotpoFormatItemData, $apiRequestParams);
                $response = $responseObject['response'];
                $apiRequestParams['method'] = $responseObject['method'];
                $apiRequestParams['yotpo_id'] = $responseObject['yotpo_id'];
                $responseCode = $response->getData('status');

                if ($apiRequestParams['method'] == 'createProduct' && !$response->getData('is_success')) {
                    $hasFailedCreatingAnyProduct = true;
                }

                if ($this->isVariantUpsertSyncMethod($apiRequestParams['method']) && $responseCode == CoreConfig::NOT_FOUND_RESPONSE_CODE) {
                    $parentItemId = $parentItemsIds[$itemEntityId];
                    $parentProductData = $this->getCollectionForSync([$parentItemId])->getItems();
                    $updatedParentYotpoId = $this->forceParentProductSyncToYotpo(
                        $storeId,
                        $itemEntityId,
                        $parentItemId,
                        $parentProductData,
                        $yotpoSyncTableItemsData,
                        $parentItemsIds,
                        $yotpoFormatItemData
                    );

                    if ($updatedParentYotpoId && $apiRequestParams['yotpo_id_parent'] != $updatedParentYotpoId) {
                        $this->resetVariantsSyncWithUpdatedParentYotpoId(
                            $storeId,
                            $parentItemsIds[$itemEntityId],
                            $apiRequestParams['yotpo_id_parent'],
                            $updatedParentYotpoId,
                            $syncedToYotpoProductAttributeId
                        );
                        continue;
                    }
                }

                $yotpoIdValue = $apiRequestParams['yotpo_id'] ?: 0;
                $syncDataRecordToUpdate = $this->prepareSyncTableDataToUpdate(
                    $itemEntityId,
                    $yotpoIdKey,
                    $yotpoIdValue,
                    $storeId,
                    $responseCode
                );

                if (!$isVisibleVariantsSync) {
                    $syncDataRecordToUpdate['yotpo_id_parent'] = $apiRequestParams['yotpo_id_parent'] ?: 0;
                }

                if ($this->coreConfig->canUpdateCustomAttributeForProducts($syncDataRecordToUpdate['response_code'])) {
                    if ($this->isSyncingAsMainEntity()) {
                        $this->updateProductSyncAttribute($storeId, $itemRowId);
                    }
                }

                $returnResponse = $this->processResponse(
                    $apiRequestParams,
                    $response,
                    $syncDataRecordToUpdate,
                    $yotpoFormatItemData,
                    [],
                    $isVisibleVariantsSync
                );

                $processedSyncDataRecordToUpdate = $returnResponse['temp_sql'];

                if (isset($this->retryItems[$storeId][$itemEntityId])) {
                    unset($this->retryItems[$storeId][$itemEntityId]);
                }

                if (count($returnResponse['failed_product_ids_for_retry'])) {
                    foreach ($returnResponse['failed_product_ids_for_retry'] as $retryId) {
                        if ($this->isImmediateRetry($response, $this->entity, $isVisibleVariantsSync.$retryId, $storeId)) {
                            $this->retryItems[$storeId][$retryId] = $retryId;
                            $this->setImmediateRetryAlreadyDone(
                                $this->entity,
                                $isVisibleVariantsSync . (int)$itemEntityId,
                                $this->coreConfig->getStoreId()
                            );
                        }
                    }
                }

                if (!$isVisibleVariantsSync) {
                    $yotpoSyncTableItemsData = $this->pushParentData(
                        (int)$itemEntityId,
                        $processedSyncDataRecordToUpdate,
                        $yotpoSyncTableItemsData,
                        $parentItemsIds
                    );

                    if ($this->shouldForceProductCollectionsResync($response, $apiRequestParams)) {
                        $this->collectionsProductsService->forceUpdateProductCollectionsForResync($storeId, $itemRowId);
                    }
                }

                if ($processedSyncDataRecordToUpdate) {
                    $this->productsSyncService->updateSyncTable($processedSyncDataRecordToUpdate);
                    $syncTableRecordsUpdated[] = $processedSyncDataRecordToUpdate;

                    if ($this->shouldForceVariantsSyncFollowingProductUpsert(
                        $isVisibleVariantsSync,
                        $apiRequestParams['method'],
                        $response->getData('is_success'),
                        $currentItemYotpoId,
                        $apiRequestParams['yotpo_id'])
                    ) {
                        $this->resetVariantsSyncWithUpdatedParentYotpoId(
                            $storeId,
                            $itemEntityId,
                            $currentItemYotpoId,
                            $apiRequestParams['yotpo_id'],
                            $syncedToYotpoProductAttributeId
                        );
                    }
                }

                if ($this->isCommandLineSync && !$this->isImmediateRetry) {
                    // phpcs:ignore
                    echo 'Catalog process completed for productid - ' . $itemEntityId . PHP_EOL;
                }
            } catch (\Exception $e) {
                $hasFailedCreatingAnyProduct = true;
                $this->updateProductSyncAttribute($storeId, $itemRowId);
                $this->yotpoCatalogLogger->infoLog(
                    __(
                        'error while syncing product, store_id: %1, product_id: %2. Exception: %3',
                        $storeId,
                        $itemRowId,
                        $e->getMessage()
                    )
                );
            }
        }

        $dataToSent = [];
        if (count($syncTableRecordsUpdated)) {
            $dataToSent = array_merge($dataToSent, $this->catalogData->filterDataForCatSync($syncTableRecordsUpdated));
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

        if (isset($this->retryItems[$storeId]) && count($this->retryItems[$storeId]) > 0) {
            $this->update(
                'yotpo_product_sync',
                [$yotpoIdKey => 0, 'response_code' => coreConfig::CUSTOM_RESPONSE_DATA],
                ['product_id' . ' IN (?)' => $this->retryItems[$storeId], 'store_id = ?' => $storeId]
            );
            $collection = $this->getCollectionForSync($this->retryItems[$storeId]);
            $this->isImmediateRetry = true;
            $this->syncItems($collection->getItems(), $storeId, $isVisibleVariantsSync);
        }

        if ($visibleVariantsDataValues && !$isVisibleVariantsSync) {
            $hasFailedCreatingAnyVisibleVariant = $this->syncItems($visibleVariantsDataValues, $storeId, true);
            if ($hasFailedCreatingAnyVisibleVariant) {
                $hasFailedCreatingAnyProduct = true;
            }
        }

        if ($hasFailedCreatingAnyProduct) {
            $this->yotpoCatalogLogger->infoLog(
                __(
                    'API errors occurred while trying to create products -
                    Store ID: %1, Store Name: %2, Is Visible Variants Sync: %3',
                    $storeId,
                    $this->coreConfig->getStoreName($storeId),
                    var_export($isVisibleVariantsSync, true)
                )
            );
        }

        return $hasFailedCreatingAnyProduct;
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
                try {
                    $tempDeleteQry = [
                        'product_id' => $itemId,
                        'is_deleted_at_yotpo' => 0,
                        'store_id' => $storeId
                    ];
                    if (!$itemData['yotpo_id']) {
                        $tempDeleteQry['is_deleted_at_yotpo'] = 1;
                        $this->productsSyncService->updateSyncTable($tempDeleteQry);
                        continue;
                    }
                    $apiParam = [];
                    if (isset($itemData['yotpo_id_parent'])) {
                        $apiParam = $itemData;
                    }
                    $params = $this->getDeleteApiParams($itemData, 'yotpo_id');
                    $itemDataRequest = ['is_discontinued' => true];
    $responseObject = $this->handleRequest($itemId, $itemDataRequest, $params);
                $response = $responseObject['response'];
                $params['method'] = $responseObject['method'];
                $params['yotpo_id'] = $responseObject['yotpo_id'];
                    $returnResponse = $this->processResponse($params, $response, $tempDeleteQry, $itemData);
                    if ($this->isImmediateRetryResponse($response->getData('status'))) {
                        $response = $this->processDeleteRetry($params, $apiParam, $itemData, $itemId);
                        $returnResponse = $this->processResponse($params, $response, $tempDeleteQry, $itemData);
                    }
                    $this->productsSyncService->updateSyncTable($returnResponse['temp_sql']);
                } catch (\Exception $e) {
                    $this->updateProductSyncAttribute($storeId, $itemId);
                    $this->yotpoCatalogLogger->infoLog(
                        __(
                            'Exception raised within processDeleteData - $itemId: %1, $itemData: %2 Exception Message: %3',
                            $itemId,
                            $itemData,
                            $e->getMessage()
                        )
                    );
                }
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
                try {
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
    $responseObject = $this->handleRequest($itemId, $itemDataRequest, $params);
                $response = $responseObject['response'];
                $params['method'] = $responseObject['method'];
                $params['yotpo_id'] = $responseObject['yotpo_id'];
                    $returnResponse = $this->processResponse($params, $response, $tempDeleteQry, $itemData);
                    if ($this->isImmediateRetryResponse($response->getData('status'))) {
                        $response = $this->processDeleteRetry($params, $apiParam, $itemData, $itemId);
                        $returnResponse = $this->processResponse($params, $response, $tempDeleteQry, $itemData);
                    }
                    $this->productsSyncService->updateSyncTable($returnResponse['temp_sql']);
                } catch (\Exception $e) {
                    $this->updateProductSyncAttribute($storeId, $itemId);
                    $this->yotpoCatalogLogger->infoLog(
                        __(
                            'Exception raised within processUnAssignData - $itemId: %1, $itemData: %2 Exception Message: %3',
                            $itemId,
                            $itemData,
                            $e->getMessage()
                        )
                    );
                }
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
     * @param array<int|string, mixed> $yotpoData
     * @param array<int, int> $parentsIds
     * @return array<int|string, mixed>
     */
    protected function pushParentData($productId, $tempSqlArray, $yotpoData, $parentsIds)
    {
        $yotpoId = 0;
        if (isset($tempSqlArray['yotpo_id'])
            && $tempSqlArray['yotpo_id']) {
            if (!isset($yotpoData[$productId])) {
                $parentId = $this->findParentId($productId, $parentsIds);
                if ($parentId) {
                    $yotpoId = $tempSqlArray['yotpo_id'];
                }
            }
        }
        if ($yotpoId) {
            $yotpoData[$productId] = [
                'product_id' => $productId,
                'yotpo_id' => $yotpoId
            ];
        }

        return $yotpoData;
    }

    /**
     * Find it parent ID is exist in the synced data
     * @param int $productId
     * @param array<int, int> $parentsIds
     * @return false|int|string
     */
    protected function findParentId($productId, $parentsIds)
    {
        return array_search($productId, $parentsIds);
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
     * @param array<mixed> $productIds
     * @param array<mixed> $visibleItems
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
     * @param array<mixed> $productIds
     * @param array<mixed> $visibleItems
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
        $productsIdsToUpdate = [];
        $productsIdsToCreate = $this->syncDataMain->getProductIds($productIds, $storeId, $itemsMap);

        if (!$this->coreConfig->isCatalogSyncActive($storeId)) {
            $productsIdsToCheck = array_diff($productIds, $productsIdsToCreate);
            $productsIdsToUpdate = $this->productsSyncService->findProductsThatShouldBeSyncedByAttribute($productsIdsToCheck);
        }

        return array_merge($productsIdsToUpdate, $productsIdsToCreate);
    }

    /**
     * @param array <mixed> $yotpoSyncTableItemData
     * @return bool
     */
    private function shouldItemBeResynced($yotpoSyncTableItemData)
    {
        if ($yotpoSyncTableItemData['response_code'] == CoreConfig::CONFLICT_RESPONSE_CODE) {
            return true;
        }

        return $this->coreConfig->canResync(
            $yotpoSyncTableItemData['response_code'],
            $yotpoSyncTableItemData,
            $this->isCommandLineSync
        );
    }

    /**
     * @param integer $storeId
     * @param integer $itemRowId
     * @return array <mixed>
     */
    private function prepareAttributeDataToUpdate($storeId, $itemRowId)
    {
        $syncedToYotpoProductAttributeId = $this->catalogData->getAttributeId(CoreConfig::CATALOG_SYNC_ATTR_CODE);
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
     * @param integer|string $yotpoIdValue
     * @param integer $storeId
     * @param integer|string $responseCode
     * @param integer $yotpoParentId
     * @return array <mixed>
     */
    private function prepareSyncTableDataToUpdate(
        $itemEntityId,
        $yotpoIdKey,
        $yotpoIdValue,
        $storeId,
        $responseCode,
        $yotpoParentId = null
    ) {
        $lastSyncTime = $this->getCurrentTime();
        $return =  [
            'product_id' => $itemEntityId,
            $yotpoIdKey => $yotpoIdValue,
            'store_id' => $storeId,
            'synced_to_yotpo' => $lastSyncTime,
            'response_code' => $responseCode
        ];
        if ($yotpoParentId !== null) {
            $return['yotpo_id_parent'] = $yotpoParentId;
        }
        return $return;
    }

    /**
     * @param $storeId
     * @param $itemRowId
     * @return void
     */
    private function updateProductSyncAttribute($storeId, $itemRowId)
    {
        $attributeDataToUpdate = $this->prepareAttributeDataToUpdate($storeId, $itemRowId);
        $this->insertOnDuplicate(
            'catalog_product_entity_int',
            [$attributeDataToUpdate]
        );
    }

    /**
     * @param integer $storeId
     * @param integer $itemEntityId
     * @param integer $parentItemId
     * @param array <mixed> $yotpoSyncTableItemsData
     * @param array <mixed> $parentItemsIds
     * @param array <mixed> $yotpoFormatItemData
     * @return integer
     */
    private function forceParentProductSyncToYotpo($storeId, $itemEntityId, $parentItemId, $parentProductData, $yotpoSyncTableItemsData, $parentItemsIds, $yotpoFormatItemData)
    {
        $this->yotpoCatalogLogger->infoLog(
            __(
                'Start syncing parent product that does not exist in Yotpo - Store ID: %1, Store Name: %2, Item Entity ID: %3, Parent Entity ID: %4',
                $storeId,
                $this->coreConfig->getStoreName($storeId),
                $itemEntityId,
                $parentItemId
            )
        );

        if ($this->isProductParentYotpoIdFound($yotpoSyncTableItemsData, $parentItemId)) {
            $yotpoSyncTableItemsData[$parentItemId]['yotpo_id'] = 0;
        }

        $parentProductYotpoId = $this->ensureEntityExistenceAsProductInYotpo(
            $parentItemId,
            $parentProductData,
            $yotpoSyncTableItemsData,
            $parentItemsIds,
            $yotpoFormatItemData
        );

        if ($parentProductYotpoId) {
            $this->yotpoCatalogLogger->infoLog(
                __(
                    'Finished syncing parent product that does not exist in Yotpo - Store ID: %1, Store Name: %2, Parent Entity ID: %3, Yotpo ID: %4',
                    $storeId,
                    $this->coreConfig->getStoreName($storeId),
                    $parentItemId,
                    $parentProductYotpoId
                )
            );

            $yotpoSyncTableItemsData[$parentItemId] = [
                'product_id' => $parentItemId,
                'yotpo_id' => $parentProductYotpoId
            ];
        } else {
            $this->yotpoCatalogLogger->infoLog(
                __(
                    'Failed creating parent product for a variant - Store ID: %1, Store Name: %2, Parent Entity ID: %3, Yotpo ID: %4',
                    $storeId,
                    $this->coreConfig->getStoreName($storeId),
                    $parentItemId,
                    $parentProductYotpoId
                )
            );
        }

        return $parentProductYotpoId;
    }

    /**
     * @param int $parentId
     * @param array <mixed> $yotpoSyncTableItemsData
     * @param array <mixed> $parentItemsIds
     * @param array <mixed> $yotpoFormatItemData
     * @return string|null
     */
    private function ensureEntityExistenceAsProductInYotpo(
        $parentId,
        $parentProductData,
        $yotpoSyncTableItemsData,
        $parentItemsIds,
        $yotpoFormatItemData
    ) {
        $apiRequestParams = $this->getApiParams(
            $parentId,
            $yotpoSyncTableItemsData,
            $parentItemsIds,
            false
        );
        /** @phpstan-ignore-next-line */
        $productData = $this->catalogData->adaptMagentoProductToYotpoProduct(reset($parentProductData));
        if (isset($productData['row_id'])) {
            unset($productData['row_id']);
        }

        $responseObject = $this->handleRequest($parentId, $productData, $apiRequestParams);
        $response = $responseObject['response'];
        $apiRequestParams['method'] = $responseObject['method'];
        $apiRequestParams['yotpo_id'] = $responseObject['yotpo_id'];

        if ($response && !$response->getData(CoreConfig::YOTPO_SYNC_RESPONSE_IS_SUCCESS_KEY)) {
            $this->yotpoCatalogLogger->infoLog(
                __(
                    'Failed syncing missing variant parent product to Yotpo - Parent Entity ID: %1, Status Code: %2, Reason: %3',
                    $parentId,
                    $response->getData('status'),
                    $response->getData('reason')
                )
            );

            return 0;
        }
        $yotpoId = $apiRequestParams['yotpo_id'] ?: 0;
        $storeId = $this->coreConfig->getStoreId();
        $syncDataRecordToUpdate = $this->prepareSyncTableDataToUpdate(
            $parentId,
            'yotpo_id',
            $yotpoId,
            $storeId,
            $response->getData('status')
        );
        $returnResponse = $this->processResponse(
            $apiRequestParams,
            $response,
            $syncDataRecordToUpdate,
            $yotpoFormatItemData
        );
        $processedSyncDataRecordToUpdate = $returnResponse['temp_sql'];
        $this->productsSyncService->updateSyncTable($processedSyncDataRecordToUpdate);
        return $processedSyncDataRecordToUpdate['yotpo_id'];
    }

    /**
     * @param DataObject $response
     * @param array<mixed> $apiRequestParams
     * @return boolean
     */
    private function shouldForceProductCollectionsResync($response, $apiRequestParams)
    {
        if (!$this->isImmediateRetry) {
            return false;
        }

        if (!$response->getData('is_success')) {
            return false;
        }

        if (!$this->isProductUpsertSyncMethod($apiRequestParams['method'])) {
            return false;
        }

        return true;
    }

    /**
     * @param bool $isVisibleVariantsSync
     * @param string $syncMethod
     * @param bool $isRequestSuccessful
     * @param integer $currentItemYotpoId
     * @param integer $returnedItemYotpoId
     * @return
     */
    private function shouldForceVariantsSyncFollowingProductUpsert(
        $isVisibleVariantsSync,
        $syncMethod,
        $isRequestSuccessful,
        $currentItemYotpoId,
        $returnedItemYotpoId
    ) {
        return !$isVisibleVariantsSync
            && $this->isProductUpsertSyncMethod($syncMethod)
            && $isRequestSuccessful
            && $this->isItemYotpoIdChanged($currentItemYotpoId, $returnedItemYotpoId);
    }

    /**
     * @param string $method
     * @return boolean
     */
    private function isProductUpsertSyncMethod($method)
    {
        return in_array($method, ['createProduct', 'updateProduct']);
    }

    /**
     * @param string $method
     * @return boolean
     */
    private function isVariantUpsertSyncMethod($method)
    {
        return in_array($method, ['createProductVariant', 'updateProductVariant']);
    }

    /**
     * @param integer $currentItemYotpoId
     * @param integer $returnedItemYotpoId
     * @return boolean
     */
    private function isItemYotpoIdChanged($currentItemYotpoId, $returnedItemYotpoId)
    {
        return $currentItemYotpoId && $returnedItemYotpoId && $currentItemYotpoId != $returnedItemYotpoId;
    }

    /**
     * @param integer $storeId
     * @param integer $currentParentYotpoId
     * @param integer $updatedParentYotpoId
     * @return void
     */
    private function resetVariantsSyncWithUpdatedParentYotpoId(
        $storeId,
        $parentEntityId,
        $currentParentYotpoId,
        $updatedParentYotpoId,
        $syncedToYotpoProductAttributeId
    ) {
        $this->yotpoCatalogLogger->infoLog(
            __(
                'Yotpo Parent Product ID changed, forcing variants re-sync. Store ID: %1, Entity ID: %2, Current Yotpo ID: %3, Updated Yotpo ID: %4',
                $storeId,
                $parentEntityId,
                $currentParentYotpoId,
                $updatedParentYotpoId
            )
        );

        $variantIds = $this->productsSyncService->getProductIdsFromSyncTableByStoreIdAndParentYotpoId($storeId, $currentParentYotpoId);
        if (!$variantIds) {
            return;
        }

        $this->productsSyncService->updateYotpoIdParentInSyncTableByStoreIdAndVariantIds($storeId, $variantIds, $updatedParentYotpoId);
        $this->updateProductSyncAttributeByStoreIdAndProductIds($storeId, $variantIds, $syncedToYotpoProductAttributeId);
    }

    /**
     * @param integer $storeId
     * @param array $productIds
     * @param integer $syncedToYotpoProductAttributeId
     * @return void
     */
    private function updateProductSyncAttributeByStoreIdAndProductIds($storeId, $productIds, $syncedToYotpoProductAttributeId)
    {
        $connection = $this->resourceConnection->getConnection();
        $condition = [
            'store_id = ?' => $storeId,
            'attribute_id = ?' => $syncedToYotpoProductAttributeId,
            $this->entityIdFieldValue . ' IN (?)' => $productIds
        ];
        $connection->update(
            $this->resourceConnection->getTableName('catalog_product_entity_int'),
            ['value' => 0],
            $condition
        );
    }
}
