<?php

namespace Yotpo\Core\Model\Sync\Reset;

use Magento\Framework\App\ResourceConnection;
use Yotpo\Core\Model\Config as CoreConfig;
use Yotpo\Core\Model\AbstractJobs;

class Main
{
    const DELETE_LIMIT = 10000;

    /**
     * @var int
     */
    protected $storeId = 0;

    /**
     * @var CoreConfig
     */
    protected $coreConfig;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var AbstractJobs
     */
    protected $abstractJobs;

    /**
     * @var array <string>
     */
    protected $cronJobCodes = [''];

    /**
     * @param ResourceConnection $resourceConnection
     * @param CoreConfig $coreConfig
     * @param AbstractJobs $abstractJobs
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        CoreConfig $coreConfig,
        AbstractJobs $abstractJobs
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->coreConfig = $coreConfig;
        $this->abstractJobs = $abstractJobs;
    }

    /**
     * @param int $storeId
     * @return void
     */
    public function resetSync($storeId)
    {
        $this->deleteCronSchedules();
    }

    /**
     * @param string $tableName
     * @param int $storeId
     * @return void
     * @throws \Zend_Db_Statement_Exception
     */
    protected function deleteAllFromTable($tableName, $storeId)
    {
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
    protected function getTotalCount($tableName, $storeId)
    {
        $connection  = $this->resourceConnection->getConnection();
        $select = $connection->select();
        $query = $select->reset()
            ->from(
                ['p' => $tableName]
            );
        if ($storeId) {
            $query->where('store_id = ?', $storeId);
        }
        return $connection->query($query)->rowCount();
    }

    /**
     * @param int $storeId
     * @return void
     */
    protected function setStoreId($storeId)
    {
        $this->storeId = $storeId;
    }

    /**
     * @return int
     */
    protected function getStoreId()
    {
        return $this->storeId;
    }

    /**
     * @param array <string> $jobCodes
     * @return void
     */
    protected function setCronJobCodes($jobCodes)
    {
        $this->cronJobCodes = $jobCodes;
    }

    /**
     * @return array <string>
     */
    protected function getCronJobCodes()
    {
        return $this->cronJobCodes;
    }

    /**
     * @return void
     */
    protected function deleteCronSchedules()
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
