<?php

namespace Yotpo\Core\Model\Sync\CollectionsProducts\Services;

use Yotpo\Core\Model\AbstractJobs;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Yotpo\Core\Model\Config as CoreConfig;
use Yotpo\Core\Model\Sync\Catalog\YotpoResource;

class CollectionsProductsService extends AbstractJobs
{
    const PRODUCT_SYNC_LIMIT_CONFIG_KEY = 'product_sync_limit';
    const YOTPO_COLLECTIONS_PRODUCTS_SYNC_TABLE_NAME = 'yotpo_collections_products_sync';

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
     **/
    public function __construct(
        AppEmulation $appEmulation,
        ResourceConnection $resourceConnection,
        CoreConfig $coreConfig,
        YotpoResource $yotpoResource
    ) {
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
}
