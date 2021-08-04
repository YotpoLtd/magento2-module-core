<?php

namespace Yotpo\Core\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;

/**
 * Class MapCatalogAttributes
 *
 * Maps catalog attributes in the backend configuration
 */
class MapCatalogAttributes implements DataPatchInterface
{
    const XML_PATH_CATALOG_SETTINGS  = 'yotpo_core/sync_settings/catalog_sync/settings_catalog';

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var array<mixed>
     */
    private $attributeList = [
        'mpn', 'brand', 'ean', 'upc', 'isbn', 'blocklist', 'crf', 'product_group'
    ];

    /**
     * MapCatalogAttributes constructor.
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        CollectionFactory $collectionFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * Maps catalog attributes data in backend admin
     *
     * @return void|MapCatalogAttributes
     */
    public function apply()
    {
        $this->moduleDataSetup->startSetup();

        $newData = [];
        $attributeInfo = $this->collectionFactory->create();
        foreach ($attributeInfo as $item) {
            $attributeCode = $item->getData('attribute_code');
            if (in_array($item->getData('attribute_code'), $this->attributeList)) {
                $configPath = self::XML_PATH_CATALOG_SETTINGS.'/attr_'.$attributeCode;
                $newData[] = $this->prepareNewData('default', 0, $configPath, $attributeCode);
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
     * @param string $configPath
     * @param string $configValue
     * @return array<mixed>
     */
    public function prepareNewData($scope, $scopeId, $configPath, $configValue)
    {
        return [
            'scope' => $scope,
            'scope_id' => $scopeId,
            'path' => $configPath,
            'value' => $configValue
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
