<?php

namespace Yotpo\Core\Observer\Config;

use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\Event\Observer;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\Data\Main as CatalogDataMain;
use Magento\Framework\App\ResourceConnection;

/**
 * @class CatalogMapping - Reset catalog sync if mapping changed
 */
class CatalogMapping extends Main
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
     * @var CatalogDataMain
     */
    protected $catalogDataMain;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @param ConfigResource $configResource
     * @param Config $config
     * @param CatalogDataMain $catalogDataMain
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ConfigResource $configResource,
        Config $config,
        CatalogDataMain $catalogDataMain,
        ResourceConnection $resourceConnection
    ) {
        $this->configResource = $configResource;
        $this->config = $config;
        $this->catalogDataMain = $catalogDataMain;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function doYotpoCatalogMappingChanges(Observer $observer)
    {
        $catalogPathsChanged = $this->checkCatalogMappingChanged($observer);
        if (!$catalogPathsChanged) {
            return;
        }
        $this->resetCatalogSync();
    }

    /**
     * @param Observer $observer
     * @return array <mixed>
     */
    public function checkCatalogMappingChanged(Observer $observer): array
    {
        $changedPaths = (array)$observer->getEvent()->getChangedPaths();
        $catalogMapValues = [];
        $catalogMaps = [
            'attr_mpn',
            'attr_brand',
            'attr_ean',
            'attr_upc',
            'attr_isbn',
            'attr_blocklist',
            'attr_crf',
            'attr_product_group',
        ];
        foreach ($catalogMaps as $map) {
            $catalogMapValues[] = $this->config->getConfigPath($map);
        }
        return array_intersect($catalogMapValues, $changedPaths);
    }

    /**
     * @return void
     */
    public function resetCatalogSync()
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $connection->getTableName('catalog_product_entity_int');
        $select = $connection->select()
            ->from($tableName, 'value_id')
            ->where('attribute_id = ?', $this->catalogDataMain->getAttributeId(Config::CATALOG_SYNC_ATTR_CODE))
            ->where('value = ?', 1);
        $rows = $connection->fetchCol($select);
        if (!$rows) {
            return;
        }
        $updateLimit = $this->config->getUpdateSqlLimit();
        $rows = array_chunk($rows, $updateLimit);
        $count = count($rows);
        for ($i=0; $i<$count; $i++) {
            $cond   =   [
                'value_id IN (?) ' => $rows[$i]
            ];
            $connection->update(
                $tableName,
                ['value' => 0],
                $cond
            );
        }
    }
}
