<?php

namespace Yotpo\Core\Model\Sync\Reset;

use Magento\Framework\App\ResourceConnection;

class Main
{
    const DELETE_LIMIT = 10000;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ResourceConnection $resourceConnection
    ) {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @param int $storeId
     * @return void
     * @throws \Zend_Db_Statement_Exception
     */
    public function resetSync($storeId)
    {
        $this->deleteRunningCronSchedules();
        $this->deleteAllFromTables($storeId);
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
}
