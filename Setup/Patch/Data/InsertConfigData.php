<?php

namespace Yotpo\Core\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Class InsertConfigData - Insert map_shipment_status field value to core_config_data table
 */

class InsertConfigData implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * InsertConfigData constructor.
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * @return void|InsertConfigData
     */
    public function apply()
    {
        $this->moduleDataSetup->startSetup();
        $setup = $this->moduleDataSetup;

        $data[] = [
            'scope' => 'default',
            'scope_id' => 0,
            'path' => 'yotpo_core/settings_order/map_shipment_status',
            'value' => '{
            "_1_1":{"yotpo_shipment_status":"label_printed","store_shipment_status":""},
            "_1_2":{"yotpo_shipment_status":"label_purchased","store_shipment_status":""},
            "_1_3":{"yotpo_shipment_status":"attempted_delivery","store_shipment_status":""},
            "_1_4":{"yotpo_shipment_status":"delivered","store_shipment_status":""},
            "_1_5":{"yotpo_shipment_status":"out_for_delivery","store_shipment_status":""},
            "_1_6":{"yotpo_shipment_status":"in_transit","store_shipment_status":""},
            "_1_7":{"yotpo_shipment_status":"failure","store_shipment_status":""},
            "_1_8":{"yotpo_shipment_status":"ready_for_pickup","store_shipment_status":""},
            "_1_9":{"yotpo_shipment_status":"confirmed","store_shipment_status":""}
            }'
        ];

        $this->moduleDataSetup->getConnection()->insertArray(
            $this->moduleDataSetup->getTable('core_config_data'),
            ['scope', 'scope_id', 'path', 'value'],
            $data
        );
        $this->moduleDataSetup->endSetup();
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
