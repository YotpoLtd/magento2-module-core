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
     * @param ProductTypeConfigurable $productTypeConfigurable
     * @param CoreConfig $coreConfig
     * @param YotpoResource $yotpoResource
     **/
    public function __construct(
        AppEmulation $appEmulation,
        ResourceConnection $resourceConnection,
        ProductTypeConfigurable $productTypeConfigurable,
        CoreConfig $coreConfig,
        YotpoResource $yotpoResource
    ) {
        $this->productTypeConfigurable = $productTypeConfigurable;
        $this->coreConfig = $coreConfig;
        $this->yotpoResource = $yotpoResource;
        $this->collectionsProductsSyncBatchSize = $this->coreConfig->getConfig($this::PRODUCT_SYNC_LIMIT_CONFIG_KEY);
        parent::__construct($appEmulation, $resourceConnection);
    }

    /**
     * @param integer $collectionsProductsSyncBatchSize
     * @return array<string>
     */
    public function getCollectionsProductsToSync($collectionsProductsSyncBatchSize) {
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
            false
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
    public function getCategoryProductsIdsFromSyncTable($categoryId) {
        $connection = $this->resourceConnection->getConnection();
        $categoryProductsQuery = $connection->select(
        )->from(
            ['entity' => $this->resourceConnection->getTableName($this::YOTPO_COLLECTIONS_PRODUCTS_SYNC_TABLE_NAME)],
            ['magento_product_id']
        )->where(
            'magento_category_id = ?',
            $categoryId
        );

        $categoryProductsIdsMap = $connection->fetchAssoc($categoryProductsQuery, 'magento_product_id');

        $categoryProductsIds = [];
        foreach ($categoryProductsIdsMap as $categoryProductsIdMap) {
            $categoryProductsIds[] = $categoryProductsIdMap['magento_product_id'];
        }

        return $categoryProductsIds;
    }

    /**
     * @param string $productId
     * @return array<string>
     */
    public function getCategoryIdsFromSyncTableByProductId($productId) {
        $connection = $this->resourceConnection->getConnection();
        $categoryProductsQuery = $connection->select(
        )->from(
            ['entity' => $this->resourceConnection->getTableName($this::YOTPO_COLLECTIONS_PRODUCTS_SYNC_TABLE_NAME)],
            ['magento_category_id']
        )->where(
            'magento_product_id = ?',
            $productId
        );

        $categoryProductsIdsMap = $connection->fetchAssoc($categoryProductsQuery, 'magento_category_id');
        return array_keys($categoryProductsIdsMap);
    }

    /**
     * @param array $categoryProductsIds
     * @param string $storeId
     * @param string $categoryId
     * @param boolean $isDeletedInMagento
     * @return void
     */
    public function assignCategoryProductsForCollectionsProductsSync(array $categoryProductsIds, $storeId, $categoryId, $isDeletedInMagento = false)
    {
        $productIdsEligibleForSync = array_filter($categoryProductsIds, function ($productId) {
            return $this->isProductEligibleForProductCollectionsSync($productId);
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
                'is_synced_to_yotpo' => false,
                'last_updated_at' => $currentDatetime
            ];
        }

        $this->insertOnDuplicate($this::YOTPO_COLLECTIONS_PRODUCTS_SYNC_TABLE_NAME, $collectionsProductsSyncData);
    }

    /**
     * @param array $productsCategoriesIds
     * @param string $storeId
     * @param string $productId
     * @param boolean $isDeletedInMagento
     * @return void
     */
    public function assignProductCategoriesForCollectionsProductsSync(array $productsCategoriesIds, $storeId, $productId, $isDeletedInMagento = false)
    {
        if (!$this->isProductEligibleForProductCollectionsSync($productId)) {
            return;
        }

        $collectionsProductsSyncData = [];
        $currentDatetime = date('Y-m-d H:i:s');
        foreach ($productsCategoriesIds as $categoryId) {
            $collectionsProductsSyncData[] = [
                'magento_store_id' => $storeId,
                'magento_category_id' => $categoryId,
                'magento_product_id' => $productId,
                'is_deleted_in_magento' => $isDeletedInMagento,
                'is_synced_to_yotpo' => false,
                'last_updated_at' => $currentDatetime
            ];
        }

        $this->insertOnDuplicate($this::YOTPO_COLLECTIONS_PRODUCTS_SYNC_TABLE_NAME, $collectionsProductsSyncData);
    }

    /**
     * @param string $storeId
     * @param array $collectionProductEntityToSync
     * @return void
     */
    public function updateCollectionsProductsSyncData($storeId, $collectionProductEntityToSync)
    {
        $currentDatetime = date('Y-m-d H:i:s');
        $collectionsProductsSyncData = [
            'magento_store_id' => $storeId,
            'magento_category_id' => $collectionProductEntityToSync['magento_category_id'],
            'magento_product_id' => $collectionProductEntityToSync['magento_product_id'],
            'is_deleted_in_magento' => $collectionProductEntityToSync['is_deleted_in_magento'],
            'is_synced_to_yotpo' => true,
            'last_updated_at' => $currentDatetime
        ];

        $this->insertOnDuplicate($this::YOTPO_COLLECTIONS_PRODUCTS_SYNC_TABLE_NAME, [$collectionsProductsSyncData]);
    }

    private function isProductEligibleForProductCollectionsSync($productId) {
        $parentProduct = $this->productTypeConfigurable->getParentIdsByChild($productId);
        if (!$parentProduct) {
            return true;
        }

        return false;
    }
}
