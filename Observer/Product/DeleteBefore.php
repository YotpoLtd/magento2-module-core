<?php
namespace Yotpo\Core\Observer\Product;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Magento\Catalog\Model\Session as CatalogSession;

/**
 * Class DeleteBefore - Update yotpo is_delete attribute
 */
class DeleteBefore extends Data implements ObserverInterface
{
    /**
     * @var AppEmulation
     */
    protected $appEmulation;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var CatalogSession
     */
    protected $catalogSession;

    /**
     * DeleteBefore constructor.
     * @param ResourceConnection $resourceConnection
     * @param AppEmulation $appEmulation
     * @param CatalogSession $catalogSession
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        AppEmulation $appEmulation,
        CatalogSession $catalogSession
    ) {
        $this->catalogSession = $catalogSession;
        parent::__construct($resourceConnection, $appEmulation);
    }

    /**
     * Execute observer - Update yotpo is_deleted with value one
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
            $this->catalogSession->setDeleteYotpoIds($childrenIds);
            $productCategoriesIds = $this->getProductCategoriesIdsFromCategoryProductsTable($productId);
            $this->catalogSession->setProductCategoriesIds($productCategoriesIds);
        }
    }
}
