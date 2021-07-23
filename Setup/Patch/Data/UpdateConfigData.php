<?php

namespace Yotpo\Core\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Class UpdateConfigData
 * Merges old orderstatus data to core_config_data table.
 */
class UpdateConfigData implements DataPatchInterface
{
    const XML_PATH_CUSTOM_ORDER_STATUS  = 'yotpo/settings/custom_order_status';
    const XML_PATH_MAP_ORDER_STATUS     = 'yotpo_core/settings_order/map_order_status';
    const ORDER_STATUS_SUCCESS          = 'success';

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var array<mixed>
     */
    private $defaultOrderStatusValues =
        ['_1_1' => ['store_order_status' => 'canceled', 'yotpo_order_status' => 'cancelled'],
            '_1_2' => ['store_order_status' => 'closed', 'yotpo_order_status' => 'success'],
            '_1_3' => ['store_order_status' => 'complete', 'yotpo_order_status' => 'success'],
            '_1_4' => ['store_order_status' => 'fraud', 'yotpo_order_status' => 'failure'],
            '_1_5' => ['store_order_status' => 'holded', 'yotpo_order_status' => 'pending'],
            '_1_6' => ['store_order_status' => 'payment_review', 'yotpo_order_status' => 'pending'],
            '_1_7' => ['store_order_status' => 'paypal_canceled_reversal', 'yotpo_order_status' => 'cancelled'],
            '_1_8' => ['store_order_status' => 'paypal_reversed', 'yotpo_order_status' => 'cancelled'],
            '_1_9' => ['store_order_status' => 'pending', 'yotpo_order_status' => 'pending'],
            '_1_10' => ['store_order_status' => 'pending_payment', 'yotpo_order_status' => 'pending'],
            '_1_11' => ['store_order_status' => 'processing', 'yotpo_order_status' => 'pending'],
            '_1_12' => ['store_order_status' => 'pending_paypal', 'yotpo_order_status' => 'pending'],
        ];

    /**
     * InsertConfigData constructor.
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param SerializerInterface $serializer
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        SerializerInterface $serializer,
        ResourceConnection $resourceConnection
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->serializer = $serializer;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Inserts config data from the existing database
     *
     * @return void|UpdateConfigData
     */
    public function apply()
    {
        $this->moduleDataSetup->startSetup();

        $newData = [];
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from(
            ['coreConfigData' => $connection->getTableName('core_config_data')],
            ['*']
        )->where(
            $connection->quoteInto('coreConfigData.path = ?', self::XML_PATH_CUSTOM_ORDER_STATUS)
        );
        $items = $connection->fetchAssoc($select, []);
        if ($items) {
            foreach ($items as $item) {
                $newData[] = $this->prepareNewData($item['scope'], $item['scope_id'], $item['value']);
            }
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
     * @param string $existingData
     * @return array<mixed>
     */
    public function prepareNewData($scope, $scopeId, $existingData)
    {
        $serializedData = $this->prepareSerializedData($existingData);
        $data = [
            'scope' => $scope,
            'scope_id' => $scopeId,
            'path' => self::XML_PATH_MAP_ORDER_STATUS,
            'value' => $serializedData
        ];
        return $data;
    }

    /**
     * Prepare serialized data
     *
     * @param string $existingData
     * @return bool|string
     */
    public function prepareSerializedData($existingData)
    {
        $defaultOrderStatusValues = [];
        $existingItems = explode(',', $existingData);
        foreach ($this->defaultOrderStatusValues as $key => $value) {
            $item = $this->defaultOrderStatusValues[$key];
            $storeOrderStatus = $item['store_order_status'];
            if (in_array($storeOrderStatus, $existingItems)) {
                $item['yotpo_order_status'] = self::ORDER_STATUS_SUCCESS;
            }
            $defaultOrderStatusValues[$key] = $item;
        }
        return $this->serializer->serialize($defaultOrderStatusValues);
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
