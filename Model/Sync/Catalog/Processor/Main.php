<?php

namespace Yotpo\Core\Model\Sync\Catalog\Processor;

use InvalidArgumentException;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Yotpo\Core\Model\AbstractJobs;
use Yotpo\Core\Model\Config as CoreConfig;
use Yotpo\Core\Model\Sync\Catalog\Data as CatalogData;
use Yotpo\Core\Model\Sync\Catalog\Logger as YotpoCoreCatalogLogger;
use Yotpo\Core\Model\Sync\Catalog\YotpoResource;
use Yotpo\Core\Model\Api\Sync as CoreSync;

/**
 * Manage catalog sync process
 */
class Main extends AbstractJobs
{
    const YOTPO_PRODUCT_SYNC_TABLE_NAME = 'yotpo_product_sync';

    /**
     * @var CoreConfig
     */
    protected $coreConfig;

    /**
     * @var YotpoCoreCatalogLogger
     */
    protected $yotpoCatalogLogger;

    /**
     * @var YotpoResource
     */
    protected $yotpoResource;

    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * @var CoreSync
     */
    protected $coreSync;

    /**
     * @var CatalogData
     */
    protected $catalogData;

    /**
     * @var null|int
     */
    protected $productSyncLimit = null;

    /**
     * @var string|null
     */
    protected $entityIdFieldValue;

    /**
     * @var string
     */
    protected $entity = 'products';

    /**
     * @var boolean
     */
    protected $normalSync = true;

    /**
     * @var CatalogRequestHandler
     */
    protected $catalogRequestHandler;

    /**
     * AbstractJobs constructor.
     * @param AppEmulation $appEmulation
     * @param ResourceConnection $resourceConnection
     * @param CoreConfig $coreConfig
     * @param YotpoCoreCatalogLogger $yotpoCatalogLogger
     * @param YotpoResource $yotpoResource
     * @param CollectionFactory $collectionFactory
     * @param CoreSync $coreSync,
     * @param CatalogData $catalogData
     */
    public function __construct(
        AppEmulation $appEmulation,
        ResourceConnection $resourceConnection,
        CoreConfig $coreConfig,
        YotpoCoreCatalogLogger $yotpoCatalogLogger,
        YotpoResource $yotpoResource,
        CollectionFactory $collectionFactory,
        CoreSync $coreSync,
        CatalogData $catalogData,
        CatalogRequestHandler $catalogRequestHandler
    ) {
        $this->coreConfig = $coreConfig;
        $this->yotpoCatalogLogger = $yotpoCatalogLogger;
        $this->yotpoResource = $yotpoResource;
        $this->collectionFactory = $collectionFactory;
        $this->coreSync = $coreSync;
        $this->entityIdFieldValue = $this->coreConfig->getEavRowIdFieldName();
        $this->catalogData = $catalogData;
        $this->catalogRequestHandler = $catalogRequestHandler;
        parent::__construct($appEmulation, $resourceConnection);
    }

    /**
     * @param integer $itemEntityId
     * @param array<mixed> $yotpoItemData
     * @param array<mixed> $apiRequestParams
     * @return array<mixed>
     */
    protected function handleRequest($itemEntityId, $yotpoItemData, $apiRequestParams)
    {
        $syncMethod = $apiRequestParams['method'];
        $yotpoItemId = null;
        $yotpoParentItemId = null;

        if (isset($apiRequestParams['yotpo_id'])) {
            $yotpoItemId = $apiRequestParams['yotpo_id'];
        }

        if (isset($apiRequestParams['yotpo_id_parent'])) {
            $yotpoParentItemId = $apiRequestParams['yotpo_id_parent'];
        }

        switch ($syncMethod) {
            case 'createProduct':
            case 'updateProduct':
                return $this->catalogRequestHandler->handleProductUpsert($itemEntityId, $yotpoItemData, $yotpoItemId);
            case 'createProductVariant':
            case 'updateProductVariant':
                return $this->catalogRequestHandler->handleVariantUpsert(
                    $itemEntityId,
                    $yotpoItemData,
                    $yotpoParentItemId,
                    $yotpoItemId
                );
            case 'deleteProduct':
            case 'unassignProduct':
            case 'deleteProductVariant':
            case 'unassignProductVariant':
                $response = $this->processRequest($apiRequestParams, $yotpoItemData);
                return $this->catalogRequestHandler->prepareRequestResponseObject($syncMethod, $yotpoItemId, $response);
            default:
                $response = $this->coreSync->getEmptyResponse();
                $storeId = $this->coreConfig->getStoreId();
                $this->yotpoCatalogLogger->infoLog(
                    __(
                        'API request process failed due to a matching method was not found -
                        Magento Store ID: %1, Name: %2',
                        $storeId,
                        $this->coreConfig->getStoreName($storeId)
                    ),
                    []
                );
                return $this->catalogRequestHandler->prepareRequestResponseObject($syncMethod, $yotpoItemId, $response);
        }
    }

    /**
     * Trigger API
     * @param array<string, string> $params
     * @param mixed $data
     * @return mixed
     * @throws NoSuchEntityException|InvalidArgumentException
     */
    private function processRequest($params, $data)
    {
        switch ($params['method']) {
            case $this->coreConfig->getProductSyncMethod('deleteProduct'):
            case $this->coreConfig->getProductSyncMethod('unassignProduct'):
                $data = ['product' => $data, 'entityLog' => 'catalog'];
                $response = $this->coreSync->sync('PATCH', $params['url'], $data, true);
                break;
            case $this->coreConfig->getProductSyncMethod('deleteProductVariant'):
            case $this->coreConfig->getProductSyncMethod('unassignProductVariant'):
                $data = ['variant' => $data, 'entityLog' => 'catalog'];
                $response = $this->coreSync->sync('PATCH', $params['url'], $data, true);
                break;
            default:
                throw new InvalidArgumentException("Invalid method was passed to processRequest");
        }

        return $response;
    }

    /**
     * Handle response
     * @param array<mixed> $apiParam
     * @param mixed $response
     * @param array<string, string|int> $tempSqlArray
     * @param mixed $data
     * @param array<int, int> $externalIds
     * @param boolean $visibleVariants
     * @return array<string, mixed>
     * @throws NoSuchEntityException
     */
    protected function processResponse(
        $apiParam,
        $response,
        $tempSqlArray,
        $data,
        $externalIds = [],
        $visibleVariants = false
    ) {
        $storeId = $this->coreConfig->getStoreId();
        $failedProductSyncIdsForRetry = [];
        switch ($apiParam['method']) {
            case $this->coreConfig->getProductSyncMethod('createProduct'):
            case $this->coreConfig->getProductSyncMethod('createProductVariant'):
                $yotpoIdkey = $visibleVariants ? 'visible_variant_yotpo_id' : 'yotpo_id';
                if ($response->getData('is_success')) {
                    $tempSqlArray[$yotpoIdkey] = $this->catalogRequestHandler->getYotpoIdFromResponse(
                        $response,
                        $apiParam['method']
                    );
                    $this->writeSuccessLog($apiParam['method'], $storeId);
                } else {
                    if ($response->getStatus() == '409') {
                        $externalIds[] = array_key_exists('external_id', $data) ? $data['external_id'] : 0;
                    }
                    $tempSqlArray[$yotpoIdkey] = 0;
                    $this->writeFailedLog($apiParam['method'], $storeId);
                }
                break;
            case $this->coreConfig->getProductSyncMethod('updateProduct'):
            case $this->coreConfig->getProductSyncMethod('updateProductVariant'):
            case $this->coreConfig->getProductSyncMethod('deleteProduct'):
            case $this->coreConfig->getProductSyncMethod('deleteProductVariant'):
            case $this->coreConfig->getProductSyncMethod('unassignProduct'):
            case $this->coreConfig->getProductSyncMethod('unassignProductVariant'):
                if ($response->getData('is_success')) {
                    $this->writeSuccessLog($apiParam['method'], $storeId);
                    $delOrUnAssignParams = $this->prepareTempSqlForUnAssignOrDel($apiParam['method']);
                    if ($delOrUnAssignParams) {
                        $tempSqlArray = array_merge($tempSqlArray, $delOrUnAssignParams);
                    }
                } else {
                    if ($this->isImmediateRetryResponse($response->getData('status'))) {
                        $BadRequestProductSyncParentId = '';
                        if ($apiParam['method'] === $this->coreConfig->getProductSyncMethod('updateProductVariant')) {
                            $BadRequestProductSyncParentId = $this->getProductParentIdFromSyncTable($apiParam);
                        }
                        if ($BadRequestProductSyncParentId) {
                            $failedProductSyncIdsForRetry[] = $BadRequestProductSyncParentId;
                        }
                        if (array_key_exists('external_id', $data)) {
                            $failedProductSyncIdsForRetry[] = $data['external_id'];
                        }
                    }
                    $this->writeFailedLog($apiParam['method'], $storeId);
                }
                break;
            default:
                $tempSqlArray = [];
                $storeId = $this->coreConfig->getStoreId();
                $this->yotpoCatalogLogger->infoLog(
                    __(
                        'API Response Process failed due to a matching method was not found
                        - Magento Store ID: %1, Name: %2',
                        $storeId,
                        $this->coreConfig->getStoreName($storeId)
                    ),
                    []
                );
        }

        return [
            'temp_sql' => $tempSqlArray,
            'external_id' => array_filter($externalIds),
            'failed_product_ids_for_retry' => $failedProductSyncIdsForRetry
        ];
    }

    /**
     * @param string $apiMethod
     * @return array|mixed|string
     */
    public function prepareTempSqlForUnAssignOrDel($apiMethod)
    {
        $return = [];
        if ($apiMethod === $this->coreConfig->getProductSyncMethod('deleteProduct')
            || $apiMethod === $this->coreConfig->getProductSyncMethod('deleteProductVariant')) {
            $return['is_deleted_at_yotpo'] = 1;
        }
        if ($apiMethod === $this->coreConfig->getProductSyncMethod('unassignProduct')
            || $apiMethod === $this->coreConfig->getProductSyncMethod('unassignProductVariant')) {
            $return['yotpo_id_unassign'] = 0;
        }
        return $return;
    }

    /**
     * Success Log
     * @param string|int $method
     * @param int $storeId
     * @return void
     * @throws NoSuchEntityException
     */
    protected function writeSuccessLog($method, $storeId)
    {
        $this->yotpoCatalogLogger->infoLog(
            __(
                '%1 API ran successfully - Magento Store ID: %2, Name: %3',
                $method,
                $storeId,
                $this->coreConfig->getStoreName($storeId)
            ),
            []
        );
    }

    /**
     * Failed Log
     * @param string|int $method
     * @param int $storeId
     * @return void
     * @throws NoSuchEntityException
     */
    protected function writeFailedLog($method, $storeId)
    {
        $this->yotpoCatalogLogger->infoLog(
            __(
                '%1 API failed - Magento Store ID: %2, Name: %3',
                $method,
                $storeId,
                $this->coreConfig->getStoreName($storeId)
            ),
            []
        );
    }

    /**
     * Collection for fetching the data to delete
     * @param int $storeId
     * @return array<int, array<string, string|int>>
     */
    protected function getToDeleteCollection($storeId)
    {
        return $this->yotpoResource->getToDeleteCollection($storeId, (int) $this->productSyncLimit);
    }

    /**
     * Collection for fetching the data to delete
     * @param int $storeId
     * @return array<int, array<string, string|int>>
     */
    protected function getUnAssignedCollection($storeId)
    {
        return $this->yotpoResource->getUnAssignedCollection($storeId, (int) $this->productSyncLimit);
    }

    /**
     * Prepare collection query to fetch data
     * @param array<mixed>|null $unSyncedProductIds
     * @return Collection<mixed>
     */
    protected function getCollectionForSync($unSyncedProductIds = []): Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect('*');
        if (!$unSyncedProductIds) {
            $collection->addAttributeToFilter(
                [
                    ['attribute' => CoreConfig::CATALOG_SYNC_ATTR_CODE, 'null' => true],
                    ['attribute' => CoreConfig::CATALOG_SYNC_ATTR_CODE, 'eq' => '0'],
                ]
            );
        }
        if ($unSyncedProductIds && is_array($unSyncedProductIds)) {
            $collection->addFieldToFilter('entity_id', ['in' => $unSyncedProductIds]);
        }
        $collection->addUrlRewrite();
        $collection->addStoreFilter();
        $collection->getSelect()->order('type_id');
        $collection->setFlag('has_stock_status_filter', false);
        $collection->getSelect()->limit($this->productSyncLimit);
        return $collection;
    }

    /**
     * Get API End URL and API Method
     * @param int|string $productId
     * @param array<int, array> $yotpoData
     * @param array<int, int> $parentsIds
     * @param boolean $isVisibleVariant
     * @return array<string, string>
     * @throws NoSuchEntityException
     */
    protected function getApiParams(
        $productId,
        array $yotpoData,
        array $parentsIds,
        $isVisibleVariant
    ) {
        $apiUrl = $this->coreConfig->getEndpoint('products');
        $method = $this->coreConfig->getProductSyncMethod('createProduct');
        $yotpoIdParent = $yotpoId = '';
        $yotpoIdKey = 'yotpo_id';

        if ($isVisibleVariant) {
            $yotpoIdKey = 'visible_variant_yotpo_id';
        } elseif (count($parentsIds) && isset($parentsIds[$productId])) {
            $parentId = $parentsIds[$productId];
            if ($this->isProductParentYotpoIdFound($yotpoData, $parentId)) {
                $yotpoIdParent = $yotpoData[$parentId]['yotpo_id'];

                $method = $this->coreConfig->getProductSyncMethod('createProductVariant');
                $apiUrl = $this->coreConfig->getEndpoint(
                    'variant',
                    ['{yotpo_product_id}'],
                    [$yotpoIdParent]
                );
            }
        }

        if (count($yotpoData)) {
            if (isset($yotpoData[$productId])
                && isset($yotpoData[$productId][$yotpoIdKey])
            ) {
                $yotpoId = $yotpoData[$productId][$yotpoIdKey];
                if ($yotpoId && $method ==  $this->coreConfig->getProductSyncMethod('createProduct')) {
                    $apiUrl = $this->coreConfig->getEndpoint(
                        'updateProduct',
                        ['{yotpo_product_id}'],
                        [$yotpoId]
                    );
                    $method = $this->coreConfig->getProductSyncMethod('updateProduct');
                } elseif ($yotpoId && $method ==  $this->coreConfig->getProductSyncMethod('createProductVariant')) {
                    $apiUrl = $this->coreConfig->getEndpoint(
                        'updateVariant',
                        ['{yotpo_product_id}','{yotpo_variant_id}'],
                        [$yotpoIdParent, $yotpoId]
                    );
                    $method = $this->coreConfig->getProductSyncMethod('updateProductVariant');
                }

            }
        }

        return [
            'url' => $apiUrl,
            'method' => $method,
            'yotpo_id' => $yotpoId,
            'yotpo_id_parent' => $yotpoIdParent
        ];
    }

    /**
     * @param array<string, int|string> $data
     * @param string $key
     * @return array<string, mixed>
     */
    protected function getDeleteApiParams($data, $key)
    {
        if ($variantId = $data['yotpo_id_parent']) {
            $apiUrl = $this->coreConfig->getEndpoint(
                'updateVariant',
                ['{yotpo_product_id}','{yotpo_variant_id}'],
                [$variantId, $data[$key]]
            );
            if ($key === 'yotpo_id') {
                $method = $this->coreConfig->getProductSyncMethod('deleteProductVariant');
            } else {
                $method = $this->coreConfig->getProductSyncMethod('unassignProductVariant');
            }
        } else {
            $apiUrl = $this->coreConfig->getEndpoint(
                'updateProduct',
                ['{yotpo_product_id}'],
                [$data[$key]]
            );
            if ($key === 'yotpo_id') {
                $method = $this->coreConfig->getProductSyncMethod('deleteProduct');
            } else {
                $method = $this->coreConfig->getProductSyncMethod('unassignProduct');
            }
        }

        return ['url' => $apiUrl, 'method' => $method, $key => $data[$key]];
    }

    /**
     * Calculate the remaining limit
     * @param int $delta
     * @return void
     */
    public function updateProductSyncLimit($delta)
    {
        $this->productSyncLimit = $this->productSyncLimit - $delta;
    }

    /**
     * Send GET request to Yotpo to fetch the existing data details
     *
     * @param string $url
     * @param int|string $requestIds
     * @param string $type
     * @return array<int, mixed>
     * @throws NoSuchEntityException
     */
    public function getExistingProductsFromAPI($url, $requestIds, $type)
    {
        $data = ['external_ids' => $requestIds, 'entityLog' => 'catalog'];
        $response = $this->coreSync->sync('GET', $url, $data, true);

        $products = [];
        if (is_object($response) && $response->getResponse()) {
            $responseData = $response->getResponse();
            if ($responseData && is_array($responseData)) {
                if ($type == 'variants') {
                    $products = array_key_exists('variants', $responseData) ? $responseData['variants'] : [];
                } else {
                    $products = array_key_exists('products', $responseData) ? $responseData['products'] : [];
                }
            }
        }
        return $products;
    }

    /**
     * @param array<mixed> $apiParam
     * @return int|string
     * @throws NoSuchEntityException
     */
    protected function getProductParentIdFromSyncTable($apiParam)
    {
        $yotpoId = isset($apiParam['yotpo_id_parent']) ? $apiParam['yotpo_id_parent'] : 0;
        if (!$yotpoId) {
            return 0;
        }

        $connection = $this->getConnection();
        $tableName = $this->getTableName('yotpo_product_sync');
        $productIdByYotpoIdAndStoreIdQuery = $connection->select()
            ->from($tableName, 'product_id')
            ->where('yotpo_id = ?', $yotpoId)
            ->where('store_id = ?', $this->coreConfig->getStoreId());

        return $connection->fetchOne($productIdByYotpoIdAndStoreIdQuery);
    }

    /**
     * @param array<string, string> $params
     * @param array<string, int|string> $apiParam
     * @param array<mixed> $itemData
     * @param int $itemId
     * @return mixed
     * @throws NoSuchEntityException
     */
    protected function processDeleteRetry($params, $apiParam, $itemData, $itemId)
    {
        $parentYotpoId = '';
        $childYotpoId = '';

        $parentId = $this->getProductParentIdFromSyncTable($apiParam);
        if ($params['method'] === $this->coreConfig->getProductSyncMethod('deleteProduct') || $parentId) {
            $requestId = $parentId ?: $itemId;
            $url = $this->coreConfig->getEndpoint('products');
            $existingProduct = $this->getExistingProductsFromAPI($url, $requestId, 'products');

            if (is_array($existingProduct)) {
                if (count($existingProduct)) {
                    $parentYotpoId = $existingProduct[0]['yotpo_id'];
                }
            }

            $itemData = [
                'product_id' => $itemId,
                'yotpo_id' => $parentYotpoId,
                'yotpo_id_parent' => ''
            ];
        }

        if ($params['method'] === $this->coreConfig->getProductSyncMethod('deleteProductVariant')) {
            if ($parentYotpoId) {
                $url = $this->coreConfig->getEndpoint(
                    'variant',
                    ['{yotpo_product_id}'],
                    [$parentYotpoId]
                );
                $existingVariant = $this->getExistingProductsFromAPI($url, $itemId, 'variants');
                if (is_array($existingVariant)) {
                    if (count($existingVariant)) {
                        $childYotpoId = $existingVariant[0]['yotpo_id'];
                    }
                }
                $itemData = [
                    'product_id' => $itemId,
                    'yotpo_id' => $childYotpoId,
                    'yotpo_id_parent' => $parentYotpoId
                ];
            }
        }

        $params = $this->getDeleteApiParams($itemData, 'yotpo_id');
        $itemData = ['is_discontinued' => true];

        $responseObject = $this->handleRequest($itemId, $itemData, $params);
        return $responseObject['response'];
    }

    /**
     * @param bool $flag
     * @return void
     */
    public function setNormalSyncFlag($flag)
    {
        $this->normalSync = $flag;
    }

    /**
     * @param array <int> $productIds
     * @param array <int | null> $storeId
     * @return void
     */
    public function removeProductFromSyncTable($productIds, $storeId)
    {
        if (!$productIds) {
            return;
        }
        $connection = $this->getConnection();
        $tableName = $this->getTableName('yotpo_product_sync');
        $whereConditions = [
            $connection->quoteInto('product_id IN (?)', $productIds)
        ];
        if ($storeId) {
            $whereConditions[] = $connection->quoteInto('store_id IN (?)', $storeId);
        }
        $connection->delete($tableName, $whereConditions);
    }

    /**
     * @param array <mixed> $yotpoData
     * @param integer $parentId
     * @return bool
     */
    public function isProductParentYotpoIdFound($yotpoData, $parentId): bool
    {
        return isset($yotpoData[$parentId])
            && isset($yotpoData[$parentId]['yotpo_id'])
            && $yotpoData[$parentId]['yotpo_id'];
    }

    /**
     * @param string $productId
     * @return string
     */
    public function getYotpoIdFromProductsSyncTableByProductId($productId)
    {
        $storeId = $this->coreConfig->getStoreId();
        $connection = $this->resourceConnection->getConnection();
        $productYotpoIdQuery = $connection->select(
        )->from(
            [ $this->resourceConnection->getTableName($this::YOTPO_PRODUCT_SYNC_TABLE_NAME) ],
            [ 'yotpo_id' ]
        )->where(
            'product_id = ?',
            $productId
        )->where(
            'store_id = ?',
            $storeId
        );

        $productYotpoId = $connection->fetchOne($productYotpoIdQuery, 'yotpo_id');
        return $productYotpoId;
    }

    /**
     * @param array<int|string> $productsIds
     * @return array<string>
     */
    public function getYotpoIdsFromProductsSyncTableByProductIds(array $productsIds)
    {
        $storeId = $this->coreConfig->getStoreId();
        $connection = $this->resourceConnection->getConnection();
        $productsYotpoIdsQuery = $connection->select(
        )->from(
            [ $this->resourceConnection->getTableName($this::YOTPO_PRODUCT_SYNC_TABLE_NAME) ],
            [ 'product_id' , 'yotpo_id' ]
        )->where(
            'product_id IN (?)',
            $productsIds
        )->where(
            'store_id = ?',
            $storeId
        );

        $productsSyncData = $connection->fetchAssoc($productsYotpoIdsQuery, 'product_id');

        $productIdsToYotpoIdsMap = [];
        foreach ($productsSyncData as $productId => $productSyncRecord) {
            $productIdsToYotpoIdsMap[$productId] = $productSyncRecord['yotpo_id'];
        }

        return $productIdsToYotpoIdsMap;
    }

    /**
     * @param integer $itemEntityId
     * @param integer $parentItemId
     * @param array <mixed> $yotpoSyncTableItemsData
     * @return bool
     */
    public function isProductParentYotpoIdChanged($itemEntityId, $parentItemId, $yotpoSyncTableItemsData)
    {
        return array_key_exists($itemEntityId, $yotpoSyncTableItemsData)
            && array_key_exists($parentItemId, $yotpoSyncTableItemsData)
            && $yotpoSyncTableItemsData[$itemEntityId]['yotpo_id_parent'] != $yotpoSyncTableItemsData[$parentItemId]['yotpo_id'];
    }

    /**
     * @return boolean
     */
    protected function isSyncingAsMainEntity()
    {
        return $this->normalSync;
    }
}
