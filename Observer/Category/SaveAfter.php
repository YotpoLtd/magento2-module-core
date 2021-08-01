<?php
namespace Yotpo\Core\Observer\Category;

use Magento\Catalog\Model\Category;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\Data\Main;

/**
 * Class SaveAfter - Update yotpo attribute
 */
class SaveAfter implements ObserverInterface
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Main
     */
    private $main;

    /**
     * SaveAfter constructor.
     * @param ResourceConnection $resourceConnection
     * @param Config $config
     * @param Main $main
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        Config $config,
        Main $main
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->config = $config;
        $this->main = $main;
    }

    /**
     * Set synced_to_yotpo_collection = 0 in catalog_category_entity_int table
     *
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        $connection = $this->resourceConnection->getConnection();

        /** @var Category $category */
        $category = $observer->getEvent()->getData('category');

        if (!($storeId = $this->config->getStoreId())) {
            $storeIds = $this->config->getAllStoreIds();
        } else {
            $storeIds = [$storeId];
        }

        $cond = [
            $this->config->getEavRowIdFieldName() . ' = ?' => $category->getData('row_id') ?: $category->getId(),
            'store_id IN (?)' => $storeIds,
            'attribute_id = ? ' => $this->main->getAttributeId(Config::CATEGORY_SYNC_ATTR_CODE)
        ];
        $connection->update(
            $connection->getTableName('catalog_category_entity_int'),
            ['value' => 0],
            $cond
        );
    }
}
