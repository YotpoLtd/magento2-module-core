<?php

namespace Yotpo\Core\Model\Sync\Reset;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Yotpo\Core\Model\Config;

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
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        Config $config,
        TypeListInterface $cacheTypeList
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->config = $config;
        $this->cacheTypeList = $cacheTypeList;
    }

    /**
     * @param int $storeId
     * @param boolean $skipSyncTables
     * @return void
     * @throws \Zend_Db_Statement_Exception
     */
    public function resetSync($storeId, $skipSyncTables = false)
    {
        $this->setResetInProgressConfig($storeId, '1');
        $this->deleteRunningCronSchedules();
        if (!$skipSyncTables) {
            $this->deleteAllFromTables($storeId);
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
    private function deleteAllFromTables($storeId)
    {
        $tableResourceNames = $this->getTableResourceNames();
        foreach ($tableResourceNames as $tableResourceName) {
            $this->deleteAllFromTable($storeId, $tableResourceName);
        }
    }

    /**
     * @param int $storeId
     * @param string $tableResourceName
     * @return void
     * @throws \Zend_Db_Statement_Exception
     */
    private function deleteAllFromTable($storeId, $tableResourceName)
    {
        $tableName = $this->resourceConnection->getTableName($tableResourceName);
        $totalCount = $this->getTotalCount($tableName, $storeId);
        if (!$totalCount) {
            return;
        }
        $connection  = $this->resourceConnection->getConnection();
        while ($totalCount > 0) {
            $totalCount -= self::DELETE_LIMIT;
            $select = $connection
                ->select()
                ->from($tableName)
                ->where('store_id = \''.$storeId.'\'')
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
        $connection  = $this->resourceConnection->getConnection();
        $query = $connection->select()
            ->from($tableName)
            ->where('store_id = ?', $storeId);
        return $connection->query($query)->rowCount();
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
        $key = 'reset_sync_in_progress_' . $yotpoEntityName;
        $this->config->saveConfig($key, $flag, $storeId);
        $this->cacheTypeList->cleanType(\Magento\Framework\App\Cache\Type\Config::TYPE_IDENTIFIER);
    }
}
