<?php

namespace Yotpo\Core\Model\Sync\Reset;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\Data\Main as SyncDataMain;
use Yotpo\Core\Model\Config as CoreConfig;

class Catalog extends Main
{
    const PRODUCT_SYNC_TABLE = 'yotpo_product_sync';
    const CATEGORY_SYNC_TABLE = 'yotpo_category_sync';
    const CRONJOB_CODES = ['yotpo_cron_core_category_sync','yotpo_cron_core_products_sync'];
    const YOTPO_ENTITY_NAME = 'catalog';

    /**
     * @var SyncDataMain
     */
    protected $syncDataMain;

    /**
     * @param ResourceConnection $resourceConnection
     * @param SyncDataMain $syncDataMain
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        Config $config,
        TypeListInterface $cacheTypeList,
        SyncDataMain $syncDataMain
    ) {
        parent::__construct(
            $resourceConnection,
            $config,
            $cacheTypeList
        );
        $this->syncDataMain = $syncDataMain;
    }

    /**
     * @return string
     */
    public function getYotpoEntityName()
    {
        return self::YOTPO_ENTITY_NAME;
    }

    /**
     * @param int $storeId
     * @param boolean $skipSyncTables
     * @return void
     * @throws \Zend_Db_Statement_Exception
     */
    public function resetSync($storeId, $skipSyncTables = false)
    {
        parent::resetSync($storeId);
        $this->resetCatalogSyncAttributes($storeId);
        $this->setResetInProgressConfig($storeId, '0');
    }

    /**
     * @return array <string>
     */
    protected function getTableResourceNames()
    {
        return [self::PRODUCT_SYNC_TABLE, self::CATEGORY_SYNC_TABLE];
    }

    /**
     * @return array <string>
     */
    protected function getCronJobCodes()
    {
        return self::CRONJOB_CODES;
    }

    /**
     * @param int $storeId
     * @return void
     */
    private function resetCatalogSyncAttributes($storeId)
    {
        $dataSet = [
            [
                'table_name' => 'catalog_category_entity_int',
                'attribute_code' => CoreConfig::CATEGORY_SYNC_ATTR_CODE
            ],
            [
                'table_name' => 'catalog_product_entity_int',
                'attribute_code' => CoreConfig::CATALOG_SYNC_ATTR_CODE
            ],
        ];
        foreach ($dataSet as $data) {
            $connection =   $this->resourceConnection->getConnection();
            $attributeId = $this->syncDataMain->getAttributeId($data['attribute_code']);
            $totalCount = $this->getCountOfEntities($data['table_name'], $attributeId, $storeId);
            $tableName = $this->resourceConnection->getTableName($data['table_name']);
            while ($totalCount > 0) {
                if ($totalCount > self::UPDATE_LIMIT) {
                    $limit = self::UPDATE_LIMIT;
                    $totalCount -= self::UPDATE_LIMIT;
                } else {
                    $limit = $totalCount;
                    $totalCount = 0;
                }
                $updateQuery = sprintf(
                    'UPDATE `%s` SET `value` = %d WHERE `attribute_id` = %d AND `store_id` = %d AND `value` = 1
                        ORDER BY `value_id` ASC LIMIT %d',
                    $this->resourceConnection->getTableName($tableName),
                    0,
                    $this->syncDataMain->getAttributeId($data['attribute_code']),
                    $storeId,
                    $limit
                );
                $connection->query($updateQuery);
            }
        }
    }

    /**
     * @param string $tableName
     * @param int $attributeId
     * @param int $storeId
     * @return int
     */
    public function getCountOfEntities($tableName, $attributeId, $storeId)
    {
        $connection  = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName($tableName);
        $select = $connection->select();
        $query = $select->reset()
            ->from(
                ['p' => $tableName]
            );
        if ($storeId) {
            $query->where('store_id = ?', $storeId);
        }
        $query->where('attribute_id = ?', $attributeId);
        $query->where('value = ?', 1);
        return $connection->query($query)->rowCount();
    }
}
