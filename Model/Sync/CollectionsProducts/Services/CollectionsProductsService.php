<?php

namespace Yotpo\Core\Model\Sync\CollectionsProducts\Services;

use Yotpo\Core\Model\AbstractJobs;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Yotpo\Core\Model\Config as CoreConfig;
use Yotpo\Core\Model\Sync\Catalog\YotpoResource;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ProductTypeConfigurable;

class CollectionsProductsService extends AbstractJobs
{
    const PRODUCT_SYNC_LIMIT_CONFIG_KEY = 'product_sync_limit';
    const YOTPO_COLLECTIONS_PRODUCTS_SYNC_TABLE_NAME = 'yotpo_collections_products_sync';

    /**
     * @var ProductTypeConfigurable
     */
    protected $productTypeConfigurable;

    /**
     * @var CoreConfig
     */
    protected $coreConfig;

    /**
     * @var YotpoResource
     */
    protected $yotpoResource;

    /**
     * @var int
     */
    protected $collectionsProductsSyncBatchSize = null;

    /**
     * Processor constructor.
     * @param AppEmulation $appEmulation
     * @param ResourceConnection $resourceConnection
     * @param CoreConfig $coreConfig
     * @param YotpoResource $yotpoResource
     * @param ProductTypeConfigurable $productTypeConfigurable
     **/
    public function __construct(
        AppEmulation $appEmulation,
        ResourceConnection $resourceConnection,
        CoreConfig $coreConfig,
        YotpoResource $yotpoResource,
        ProductTypeConfigurable $productTypeConfigurable
    ) {
        $this->coreConfig = $coreConfig;
        $this->yotpoResource = $yotpoResource;
        $this->productTypeConfigurable = $productTypeConfigurable;
        $this->collectionsProductsSyncBatchSize = $this->coreConfig->getConfig($this::PRODUCT_SYNC_LIMIT_CONFIG_KEY);
        parent::__construct($appEmulation, $resourceConnection);
    }

    /**
     * @param integer $collectionsProductsSyncBatchSize
     * @return array<string>
     */
    public function getCollectionsProductsToSync($collectionsProductsSyncBatchSize)
    {
        $storeId = $this->coreConfig->getStoreId();
        $connection = $this->resourceConnection->getConnection();
        $categoryProductsQuery = $connection->select(
        )->from(
            [ $this->resourceConnection->getTableName($this::YOTPO_COLLECTIONS_PRODUCTS_SYNC_TABLE_NAME) ],
            ['*']
        )->where(
            'magento_store_id = ?',
            $storeId
        )->where(
            'is_synced_to_yotpo = ?',
            0
        )->limit(
            $collectionsProductsSyncBatchSize
        );

        $collectionsProductsToSync = $connection->fetchAll($categoryProductsQuery);
        return $collectionsProductsToSync;
    }

    /**
     * @param string $categoryId
     * @return array<string>
     */
    public function getCategoryProductsIdsFromSyncTable($categoryId)
    {
        $connection = $this->resourceConnection->getConnection();
        $categoryProductsQuery = $connection->select(
        )->from(
            [ $this->resourceConnection->getTableName($this::YOTPO_COLLECTIONS_PRODUCTS_SYNC_TABLE_NAME) ],
            [ 'magento_product_id' ]
        )->where(
            'magento_category_id = ?',
            $categoryId
        );

        $categoryProductsIdsMap = $connection->fetchAssoc($categoryProductsQuery);
        return array_keys($categoryProductsIdsMap);
    }

    /**
     * @param string $productId
     * @return array<int|string>
     */
    public function getCategoryIdsFromSyncTableByProductId($productId)
    {
        $connection = $this->resourceConnection->getConnection();
        $categoryProductsQuery = $connection->select(
        )->from(
            [ $this->resourceConnection->getTableName($this::YOTPO_COLLECTIONS_PRODUCTS_SYNC_TABLE_NAME) ],
            [ 'magento_category_id' ]
        )->where(
            'magento_product_id = ?',
            $productId
        );

        $categoryProductsIdsMap = $connection->fetchAssoc($categoryProductsQuery);
        return array_keys($categoryProductsIdsMap);
    }

    /**
     * @param array<int|string> $productsIds
     * @param int $storeId
     * @param int|string $categoryId
     * @param boolean $isDeletedInMagento
     * @return void
     */
    public function assignCategoryProductsForCollectionsProductsSync(
        $productsIds,
        $storeId,
        $categoryId,
        $isDeletedInMagento = false
    ) {
        $productIdsEligibleForSync = array_filter($productsIds, function ($productId) {
            return $this->isProductEligibleForProductsCollectionsSync($productId);
        });

        if (!$productIdsEligibleForSync) {
            return;
        }

        $collectionsProductsSyncData = [];
        $currentDatetime = date('Y-m-d H:i:s');
        foreach ($productIdsEligibleForSync as $productId) {
            $collectionsProductsSyncData[] = [
                'magento_store_id' => $storeId,
                'magento_category_id' => $categoryId,
                'magento_product_id' => $productId,
                'is_deleted_in_magento' => $isDeletedInMagento,
                'is_synced_to_yotpo' => 0,
                'last_updated_at' => $currentDatetime
            ];
        }

        $this->insertOnDuplicate($this::YOTPO_COLLECTIONS_PRODUCTS_SYNC_TABLE_NAME, $collectionsProductsSyncData);
    }

    /**
     * @param array<int|string> $categoriesIds
     * @param string $storeId
     * @param int $productId
     * @param boolean $isDeletedInMagento
     * @return void
     */
    public function assignProductCategoriesForCollectionsProductsSync(
        $categoriesIds,
        $storeId,
        $productId,
        $isDeletedInMagento = false
    ) {
        if (!$this->isProductEligibleForProductsCollectionsSync($productId)) {
            return;
        }

        $collectionsProductsSyncData = [];
        $currentDatetime = date('Y-m-d H:i:s');
        foreach ($categoriesIds as $categoryId) {
            $collectionsProductsSyncData[] = [
                'magento_store_id' => $storeId,
                'magento_category_id' => $categoryId,
                'magento_product_id' => $productId,
                'is_deleted_in_magento' => $isDeletedInMagento,
                'is_synced_to_yotpo' => 0,
                'last_updated_at' => $currentDatetime
            ];
        }

        $this->insertOnDuplicate($this::YOTPO_COLLECTIONS_PRODUCTS_SYNC_TABLE_NAME, $collectionsProductsSyncData);
    }

    /**
     * @param int $storeId
     * @param array<mixed> $collectionProductEntityToSync
     * @return void
     */
    public function updateCollectionsProductsSyncDataAsSyncedToYotpo($storeId, $collectionProductEntityToSync)
    {
        $currentDatetime = date('Y-m-d H:i:s');
        $collectionsProductsSyncData = [
            'magento_store_id' => $storeId,
            'magento_category_id' => $collectionProductEntityToSync['magento_category_id'],
            'magento_product_id' => $collectionProductEntityToSync['magento_product_id'],
            'is_deleted_in_magento' => $collectionProductEntityToSync['is_deleted_in_magento'],
            'is_synced_to_yotpo' => 1,
            'last_updated_at' => $currentDatetime
        ];

        $this->insertOnDuplicate($this::YOTPO_COLLECTIONS_PRODUCTS_SYNC_TABLE_NAME, [$collectionsProductsSyncData]);
    }

    /**
     * @param string $categoryId
     * @return void
     */
    public function updateCollectionProductsSyncDataAsDeletedInYotpo($categoryId)
    {
        $currentDatetime = date('Y-m-d H:i:s');
        $connection = $this->resourceConnection->getConnection();
        $updateCondition = [
            'magento_category_id = ?' => $categoryId,
            'is_deleted_in_magento = ?' => 0
        ];
        $dataToUpdate = [
            'is_synced_to_yotpo' => 0,
            'is_deleted_in_magento' => 1,
            'last_updated_at' => $currentDatetime
        ];
        $connection->update(
            $this->resourceConnection->getTableName($this::YOTPO_COLLECTIONS_PRODUCTS_SYNC_TABLE_NAME),
            $dataToUpdate,
            $updateCondition
        );
    }

    /**
     * @param int $storeId
     * @param string $productId
     * @return void
     */
    public function forceUpdateProductCollectionsForResync($storeId, $productId)
    {
        $connection = $this->resourceConnection->getConnection();
        $updateCondition = [
            'magento_store_id = ?' => $storeId,
            'magento_product_id = ?' => $productId,
            'is_deleted_in_magento = ?' => 0
        ];
        $currentDatetime = date('Y-m-d H:i:s');
        $connection->update(
            $this->resourceConnection->getTableName($this::YOTPO_COLLECTIONS_PRODUCTS_SYNC_TABLE_NAME),
            ['is_synced_to_yotpo' => 0, 'last_updated_at' => $currentDatetime],
            $updateCondition
        );
    }

    /**
     * @param int|string $productId
     * @return bool
     */
    private function isProductEligibleForProductsCollectionsSync($productId)
    {
        $parentProduct = $this->productTypeConfigurable->getParentIdsByChild((int) $productId);
        if (!$parentProduct) {
            return true;
        }

        return false;
    }
}
