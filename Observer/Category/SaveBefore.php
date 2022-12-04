<?php
namespace Yotpo\Core\Observer\Category;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\ResourceConnection;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\Category\Processor\Main as CategoryProcessorMain;
use Yotpo\Core\Model\Sync\CollectionsProducts\Services\CollectionsProductsService;
use Yotpo\Core\Services\CatalogCategoryProductService;

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
     * @var CatalogCategoryProductService
     */
    protected $catalogCategoryProductService;

    /**
     * SaveBefore constructor.
     * @param ResourceConnection $resourceConnection
     * @param RequestInterface $request
     * @param Config $config
     * @param CategoryProcessorMain $categoryProcessorMain
     * @param CollectionsProductsService $collectionsProductsService
     * @param CatalogCategoryProductService $catalogCategoryProductService
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        RequestInterface $request,
        Config $config,
        CategoryProcessorMain $categoryProcessorMain,
        CollectionsProductsService $collectionsProductsService,
        CatalogCategoryProductService $catalogCategoryProductService
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->request = $request;
        $this->config = $config;
        $this->categoryProcessorMain = $categoryProcessorMain;
        $this->collectionsProductsService = $collectionsProductsService;
        $this->catalogCategoryProductService = $catalogCategoryProductService;
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

        $currentProductIdsInCategory =
            $this->catalogCategoryProductService->getProductIdsFromCategoryProductsTableByCategoryId(
                $categoryId
            );

        $productIdToPositionInCategoryStringMapBeforeSave = $this->request->getParam('vm_category_products');
        if ($productIdToPositionInCategoryStringMapBeforeSave === null) {
            return;
        }

        $productIdToPositionInCategoryMapBeforeSave =
            json_decode(
                $productIdToPositionInCategoryStringMapBeforeSave,
                true
            );
        $productIdsInCategoryBeforeSave = array_keys($productIdToPositionInCategoryMapBeforeSave);
        $productsAddedToCategory = array_diff($productIdsInCategoryBeforeSave, $currentProductIdsInCategory);
        $productsDeletedFromCategory = array_diff($currentProductIdsInCategory, $productIdsInCategoryBeforeSave);
        $storeIdsSuccessfullySyncedWithCategory =
            $this->categoryProcessorMain->getStoresSuccessfullySyncedWithCategory(
                $categoryId
            );

        if ($storeIdsSuccessfullySyncedWithCategory) {
            foreach ($storeIdsSuccessfullySyncedWithCategory as $storeId) {
                if ($this->config->isCatalogSyncActive($storeId)) {
                    if ($productsAddedToCategory) {
                        $this->collectionsProductsService->assignCategoryProductsForCollectionsProductsSync(
                            $productsAddedToCategory,
                            $storeId,
                            $categoryId
                        );
                    }

                    if ($productsDeletedFromCategory) {
                        $this->collectionsProductsService->assignCategoryProductsForCollectionsProductsSync(
                            $productsDeletedFromCategory,
                            $storeId,
                            $categoryId,
                            true
                        );
                    }
                }
            }
        }
    }
}
