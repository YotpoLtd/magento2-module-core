<?php

namespace Yotpo\Core\Model\Sync\Reset;

class Orders extends Main
{
    const ORDERS_SYNC_TABLE = 'yotpo_orders_sync';
    const CRONJOB_CODES = ['yotpo_cron_core_orders_sync'];

    const ORDERS_TABLE = 'sales_order';
    const ORDERS_DATA_LIMIT = 300;
    const YOTPO_ENTITY_NAME = 'order';
    const SYNCED_TO_YOTPO_ORDER_COLUMN = 'synced_to_yotpo_order';
    const FULFILLMENT_FLAG_COLUMN = 'is_fulfillment_based_on_shipment';

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
        parent::resetSync($storeId, false);
        $this->clearSyncTracks($storeId);
        $this->setResetInProgressConfig($storeId, '0');
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
        do {
            $entityIds = $connection->fetchCol($select->limit(self::ORDERS_DATA_LIMIT, $offset));
            $this->resetOrderSyncFlag($storeId, $entityIds);
            $this->cleanUpSyncTable($entityIds);
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
            [self::SYNCED_TO_YOTPO_ORDER_COLUMN => '0'],
            [
                'store_id' => $storeId,
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

    /**
     * Clear the per-order fulfillment-flag pin, store-scoped via sales_order (yotpo_orders_sync
     * itself has no store_id column). A merchant changing shipments_flag is a deliberate signal
     * to re-derive fulfillment for already-synced orders on their next sync, rather than staying
     * latched to whatever was recorded under the old setting - see
     * Model/Sync/Orders/Data.php::prepareFulfillments().
     *
     * @param int $storeId
     * @return void
     */
    public function clearFulfillmentFlagPins($storeId)
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::ORDERS_TABLE);
        $select = $connection->select()
            ->from($table, 'entity_id')
            ->where('store_id', $storeId);

        $offset = 0;
        do {
            $entityIds = $connection->fetchCol($select->limit(self::ORDERS_DATA_LIMIT, $offset));
            if ($entityIds) {
                $connection->update(
                    $this->resourceConnection->getTableName(self::ORDERS_SYNC_TABLE),
                    [self::FULFILLMENT_FLAG_COLUMN => null],
                    [$connection->quoteInto('order_id IN (?)', $entityIds)]
                );
            }
            $offset += self::ORDERS_DATA_LIMIT;
        } while ($entityIds);
    }
}
