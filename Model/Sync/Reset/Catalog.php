<?php

namespace Yotpo\Core\Model\Sync\Reset;

use Magento\Framework\App\ResourceConnection;
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
     * @param SyncDataMain $syncDataMain
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        SyncDataMain $syncDataMain
    ) {
        parent::__construct(
            $resourceConnection
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
        parent::resetSync($storeId);
        $this->resetCatalogSyncAttributes($storeId);
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
            $connection->update(
                $this->resourceConnection->getTableName($data['table_name']),
                ['value' => '0'],
                [
                    'attribute_id' => $this->syncDataMain->getAttributeId($data['attribute_code']),
                    'store_id' => $storeId
                ]
            );
        }
    }
}
