<?php

namespace Yotpo\Core\Model\Sync\Reset;

use Yotpo\Core\Model\Config as CoreConfig;

class Catalog extends Main
{
    const PRODUCT_SYNC_TABLE = 'yotpo_product_sync';
    const CATEGORY_SYNC_TABLE = 'yotpo_category_sync';
    const CATEGORY_PRODUCT_MAP_SYNC_TABLE = 'yotpo_collections_products_sync';
    const CRONJOB_CODES = ['yotpo_cron_core_category_sync','yotpo_cron_core_products_sync'];
    const YOTPO_ENTITY_NAME = 'catalog';

    /**
     * @return string
     */
    public function getYotpoEntityName()
    {
        return self::YOTPO_ENTITY_NAME;
    }

    /**
     * @param int $storeId
     * @param boolean $clearSyncTables
     * @return void
     * @throws \Zend_Db_Statement_Exception
     */
    public function resetSync($storeId, $clearSyncTables = true)
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
        return [self::PRODUCT_SYNC_TABLE, self::CATEGORY_SYNC_TABLE, self::CATEGORY_PRODUCT_MAP_SYNC_TABLE];
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
            $this->updateEntityAttributeTableData($storeId, $data['attribute_code'], $data['table_name']);
        }
    }
}
