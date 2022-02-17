<?php

namespace Yotpo\Core\Model\Sync\Reset;

use Magento\Framework\App\ResourceConnection;
use Yotpo\Core\Model\AbstractJobs;
use Yotpo\Core\Model\Config as CoreConfig;
use Yotpo\Core\Model\Sync\Data\Main as SyncDataMain;

class Catalog extends Main
{
    const PRODUCT_SYNC_TABLE = 'yotpo_product_sync';
    const CATEGORY_SYNC_TABLE = 'yotpo_category_sync';
    const CRONJOB_CODES = ['yotpo_cron_core_category_sync','yotpo_cron_core_products_sync'];

    /**
     * @var SyncDataMain
     */
    protected $syncDataMain;

    /**
     * @param ResourceConnection $resourceConnection
     * @param CoreConfig $coreConfig
     * @param SyncDataMain $syncDataMain
     * @param AbstractJobs $abstractJobs
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        CoreConfig $coreConfig,
        SyncDataMain $syncDataMain,
        AbstractJobs $abstractJobs
    ) {
        parent::__construct(
            $resourceConnection,
            $coreConfig,
            $abstractJobs
        );
        $this->syncDataMain = $syncDataMain;
    }

    /**
     * @param int $storeId
     * @return void
     * @throws \Zend_Db_Statement_Exception
     */
    public function resetSync($storeId)
    {
        $this->setStoreId($storeId);
        $this->setCronJobCodes(self::CRONJOB_CODES);
        parent::resetSync($storeId);
        $catalogTables = [self::PRODUCT_SYNC_TABLE, self::CATEGORY_SYNC_TABLE];
        foreach ($catalogTables as $table) {
            $tableName = $this->resourceConnection->getTableName($table);
            $this->deleteAllFromTable($tableName, $storeId);
        }
        $this->resetCatalogSyncAttributes();
    }

    /**
     * @return void
     */
    public function resetCatalogSyncAttributes()
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
            $connection->update(
                $this->resourceConnection->getTableName($data['table_name']),
                ['value' => '0'],
                [
                    'attribute_id' => $this->syncDataMain->getAttributeId($data['attribute_code']),
                    'store_id' => $this->getStoreId()
                ]
            );
        }
    }
}
