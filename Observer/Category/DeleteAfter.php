<?php
namespace Yotpo\Core\Observer\Category;

use Magento\Catalog\Model\Category;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\ResourceConnection;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\CollectionsProducts\Services\CollectionsProductsService;

/**
 * Class DeleteAfter - Update Yotpo Table
 */
class DeleteAfter implements ObserverInterface
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
     * @var CollectionsProductsService
     */
    private $collectionsProductsService;

    /**
     * DeleteAfter constructor.
     * @param ResourceConnection $resourceConnection
     * @param Config $config
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        Config $config,
        CollectionsProductsService $collectionsProductsService
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->config = $config;
        $this->collectionsProductsService = $collectionsProductsService;
    }

    /**
     * Set is_deleted = 1, is_deleted_at_yotpo = 0 in yotpo_category_sync table
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        /** @var Category $category */
        $category = $observer->getEvent()->getData('category');
        $categoryId = $category->getData('entity_id');
        $this->assignCategoryForDeletion($categoryId);
        $this->collectionsProductsService->updateCollectionProductsSyncDataAsDeletedInYotpo($categoryId);
    }

    /**
     * @param string $categoryId
     * @return void
     */
    private function assignCategoryForDeletion($categoryId) {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName('yotpo_category_sync'),
            ['is_deleted' => 1, 'is_deleted_at_yotpo' => 0, 'response_code' => Config::CUSTOM_RESPONSE_DATA],
            ['category_id = ?' => $categoryId]
        );
    }
}
