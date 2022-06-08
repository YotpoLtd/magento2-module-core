<?php

namespace Yotpo\Core\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Class UpdateOrdersSyncDate
 * Merges old 'orders_sync_start_date' data to core_config_data table.
 */
class UpdateOrdersSyncDate implements DataPatchInterface
{
    const XML_PATH_ORDERS_SYNC_START_DATE  = 'yotpo/sync_settings/orders_sync_start_date';
    const XML_PATH_SYNC_ORDERS_SINCE       = 'yotpo_core/sync_settings/orders_sync/sync_orders_since';

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * InsertConfigData constructor.
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        ResourceConnection $resourceConnection
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Inserts config data from the existing database
     *
     * @return void|UpdateOrdersSyncDate
     */
    public function apply()
    {
        $this->moduleDataSetup->startSetup();

        $newData = [];
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from(
            ['coreConfigData' => $this->resourceConnection->getTableName('core_config_data')],
            ['*']
        )->where(
            $connection->quoteInto('coreConfigData.path = ?', self::XML_PATH_ORDERS_SYNC_START_DATE)
        );
        $items = $connection->fetchAssoc($select);
        if ($items) {
            foreach ($items as $item) {
                $newData[] = $this->prepareNewData($item['scope'], $item['scope_id'], $item['value']);
            }
        } else {
            $newData[] = $this->prepareNewData('default', 0, null);
        }
        if ($newData) {
            $this->moduleDataSetup->getConnection()->insertOnDuplicate(
                $this->moduleDataSetup->getTable('core_config_data'),
                $newData
            );
        }

        $this->moduleDataSetup->endSetup();
    }

    /**
     * Prepare data to insert to core_config_data
     *
     * @param string $scope
     * @param int $scopeId
     * @param string|null $existingValue
     * @return array<mixed>
     */
    public function prepareNewData($scope, $scopeId, $existingValue)
    {
        return [
            'scope' => $scope,
            'scope_id' => $scopeId,
            'path' => self::XML_PATH_SYNC_ORDERS_SINCE,
            'value' => $existingValue
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases()
    {
        return [];
    }
}
