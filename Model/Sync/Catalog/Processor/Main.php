<?php

namespace Yotpo\Core\Model\Sync\Catalog\Processor;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Yotpo\Core\Model\AbstractJobs;
use Yotpo\Core\Model\Config as CoreConfig;
use Yotpo\Core\Model\Sync\Catalog\Logger as YotpoCoreCatalogLogger;
use Yotpo\Core\Model\Sync\Catalog\YotpoResource;
use Yotpo\Core\Model\Api\Sync as CoreSync;

/**
 * Manage catalog sync process
 */
class Main extends AbstractJobs
{
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
     * @var null|int
     */
    protected $productSyncLimit = null;

    /**
     * @var string|null
     */
    protected $entityIdFieldValue;

    /**
     * AbstractJobs constructor.
     * @param AppEmulation $appEmulation
     * @param ResourceConnection $resourceConnection
     * @param CoreConfig $coreConfig
     * @param YotpoCoreCatalogLogger $yotpoCatalogLogger
     * @param YotpoResource $yotpoResource
     * @param CollectionFactory $collectionFactory
     * @param CoreSync $coreSync
     */
    public function __construct(
        AppEmulation $appEmulation,
        ResourceConnection $resourceConnection,
        CoreConfig $coreConfig,
        YotpoCoreCatalogLogger $yotpoCatalogLogger,
        YotpoResource $yotpoResource,
        CollectionFactory $collectionFactory,
        CoreSync $coreSync
    ) {
        $this->coreConfig = $coreConfig;
        $this->yotpoCatalogLogger = $yotpoCatalogLogger;
        $this->yotpoResource = $yotpoResource;
        $this->collectionFactory = $collectionFactory;
        $this->coreSync = $coreSync;
        $this->entityIdFieldValue = $this->coreConfig->getEavRowIdFieldName();
        parent::__construct($appEmulation, $resourceConnection);
    }

    /**
     * Trigger API
     * @param array<string, string> $params
     * @param mixed $data
     * @return mixed
     * @throws NoSuchEntityException
     */
    protected function processRequest($params, $data)
    {
        switch ($params['method']) {
            case $this->coreConfig->getProductSyncMethod('createProduct'):
            default:
                $data = ['product' => $data, 'entityLog' => 'catalog'];
                $response = $this->coreSync->sync('POST', $params['url'], $data);
                break;
            case $this->coreConfig->getProductSyncMethod('updateProduct'):
            case $this->coreConfig->getProductSyncMethod('deleteProduct'):
            case $this->coreConfig->getProductSyncMethod('unassignProduct'):
                $data = ['product' => $data, 'entityLog' => 'catalog'];
                $response = $this->coreSync->sync('PATCH', $params['url'], $data);
                break;
            case $this->coreConfig->getProductSyncMethod('createProductVariant'):
                $data = ['variant' => $data, 'entityLog' => 'catalog'];
                $response = $this->coreSync->sync('POST', $params['url'], $data);
                break;
            case $this->coreConfig->getProductSyncMethod('updateProductVariant'):
            case $this->coreConfig->getProductSyncMethod('deleteProductVariant'):
            case $this->coreConfig->getProductSyncMethod('unassignProductVariant'):
                $data = ['variant' => $data, 'entityLog' => 'catalog'];
                $response = $this->coreSync->sync('PATCH', $params['url'], $data);
                break;
        }
        return $response;
    }

    /**
     * Handle response
     * @param array<string, int|string> $apiParam
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
        switch ($apiParam['method']) {
            case $this->coreConfig->getProductSyncMethod('createProduct'):
            case $this->coreConfig->getProductSyncMethod('createProductVariant'):
            default:
                if ($visibleVariants) {
                    $yotpoIdkey = 'visible_variant_yotpo_id';
                } else {
                    $yotpoIdkey = 'yotpo_id';
                }
                if ($response->getData('is_success')) {
                    $tempSqlArray[$yotpoIdkey] = $this->getYotpoIdFromResponse($response, $apiParam['method']);
                    $this->writeSuccessLog($apiParam['method'], $storeId, $data);
                } else {
                    if ($response->getStatus() == '409') {
                        $externalIds[] = $data['external_id'];
                    }
                    $tempSqlArray[$yotpoIdkey] = 0;
                    $this->writeFailedLog($apiParam['method'], $storeId, []);
                }
                break;
            case $this->coreConfig->getProductSyncMethod('updateProduct'):
            case $this->coreConfig->getProductSyncMethod('updateProductVariant'):
            case $this->coreConfig->getProductSyncMethod('deleteProduct'):
            case $this->coreConfig->getProductSyncMethod('deleteProductVariant'):
            case $this->coreConfig->getProductSyncMethod('unassignProduct'):
            case $this->coreConfig->getProductSyncMethod('unassignProductVariant'):
                if ($response->getData('is_success')) {
                    $this->writeSuccessLog($apiParam['method'], $storeId, $data);
                    if ($apiParam['method'] === $this->coreConfig->getProductSyncMethod('deleteProduct')
                        || $apiParam['method'] === $this->coreConfig->getProductSyncMethod('deleteProductVariant')) {
                        $tempSqlArray['is_deleted_at_yotpo'] = 1;
                    }
                    if ($apiParam['method'] === $this->coreConfig->getProductSyncMethod('unassignProduct')
                        || $apiParam['method'] === $this->coreConfig->getProductSyncMethod('unassignProductVariant')) {
                        $tempSqlArray['yotpo_id_unassign'] = 0;
                    }
                } else {
                    $this->writeFailedLog($apiParam['method'], $storeId, []);
                }
                break;
        }

        return [
            'temp_sql' => $tempSqlArray,
            'external_id' => $externalIds
        ];
    }

    /**
     * Success Log
     * @param string|int $method
     * @param int $storeId
     * @param mixed $data
     * @return void
     */
    protected function writeSuccessLog($method, $storeId, $data)
    {
        $this->yotpoCatalogLogger->info(
            __('%1 API ran successfully - Magento Store Id: %2', $method, $storeId),
            []
        );
    }

    /**
     * Failed Log
     * @param string|int $method
     * @param int $storeId
     * @param mixed $data
     * @return void
     */
    protected function writeFailedLog($method, $storeId, $data)
    {
        $this->yotpoCatalogLogger->info(
            __('%1 API Failed - Magento Store Id: %2', $method, $storeId),
            []
        );
    }

    /**
     * Get yotpo_id from response
     * @param mixed $response
     * @param string|int $method
     * @return string|int
     */
    protected function getYotpoIdFromResponse($response, $method)
    {
        $array = [
            $this->coreConfig->getProductSyncMethod('createProduct') => 'product',
            $this->coreConfig->getProductSyncMethod('createProductVariant') => 'variant'
        ];
        return $response->getData('response')[$array[$method]]['yotpo_id'];
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
     * @param array<mixed> $unSyncedProductIds
     * @return Collection<mixed>
     */
    protected function getCollectionForSync($unSyncedProductIds = null): Collection
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
        if ($unSyncedProductIds) {
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
     * @param array<int, int> $parentIds
     * @param array<int|string, mixed> $parentData
     * @param boolean $visibleVariants
     * @return array<string, string>
     * @throws NoSuchEntityException
     */
    protected function getApiParams(
        $productId,
        array $yotpoData,
        array $parentIds,
        array $parentData,
        $visibleVariants = false
    ) {
        $apiUrl = $this->coreConfig->getEndpoint('products');
        $method = $this->coreConfig->getProductSyncMethod('createProduct');
        $yotpoIdParent = $yotpoId = '';

        if (count($parentIds) && !$visibleVariants) {
            if (isset($parentIds[$productId])
                && isset($parentData[$parentIds[$productId]])
                && isset($parentData[$parentIds[$productId]]['yotpo_id'])
                && $yotpoIdParent = $parentData[$parentIds[$productId]]['yotpo_id']) {

                $method = $this->coreConfig->getProductSyncMethod('createProductVariant');
                $apiUrl = $this->coreConfig->getEndpoint(
                    'variant',
                    ['{yotpo_product_id}'],
                    [$yotpoIdParent]
                );
            } elseif (isset($parentIds[$productId])) {
                return [];
            }
        }

        $yotpoIdKey = $visibleVariants ? 'visible_variant_yotpo_id' : 'yotpo_id';
        if (count($yotpoData)) {
            if (isset($yotpoData[$productId])
                && isset($yotpoData[$productId][$yotpoIdKey])
            ) {

                $yotpoId = $yotpoData[$productId][$yotpoIdKey] ;

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

        if (!$yotpoId) {
            if ($method == 'createProduct') {
                $url = $this->coreConfig->getEndpoint('products');
                $existingProduct = $this->getExistingProductsFromAPI($url, $productId, 'products');
                if (is_array($existingProduct) && count($existingProduct)) {
                    $yotpoId = $existingProduct[0]['yotpo_id'];
                    $apiUrl = $this->coreConfig->getEndpoint(
                        'updateProduct',
                        ['{yotpo_product_id}'],
                        [$yotpoId]
                    );
                    $method = $this->coreConfig->getProductSyncMethod('updateProduct');
                }
            }
            if ($method == 'createProductVariant' && $yotpoIdParent) {
                $url = $this->coreConfig->getEndpoint(
                    'variant',
                    ['{yotpo_product_id}'],
                    [$yotpoIdParent]
                );
                $existingVariant = $this->getExistingProductsFromAPI($url, $productId, 'variants');
                if (is_array($existingVariant) && count($existingVariant)) {
                    $yotpoId = $existingVariant[0]['yotpo_id'];
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
        $response = $this->coreSync->sync('GET', $url, $data);
        if ($type == 'variants') {
            $products = $response->getResponse()['variants'];
        } else {
            $products = $response->getResponse()['products'];
        }
        return $products;
    }
}
