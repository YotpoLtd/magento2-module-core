<?php

namespace Yotpo\Core\Model\Sync\Reset;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\Data\Main as SyncDataMain;

class Main
{
    const DELETE_LIMIT = 10000;
    const UPDATE_LIMIT = 2000;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var TypeListInterface
     */
    protected $cacheTypeList;

    /**
     * @var SyncDataMain
     */
    protected $syncDataMain;

    /**
     * @param ResourceConnection $resourceConnection
     * @param Config $config
     * @param TypeListInterface $cacheTypeList
     * @param SyncDataMain $syncDataMain
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        Config $config,
        TypeListInterface $cacheTypeList,
        SyncDataMain $syncDataMain
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->config = $config;
        $this->cacheTypeList = $cacheTypeList;
        $this->syncDataMain = $syncDataMain;
    }

    /**
     * @param int $storeId
     * @param boolean $clearSyncTables
     * @return void
     * @throws \Zend_Db_Statement_Exception
     */
    public function resetSync($storeId, $clearSyncTables = true)
    {
        $this->setResetInProgressConfig($storeId, '1');
        $this->deleteRunningCronSchedules();
        if ($clearSyncTables) {
            $this->deleteAllRecordsFromTables($storeId);
        }
    }

    /**
     * @return array <string>
     */
    protected function getTableResourceNames()
    {
        return [];
    }

    /**
     * @return array <string>
     */
    protected function getCronJobCodes()
    {
        return [];
    }

    /**
     * @return string
     */
    protected function getYotpoEntityName()
    {
        return '';
    }

    /**
     * @param int $storeId
     * @return void
     * @throws \Zend_Db_Statement_Exception
     */
    private function deleteAllRecordsFromTables($storeId)
    {
        $tableResourceNames = $this->getTableResourceNames();
        foreach ($tableResourceNames as $tableResourceName) {
            $this->deleteAllRecordsFromTable($storeId, $tableResourceName);
        }
    }

    /**
     * @param int $storeId
     * @param string $tableResourceName
     * @return void
     * @throws \Zend_Db_Statement_Exception
     */
    private function deleteAllRecordsFromTable($storeId, $tableResourceName)
    {
        $tableName = $this->resourceConnection->getTableName($tableResourceName);
        $totalCount = $this->getTotalCount($tableName, $storeId);
        if (!$totalCount) {
            return;
        }
        $storeIdColumnName = $this->getStoreIdColumnName($tableName);
        $connection  = $this->resourceConnection->getConnection();
        while ($totalCount > 0) {
            $totalCount -= self::DELETE_LIMIT;
            $select = $connection
                ->select()
                ->from($tableName)
                ->where($storeIdColumnName . ' = \''.$storeId.'\'')
                ->limit(self::DELETE_LIMIT);

            $query = $connection->deleteFromSelect($select, new \Zend_Db_Expr(''));
            $connection->query($query);
        }
    }

    /**
     * @param string $tableName
     * @param int $storeId
     * @return int
     * @throws \Zend_Db_Statement_Exception
     */
    private function getTotalCount($tableName, $storeId)
    {
        $storeIdColumnName = $this->getStoreIdColumnName($tableName);
        $connection  = $this->resourceConnection->getConnection();
        $query = $connection->select()
            ->from($tableName)
            ->where($storeIdColumnName . ' = ?', $storeId);
        return $connection->query($query)->rowCount();
    }

    /**
     * @param string $tableName
     * @return string
     */
    private function getStoreIdColumnName($tableName)
    {
        if (stripos($tableName, Catalog::CATEGORY_PRODUCT_MAP_SYNC_TABLE) !== false) {
            return 'magento_store_id';
        }
        return 'store_id';
    }

    /**
     * @return void
     */
    private function deleteRunningCronSchedules()
    {
        $jobCodes = $this->getCronJobCodes();
        if (!$jobCodes) {
            return;
        }
        $connection  = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('cron_schedule');
        $select = $connection
            ->select()
            ->from($tableName)
            ->where('job_code IN (?)', $jobCodes)
            ->where('status=\'running\'');
        $query = $connection->deleteFromSelect($select, new \Zend_Db_Expr(''));
        $connection->query($query);
    }

    /**
     * @param int $storeId
     * @param string $flag
     * @return void
     */
    public function setResetInProgressConfig($storeId, $flag)
    {
        $yotpoEntityName = $this->getYotpoEntityName();
        if (!$yotpoEntityName) {
            return;
        }
        $key = 'reset_sync_in_progress_' . $yotpoEntityName;
        $this->config->saveConfig($key, $flag, $storeId);
        $this->cacheTypeList->cleanType(\Magento\Framework\App\Cache\Type\Config::TYPE_IDENTIFIER);
    }

    /**
     * @param string $tableName
     * @param int $attributeId
     * @param int|null $storeId
     * @return int
     */
    public function getCountOfEntities($tableName, $attributeId, $storeId = null)
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

    /**
     * @param int $storeId
     * @param string $attributeCode
     * @param string $tableName
     * @return void
     */
    public function updateEntityAttributeTableData($storeId, $attributeCode, $tableName)
    {
        $connection =   $this->resourceConnection->getConnection();
        $attributeId = $this->syncDataMain->getAttributeId($attributeCode);
        $totalCount = $this->getCountOfEntities($tableName, $attributeId, $storeId);
        $tableName = $this->resourceConnection->getTableName($tableName);
        $limit = self::UPDATE_LIMIT;
        while ($totalCount > 0) {
            $updateQuery = sprintf(
                'UPDATE `%s` SET `value` = %d WHERE `attribute_id` = %d AND `store_id` = %d AND
                                   `value` = 1 LIMIT %d',
                $tableName,
                0,
                $attributeId,
                $storeId,
                $limit
            );
            $connection->query($updateQuery);
            $totalCount -= self::UPDATE_LIMIT;
        }
    }

    /**
     * @param string $attributeCode
     * @param string $tableName
     * @return void
     */
    public function updateEntityAttributeTableDataWithoutStoreId($attributeCode, $tableName)
    {
        $connection =   $this->resourceConnection->getConnection();
        $attributeId = $this->syncDataMain->getAttributeId($attributeCode);
        $totalCount = $this->getCountOfEntities($tableName, $attributeId);
        $tableName = $this->resourceConnection->getTableName($tableName);
        $limit = self::UPDATE_LIMIT;
        while ($totalCount > 0) {
            $updateQuery = sprintf(
                'UPDATE `%s` SET `value` = %d WHERE `attribute_id` = %d AND `value` = 1 LIMIT %d',
                $tableName,
                0,
                $attributeId,
                $limit
            );
            $connection->query($updateQuery);
            $totalCount -= self::UPDATE_LIMIT;
        }
    }
}
