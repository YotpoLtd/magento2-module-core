<?php

namespace Yotpo\Core\Plugin\Config\Backend\Serialized\ArraySerialized;

use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\App\ResourceConnection;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Config\Backend\Serialized\ArraySerialized\OrderStatus;

/**
 * OrderStatusPlugin - Reset order sync when there is a change in order status mapping
 */
class OrderStatusPlugin
{

    /**
     * @var ConfigResource
     */
    protected $configResource;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @param ConfigResource $configResource
     * @param Config $config
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ConfigResource $configResource,
        Config $config,
        ResourceConnection $resourceConnection
    ) {
        $this->configResource = $configResource;
        $this->config = $config;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @param OrderStatus $subject
     * @param OrderStatus $result
     * @return mixed
     */
    public function afterAfterSave(
        OrderStatus $subject,
        OrderStatus $result
    ) {
        if ($subject->isValueChanged()) {
            $this->resetOrderStatusSync();
        }
        return $result;
    }

    /**
     * @return void
     */
    public function resetOrderStatusSync()
    {
        $connection = $this->resourceConnection->getConnection('sales');
        $tableName = $connection->getTableName('sales_order');
        $select = $connection->select()
            ->from($tableName, 'entity_id')
            ->where('synced_to_yotpo_order = ?', 1);
        $rows = $connection->fetchCol($select);
        if (!$rows) {
            return;
        }
        $updateLimit = $this->config->getUpdateSqlLimit();
        $rows = array_chunk($rows, $updateLimit);
        $count = count($rows);
        for ($i=0; $i<1; $i++) {
            $cond   =   [
                'entity_id IN (?) ' => $rows[$i]
            ];
            $connection->update(
                $tableName,
                ['synced_to_yotpo_order' => 0],
                $cond
            );
        }
    }
}
