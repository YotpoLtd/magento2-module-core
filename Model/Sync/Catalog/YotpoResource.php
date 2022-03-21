<?php
namespace Yotpo\Core\Model\Sync\Catalog;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Yotpo\Core\Model\Config as CoreConfig;
use Magento\GroupedProduct\Model\ResourceModel\Product\Link as GroupedLink;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ProductTypeConfigurable;

/**
 * Class YotpoResource
 * Manage data from Yotpo sync table
 */
class YotpoResource
{
    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var CoreConfig
     */
    protected $coreConfig;

    /**
     * @var ProductTypeConfigurable
     */
    protected $productTypeConfigurable;

    /**
     * @var array <mixed>
     */
    protected $loadedConfigProducts;

    /**
     * Data constructor.
     * @param ResourceConnection $resourceConnection
     * @param CoreConfig $coreConfig
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        CoreConfig $coreConfig,
        ProductTypeConfigurable $productTypeConfigurable
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->coreConfig = $coreConfig;
        $this->productTypeConfigurable= $productTypeConfigurable;
    }

    /**
     * @param array<int, int> $productsId
     * @param array<int|string, int|string> $parentIds
     * @return array<int|string, mixed>
     * @throws NoSuchEntityException
     */
    public function fetchYotpoData(array $productsId, array $parentIds): array
    {
        $return = [];
        $request = array_merge($productsId, $parentIds);
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from(
            ['yotpo' => $this->resourceConnection->getTableName('yotpo_product_sync')],
            ['*']
        )->where(
            $connection->quoteInto('yotpo.product_id IN (?)', $request)
        )->where(
            $connection->quoteInto('yotpo.store_id = ?', $this->coreConfig->getStoreId())
        );

        $items = $connection->fetchAssoc($select, []);

        $yotpoData = [];
        if ($items) {
            foreach ($items as $item) {
                $yotpoData[$item['product_id']] = $item;
            }
        }

        $return['yotpo_data'] = $yotpoData;
        return $return;
    }

    /**
     * Collection for fetching the data to delete
     * @param int $storeId
     * @param int $limit
     * @return array<int, array<string, int>>
     */
    public function getToDeleteCollection(int $storeId, int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from(
            ['yotpo' => $this->resourceConnection->getTableName('yotpo_product_sync')],
            ['product_id', 'yotpo_id', 'yotpo_id_parent']
        )->where(
            $connection->quoteInto('yotpo.is_deleted = ?', 1)
        )->where(
            $connection->quoteInto('yotpo.is_deleted_at_yotpo != ?', 1)
        )->where(
            $connection->quoteInto('yotpo.store_id = ?', $storeId)
        )->limit(
            $limit
        );

        return $connection->fetchAssoc($select, 'product_id');
    }

    /**
     * Collection for fetching the data to delete
     * @param int $storeId
     * @param int $limit
     * @return array<int, array<string, int>>
     */
    public function getUnAssignedCollection(int $storeId, int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from(
            ['yotpo' => $this->resourceConnection->getTableName('yotpo_product_sync')],
            ['product_id', 'yotpo_id_unassign', 'yotpo_id_parent']
        )->where(
            $connection->quoteInto('yotpo.yotpo_id_unassign != ?', 0)
        )->where(
            $connection->quoteInto('yotpo.store_id = ?', $storeId)
        )->limit(
            $limit
        );
        return $connection->fetchAssoc($select, 'product_id');
    }

    /**
     * Get config parent IDs
     * @param array<int, int> $simpleIds
     * @return array<mixed>
     */
    public function getConfigProductIds(array $simpleIds): array
    {
        $configIds = [];
        foreach ($simpleIds as $simpleId) {
            if (!isset($this->loadedConfigProducts[$simpleId])) {
                $parent = $this->productTypeConfigurable->getParentIdsByChild($simpleId);
                if ($parent) {
                    $configIds[$simpleId] = $parent[0];
                    $this->loadedConfigProducts[$simpleId] = $configIds[$simpleId];
                }
            } else {
                $configIds[$simpleId] = $this->loadedConfigProducts[$simpleId];
            }

        }
        return $configIds;
    }

    /**
     * Get grouped parent IDs
     * @param array<int, int> $simpleIds
     * @return array<int|string, mixed>
     */
    public function getGroupProductIds(array $simpleIds): array
    {
        $parentIds = [];
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from(
            $this->resourceConnection->getTableName('catalog_product_link'),
            ['product_id' => 'linked_product_id', 'parent_id' => 'product_id']
        )->where(
            $connection->quoteInto('linked_product_id IN (?)', $simpleIds)
        )->where(
            $connection->quoteInto('link_type_id = ?', GroupedLink::LINK_TYPE_GROUPED)
        );

        $items = $connection->fetchAssoc($select, 'product_id');
        $groupIds = [];
        foreach ($items as $item) {
            $groupIds[$item['product_id']] = $item['parent_id'];
            $parentIds[$item['parent_id']] = $item['parent_id'];
        }
        $realParentIds = [];
        if ($this->coreConfig->isRowIdAvailable() && $parentIds) {
            $select = $connection->select()->from(
                $this->resourceConnection->getTableName('catalog_product_entity'),
                ['entity_id','row_id']
            )->where(
                $connection->quoteInto('row_id IN (?)', array_values($parentIds))
            );
            $groupedParentItems = $connection->fetchAssoc($select);
            foreach ($groupedParentItems as $parentItem) {
                $realParentIds[$parentItem['row_id']] = $parentItem['entity_id'];
            }
            foreach ($items as $item) {
                $groupIds[$item['product_id']] = $realParentIds[$item['parent_id']];
            }
        }
        return $groupIds;
    }
}
