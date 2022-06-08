<?php

namespace Yotpo\Core\Model\Sync\Catalog\Services;

use Yotpo\Core\Model\AbstractJobs;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Yotpo\Core\Model\Config as CoreConfig;

class ProductsSyncService extends AbstractJobs
{
    const YOTPO_PRODUCT_SYNC_TABLE_NAME = 'yotpo_product_sync';

    /**
     * @var CoreConfig
     */
    protected $coreConfig;

    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * Processor constructor.
     * @param AppEmulation $appEmulation
     * @param ResourceConnection $resourceConnection
     * @param CoreConfig $coreConfig
     * @param CollectionFactory $collectionFactory
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function __construct(
        AppEmulation $appEmulation,
        ResourceConnection $resourceConnection,
        CoreConfig $coreConfig,
        CollectionFactory $collectionFactory
    ) {
        $this->coreConfig = $coreConfig;
        $this->collectionFactory = $collectionFactory;
        parent::__construct($appEmulation, $resourceConnection);
    }

    /**
     * @param array <mixed> $syncDataRecord
     * @return void
     */
    public function updateSyncTable($syncDataRecord)
    {
        $this->insertOnDuplicate($this::YOTPO_PRODUCT_SYNC_TABLE_NAME, [$syncDataRecord]);
    }

    /**
     * @param integer $storeId
     * @param integer $parentYotpoId
     * @return array
     */
    public function getProductIdsFromSyncTableByStoreIdAndParentYotpoId($storeId, $parentYotpoId)
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select(
        )->from(
            [$this->resourceConnection->getTableName($this::YOTPO_PRODUCT_SYNC_TABLE_NAME)],
            ['product_id']
        )->where(
            'store_id = ?',
            $storeId
        )->where(
            'yotpo_id_parent = ?',
            $parentYotpoId
        )->where(
            'is_deleted = ?',
            0
        );
        $items = $connection->fetchAssoc($select);
        return array_keys($items);
    }

    /**
     * @param array<integer> $productIds
     * @return array
     */
    public function findProductsThatShouldBeSyncedByAttribute($productIds)
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect('*');
        $collection->addAttributeToFilter(
            [
                ['attribute' => CoreConfig::CATALOG_SYNC_ATTR_CODE, 'null' => true],
                ['attribute' => CoreConfig::CATALOG_SYNC_ATTR_CODE, 'eq' => '0'],
            ]
        );
        $collection->addFieldToFilter('entity_id', ['in' => $productIds]);

        $productIdsForSync = [];
        $productsCollection = $collection->getItems();
        foreach ($productsCollection as $product) {
            $productIdsForSync[] = $product->getId();
        }

        return $productIdsForSync;
    }

    /**
     * @param integer $storeId
     * @param array $variantIds
     * @param integer $yotpoIdParentToBeUpdated
     * @return void
     */
    public function updateYotpoIdParentInSyncTableByStoreIdAndVariantIds($storeId, $variantIds, $yotpoIdParentToBeUpdated)
    {
        $connection = $this->resourceConnection->getConnection();
        $condition = [
            'store_id = ?' => $storeId,
            'product_id IN (?)' => $variantIds
        ];
        $data = [
            'yotpo_id_parent' => $yotpoIdParentToBeUpdated,
            'response_code' => CoreConfig::CUSTOM_RESPONSE_DATA
        ];
        $connection->update(
            $this->resourceConnection->getTableName($this::YOTPO_PRODUCT_SYNC_TABLE_NAME),
            $data,
            $condition
        );
    }

    /**
     * @param array<mixed> $productsIds
     * @return void
     */
    public function resetProductsResponseCodeByProductsIds($productsIds = [])
    {
        if (!$productsIds) {
            return;
        }

        $condition = [];
        $condition['product_id IN (?) '] = $productsIds;
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName($this::YOTPO_PRODUCT_SYNC_TABLE_NAME),
            ['response_code' => $this->coreConfig::CUSTOM_RESPONSE_DATA],
            $condition
        );
    }
}
