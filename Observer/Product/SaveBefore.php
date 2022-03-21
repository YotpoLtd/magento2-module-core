<?php
namespace Yotpo\Core\Observer\Product;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Catalog\Model\Session as CatalogSession;
use Magento\Framework\App\ResourceConnection;
use Yotpo\Core\Services\CatalogCategoryProductService;

/**
 * Class SaveBefore - Save childIds in session
 */
class SaveBefore implements ObserverInterface
{
    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var CatalogSession
     */
    protected $catalogSession;

    /**
     * @var CatalogCategoryProductService
     */
    protected $catalogCategoryProductService;

    /**
     * SaveBefore constructor.
     * @param ResourceConnection $resourceConnection
     * @param CatalogSession $catalogSession
     * @param CatalogCategoryProductService $catalogCategoryProductService
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        CatalogSession $catalogSession,
        CatalogCategoryProductService $catalogCategoryProductService
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->catalogSession = $catalogSession;
        $this->catalogCategoryProductService = $catalogCategoryProductService;
    }

    /**
     * Execute observer - Update yotpo attribute, Manage is_deleted attr.
     *
     * @param EventObserver $observer
     * @return void
     */
    public function execute(EventObserver $observer)
    {
        $product = $observer->getEvent()->getProduct();

        if ($product->hasDataChanges()) {
            $productId = $product->getId();
            $childrenIds = $product->getTypeInstance()->getChildrenIds($productId);
            $this->catalogSession->setChildrenIds($childrenIds);
            $productCategoriesIds = $this->catalogCategoryProductService->getCategoryIdsFromCategoryProductsTableByProductId($productId);
            $this->catalogSession->setProductCategoriesIds($productCategoriesIds);
        }
    }
}
