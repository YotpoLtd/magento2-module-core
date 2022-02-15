<?php

namespace Yotpo\Core\Model\Sync\Reset;

class Orders extends Main
{
    const ORDERS_SYNC_TABLE = 'yotpo_orders_sync';
    const ORDERS_TABLE = 'sales_order';
    const ORDERS_DATA_LIMIT = 300;
    const CRONJOB_CODES = ['yotpo_cron_core_orders_sync'];

    /**
     * @param int $storeId
     * @return void
     */
    public function resetSync($storeId)
    {
        $this->setStoreId($storeId);
        $this->setCronJobCodes(self::CRONJOB_CODES);
        parent::resetSync($storeId);
        $this->processResetSync();
    }

    /**
     * @param int $offset
     * @return void
     */
    public function processResetSync($offset = 0)
    {
        $connection  = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::ORDERS_TABLE);
        $select = $connection->select()
            ->from($table, 'entity_id')
            ->where('store_id', $this->getStoreId())
            ->limit(self::ORDERS_DATA_LIMIT, $offset);
        $rows = $connection->fetchCol($select);
        if ($rows) {
            $this->resetOrderSyncFlag($rows);
            $this->cleanUpSyncTable($rows);
            $offset += self::ORDERS_DATA_LIMIT;
            $this->processResetSync($offset);
        }
    }

    /**
     * @param array <int> $orderIds
     * @return void
     */
    public function resetOrderSyncFlag($orderIds = [])
    {
        $connection =   $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName('sales_order'),
            ['synced_to_yotpo_order' => '0'],
            [
                'store_id' => $this->getStoreId(),
                'entity_id IN (?) ' => $orderIds
            ]
        );
    }

    /**
     * @param array <int> $orderIds
     * @return void
     */
    public function cleanUpSyncTable($orderIds)
    {
        $connection  = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::ORDERS_SYNC_TABLE);
        $whereConditions = [
            $connection->quoteInto('order_id IN (?)', $orderIds)
        ];
        $connection->delete($tableName, $whereConditions);
    }
}
