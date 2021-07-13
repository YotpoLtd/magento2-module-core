<?php

namespace Yotpo\Core\Model\Sync\Data;

use Magento\Framework\App\ResourceConnection;

/**
 * Class Main - Base class to retrieve attribute data
 */
class Main
{
    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var array<mixed>
     */
    protected $attributeIds = [];

    /**
     * Main constructor.
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ResourceConnection $resourceConnection
    ) {
        $this->resourceConnection   =   $resourceConnection;
    }

    /**
     * return attributeID
     * @param string $code
     * @return mixed|string
     */
    public function getAttributeId(string $code)
    {
        if (!array_key_exists($code, $this->attributeIds)) {
            $connection = $this->resourceConnection->getConnection();
            $query = $connection->select()->from(
                ['e' => $connection->getTableName('eav_attribute')],
                'e.attribute_id'
            )->where(
                $connection->quoteIdentifier('e.attribute_code') . ' = ?',
                $code
            );
            $this->attributeIds[$code] = $connection->fetchOne($query);
        }
        return $this->attributeIds[$code];
    }

    /**
     * Gets the count of synced orders from custom table
     * @return string
     */
    public function getTotalSyncedOrders()
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->
                    select()->from(['e' => $connection->getTableName('yotpo_orders_sync')], 'COUNT(*)');
        return $connection->fetchOne($select);
    }
}
