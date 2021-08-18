<?php

namespace Yotpo\Core\Observer\Config;

use Magento\Cron\Model\Schedule;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory as CronCollection;
use Yotpo\Core\Model\Config as YotpoCoreConfig;

/**
 * CronFrequency - Manage cron schedule table for sync jobs
 */
class CronFrequency
{

    /**
     * @var CronCollection
     */
    protected $cronCollection;

    /**
     * @var YotpoCoreConfig
     */
    protected $yotpoCoreConfig;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var array <mixed>
     */
    protected $cronFrequency = [
        'catalog_sync_frequency' => [
            'config_path' => '',
            'job_code' => 'yotpo_cron_core_products_sync,yotpo_cron_core_category_sync'
        ],
        'orders_sync_frequency' => [
            'config_path' => '',
            'job_code' => 'yotpo_cron_core_orders_sync'
        ]
    ];

    /**
     * @param CronCollection $cronCollection
     * @param YotpoCoreConfig $yotpoCoreConfig
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        CronCollection $cronCollection,
        YotpoCoreConfig $yotpoCoreConfig,
        ResourceConnection $resourceConnection
    ) {
        $this->cronCollection = $cronCollection;
        $this->yotpoCoreConfig = $yotpoCoreConfig;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function doCronFrequencyChanges(Observer $observer)
    {

        $cronFrequenciesChanged = $this->checkCronFrequencyChanged($observer);
        if (!$cronFrequenciesChanged) {
            return;
        }
        $cronJobCodes = [];
        foreach ($cronFrequenciesChanged as $frequencyPath) {
            foreach ($this->cronFrequency as $itemKey => $item) {
                if ($item['config_path'] == $frequencyPath) {
                    $cronJobCodes[] = explode(',', $item['job_code']);
                    break;
                }
            }
        }
        $cronJobCodes = array_merge(...$cronJobCodes);
        $cronJobCodes = array_filter(array_unique($cronJobCodes));
        $this->resetCronScheduler($cronJobCodes);
    }

    /**
     * @param Observer $observer
     * @return array <mixed>
     */
    public function checkCronFrequencyChanged(Observer $observer)
    {
        $changedPaths = (array)$observer->getEvent()->getChangedPaths();
        $cronFrequencyValues = [];
        foreach ($this->cronFrequency as $key => $item) {
            $this->cronFrequency[$key]['config_path'] = $this->yotpoCoreConfig->getConfigPath($key);
            $cronFrequencyValues[] = $this->cronFrequency[$key]['config_path'];
        }
        return array_intersect($cronFrequencyValues, $changedPaths);
    }

    /**
     * @param array <mixed> $cronJobCodes
     * @return void
     */
    private function resetCronScheduler($cronJobCodes = [])
    {
        $connection  = $this->resourceConnection->getConnection();
        $tableName = $connection->getTableName('cron_schedule');
        $whereConditions = [
            $connection->quoteInto('job_code IN (?)', $cronJobCodes),
            $connection->quoteInto('status=?', Schedule::STATUS_PENDING),
        ];
        $connection->delete($tableName, $whereConditions);
    }
}
