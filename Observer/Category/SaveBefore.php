<?php
namespace Yotpo\Core\Observer\Category;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\ResourceConnection;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\Category\Processor\Main as CategoryProcessorMain;
use Yotpo\Core\Model\Sync\CollectionsProducts\Services\CollectionsProductsService;

/**
 * Class SaveBefore - Update yotpo attribute
 */
class SaveBefore implements ObserverInterface
{

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var CategoryProcessorMain
     */
    protected $categoryProcessorMain;

    /**
     * @var CollectionsProductsService
     */
    protected $collectionsProductsService;

    /**
     * SaveBefore constructor.
     * @param ResourceConnection $resourceConnection
     * @param RequestInterface $request
     * @param Config $config
     * @param CategoryProcessorMain $categoryProcessorMain
     * @param CollectionsProductsService $collectionsProductsService
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        RequestInterface $request,
        Config $config,
        CategoryProcessorMain $categoryProcessorMain,
        CollectionsProductsService $collectionsProductsService
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->request = $request;
        $this->config = $config;
        $this->categoryProcessorMain = $categoryProcessorMain;
        $this->collectionsProductsService = $collectionsProductsService;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $categoryId = $this->request->getParam('entity_id');
        if (!$categoryId) {
            return;
        }

        $currentProductIdsInCategory = $this->getCurrentProductIdsInCategory($categoryId);
        $productIdToPositionInCategoryMapBeforeSave = json_decode($this->request->getParam('vm_category_products'), true);
        $productIdsInCategoryBeforeSave = array_keys($productIdToPositionInCategoryMapBeforeSave);

        $productsAddedToCategory = array_diff($productIdsInCategoryBeforeSave, $currentProductIdsInCategory);
        $productsDeletedFromCategory = array_diff($currentProductIdsInCategory, $productIdsInCategoryBeforeSave);
        $storeIdsSuccessfullySyncedWithCategory = $this->categoryProcessorMain->getStoresSuccessfullySyncedWithCategory($categoryId);

        if ($storeIdsSuccessfullySyncedWithCategory) {
            foreach ($storeIdsSuccessfullySyncedWithCategory as $storeId) {
                if ($this->config->isCatalogSyncActive($storeId)) {
                    if ($productsAddedToCategory) {
                        $this->collectionsProductsService->assignCategoryProductsForCollectionsProductsSync($productsAddedToCategory, $storeId, $categoryId);
                    }

                    if ($productsDeletedFromCategory) {
                        $this->collectionsProductsService->assignCategoryProductsForCollectionsProductsSync($productsDeletedFromCategory, $storeId, $categoryId, true);
                    }
                }
            }
        }
    }

    private function getCurrentProductIdsInCategory($categoryId) {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from(
            $this->resourceConnection->getTableName('catalog_category_product'),
            ['product_id']
        )->where(
            'category_id = ?',
            $categoryId
        );
        $currentProductsInCategory = $connection->fetchAssoc($select, 'product_id');
        return array_keys($currentProductsInCategory);
    }
}
