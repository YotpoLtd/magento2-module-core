<?php

namespace Yotpo\Core\Model\Sync\Reset;

class Orders extends Main
{
    const ORDERS_SYNC_TABLE = 'yotpo_orders_sync';
    const CRONJOB_CODES = ['yotpo_cron_core_orders_sync'];

    const ORDERS_TABLE = 'sales_order';
    const ORDERS_DATA_LIMIT = 300;

    /**
     * @return array <string>
     */
    protected function getTableResourceNames()
    {
        return [self::ORDERS_SYNC_TABLE];
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
    public function resetSync($storeId)
    {
        parent::resetSync($storeId);
        $this->clearSyncTracks($storeId);
    }

    /**
     * @param int $storeId
     * @return void
     */
    private function clearSyncTracks($storeId)
    {
        $connection  = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::ORDERS_TABLE);
        $select = $connection->select()
            ->from($table, 'entity_id')
            ->where('store_id', $storeId);

        $offset = 0;
        $entityIds = [];
        do {
            $entityIds = $connection->fetchCol($select->limit(self::ORDERS_DATA_LIMIT, $offset));
            $this->resetOrderSyncFlag($storeId, $entityIds);

            $offset += self::ORDERS_DATA_LIMIT;
        } while ($entityIds);
    }

    /**
     * @param int $storeId
     * @param array <int> $orderIds
     * @return void
     */
    public function resetOrderSyncFlag($storeId, $orderIds)
    {
        if (!$orderIds) {
            return;
        }
        $connection =   $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName('sales_order'),
            ['synced_to_yotpo_order' => '0'],
            [
                'store_id' => $storeId,
                'entity_id IN (?) ' => $orderIds
            ]
        );
    }
}
