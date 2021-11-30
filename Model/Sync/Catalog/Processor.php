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
use Yotpo\Core\Model\Sync\Catalog\Processor\Main;

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
     * @var array<int, int>
     */
    protected $runStoreIds = [];

    /**
     * @var DateTime
     */
    protected $dateTime;

    /**
     * @var CategorySyncProcessor
     */
    protected $categorySyncProcessor;

    /**
     * @var boolean
     */
    protected $normalSync = true;

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
     * @param YotpoResource $yotpoResource,
     * @param CategorySyncProcessor $categorySyncProcessor
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
        CategorySyncProcessor $categorySyncProcessor
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
    }

    /**
     * Sync products during checkout
     * @param null|array <mixed> $unSyncedProductIds
     * @return bool
     */
    public function processCheckoutProducts($unSyncedProductIds)
    {
        $this->normalSync = false;
        try {
            $storeId = $this->coreConfig->getStoreId();
            $collection = $this->getCollectionForSync($unSyncedProductIds);
            $this->syncItems($collection->getItems(), $storeId);
            return true;
        } catch (NoSuchEntityException $e) {
            return false;
        }
    }
    /**
     * Process the Catalog Api
     * @param null|array <mixed> $unSyncedProductIds
     * @param Order|Quote $order
     * @return bool
     */
    public function process($unSyncedProductIds = null, $order = null)
    {
        $this->runStoreIds = [];
        try {
            $allStores = (array)$this->coreConfig->getAllStoreIds(false);
            $unSyncedStoreIds = [];
            foreach ($allStores as $storeId) {
                if (in_array($storeId, $this->runStoreIds)) {
                    continue;
                }
                $this->runStoreIds[] = $storeId;
                $this->emulateFrontendArea($storeId);
                try {
                    if (!$order && !$this->coreConfig->isCatalogSyncActive()) {
                        $this->yotpoCatalogLogger->info(
                            __(
                                'Product Sync - Disabled - Magento Store ID: %1, Name: %2',
                                $storeId,
                                $this->coreConfig->getStoreName($storeId)
                            )
                        );
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
                    $this->processDeleteData();
                    $this->processUnAssignData();
                    $collection = $this->getCollectionForSync($unSyncedProductIds);
                    $this->syncItems($collection->getItems(), $storeId);
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
            if ($order && in_array($order->getStoreId(), $unSyncedStoreIds)) {
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
     * @param boolean $visibleVariants
     * @return void
     * @throws NoSuchEntityException
     */
    public function syncItems($collectionItems, $storeId, $visibleVariants = false)
    {
        if (count($collectionItems)) {
            $attributeId = $this->catalogData->getAttributeId(CoreConfig::CATALOG_SYNC_ATTR_CODE);
            $items = $this->manageSyncItems($collectionItems, $visibleVariants);
            $parentIds = $items['parent_ids'];
            $yotpoData = $items['yotpo_data'];
            $parentData = $items['parent_data'];
            $lastSyncTime = '';
            $sqlData = $sqlDataIntTable = [];
            $externalIds = [];
            $visibleVariantsData = $visibleVariants ? [] : $items['visible_variants'];
            $visibleVariantsDataValues = array_values($visibleVariantsData);
            foreach ($items['sync_data'] as $itemId => $itemData) {
                $rowId = $itemData['row_id'];
                unset($itemData['row_id']);
                if ($yotpoData
                    && array_key_exists($itemId, $yotpoData)
                    && !$this->coreConfig->canResync($yotpoData[$itemId]['response_code'], $yotpoData[$itemId])
                ) {
                    $tempSqlDataIntTable = [
                        'attribute_id' => $attributeId,
                        'store_id' => $storeId,
                        'value' => 1,
                        $this->entityIdFieldValue => $rowId
                    ];
                    $sqlDataIntTable = [];
                    $sqlDataIntTable[] = $tempSqlDataIntTable;
                    if ($this->normalSync) {
                        $this->insertOnDuplicate(
                            'catalog_product_entity_int',
                            $sqlDataIntTable
                        );
                    }
                    continue;
                }
                $apiParam = $this->getApiParams($itemId, $yotpoData, $parentIds, $parentData, $visibleVariants);
                if (!$apiParam) {
                    $parentProductId = $parentIds[$itemId] ?? 0;
                    if ($parentProductId) {
                        continue;
                    }
                }
                $this->yotpoCatalogLogger->info(
                    __(
                        'Data ready to sync - Method: %1 - Magento Store ID: %2, Name: %3',
                        $apiParam['method'],
                        $storeId,
                        $this->coreConfig->getStoreName($storeId)
                    )
                );
                $response = $this->processRequest($apiParam, $itemData);
                $lastSyncTime = $this->getCurrentTime();
                $yotpoIdKey = $visibleVariants ? 'visible_variant_yotpo_id' : 'yotpo_id';
                $tempSqlArray = [
                    'product_id' => $itemId,
                    $yotpoIdKey => $apiParam['yotpo_id'] ?: 0,
                    'store_id' => $storeId,
                    'synced_to_yotpo' => $lastSyncTime,
                    'response_code' => $response->getData('status'),
                    'sync_status' => 1
                ];
                if (!$visibleVariants) {
                    $tempSqlArray['yotpo_id_parent']  = $apiParam['yotpo_id_parent'] ?: 0;
                }
                if ($this->coreConfig->canUpdateCustomAttributeForProducts($tempSqlArray['response_code'])) {
                    $tempSqlDataIntTable = [
                        'attribute_id' => $attributeId,
                        'store_id' => $storeId,
                        'value' => 1,
                        $this->entityIdFieldValue => $rowId
                    ];
                    $sqlDataIntTable = [];
                    $sqlDataIntTable[] = $tempSqlDataIntTable;
                    if ($this->normalSync) {
                        $this->insertOnDuplicate(
                            'catalog_product_entity_int',
                            $sqlDataIntTable
                        );
                    }
                }
                $returnResponse = $this->processResponse(
                    $apiParam,
                    $response,
                    $tempSqlArray,
                    $itemData,
                    $externalIds,
                    $visibleVariants
                );
                $tempSqlArray = $returnResponse['temp_sql'];
                $externalIds = $returnResponse['external_id'];

                //push to parentData array if parent product is
                // being the part of current collection
                if (!$visibleVariants) {
                    $parentData = $this->pushParentData((int)$itemId, $tempSqlArray, $parentData, $parentIds);
                }
                $syncDataSql = [];
                $syncDataSql[] = $tempSqlArray;
                $this->insertOnDuplicate(
                    'yotpo_product_sync',
                    $syncDataSql
                );
                $sqlData[] = $tempSqlArray;
            }
            $dataToSent = [];
            if (count($sqlData)) {
                $dataToSent = array_merge($dataToSent, $this->catalogData->filterDataForCatSync($sqlData));
            }

            if ($externalIds) {
                $yotpoExistingProducts = $this->processExistData(
                    $externalIds,
                    $parentData,
                    $parentIds,
                    $visibleVariants
                );
                if ($yotpoExistingProducts && $this->normalSync) {
                    $dataToSent = array_merge(
                        $dataToSent,
                        $this->catalogData->filterDataForCatSync($yotpoExistingProducts)
                    );
                }
            }
            if ($this->normalSync) {
                $this->coreConfig->saveConfig('catalog_last_sync_time', $lastSyncTime);
                $dataForCategorySync = [];
                if ($dataToSent && !$visibleVariants) {
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
            if ($visibleVariantsDataValues && !$visibleVariants) {
                $this->syncItems($visibleVariantsDataValues, $storeId, true);
            }
        } else {
            $this->yotpoCatalogLogger->info(
                __(
                    'Product Sync complete : No Data, Magento Store ID: %1, Name: %2',
                    $storeId,
                    $this->coreConfig->getStoreName($storeId)
                )
            );
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
                    $sqlData = [];
                    $sqlData[] = $tempDeleteQry;
                    $this->insertOnDuplicate(
                        'yotpo_product_sync',
                        $sqlData
                    );
                    continue;
                }
                $params = $this->getDeleteApiParams($itemData, 'yotpo_id');
                $itemData = ['is_discontinued' => true];
                $response = $this->processRequest($params, $itemData);
                $returnResponse = $this->processResponse($params, $response, $tempDeleteQry, $itemData);
                $sqlData = [];
                $sqlData[] = $returnResponse['temp_sql'];
                $this->insertOnDuplicate(
                    'yotpo_product_sync',
                    $sqlData
                );
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
                $params = $this->getDeleteApiParams($itemData, 'yotpo_id_unassign');

                $itemDataRequest = ['is_discontinued' => true];
                $response = $this->processRequest($params, $itemDataRequest);
                $returnResponse = $this->processResponse($params, $response, $tempDeleteQry, $itemDataRequest);
                $sqlData = [];
                $sqlData[] = $returnResponse['temp_sql'];
                $this->insertOnDuplicate(
                    'yotpo_product_sync',
                    $sqlData
                );
            }
            $this->updateProductSyncLimit($dataCount);
        }
    }

    /**
     * @param array <mixed> $items
     * @param boolean $visibleVariants
     * @return array <mixed>
     * @throws NoSuchEntityException
     */
    protected function manageSyncItems($items, $visibleVariants = false): array
    {
        return $this->catalogData->manageSyncItems($items, $visibleVariants);
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
                $sqlData[] = [
                    'product_id' => $product['external_id'],
                    'store_id' => $this->coreConfig->getStoreId(),
                    $yotpoIdKey => $product['yotpo_id'],
                    'yotpo_id_parent' => $parentId,
                    'response_code' => '200'
                ];
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
        foreach ($data as $dataItem) {
            foreach ($collectionItems as $item) {
                if ($item->getId() == $dataItem['product_id']) {
                    $dataForCategorySync[$dataItem['yotpo_id']] = $item;
                    break;
                }
            }
        }
        return $dataForCategorySync;
    }
}
