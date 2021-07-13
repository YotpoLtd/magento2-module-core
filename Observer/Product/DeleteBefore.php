<?php
namespace Yotpo\Core\Observer\Product;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Catalog\Model\Session as CatalogSession;

/**
 * Class DeleteBefore - Update yotpo is_delete attribute
 */
class DeleteBefore implements ObserverInterface
{
    /**
     * @var CatalogSession
     */
    protected $catalogSession;

    /**
     * DeleteBefore constructor.
     * @param CatalogSession $catalogSession
     */
    public function __construct(
        CatalogSession $catalogSession
    ) {
        $this->catalogSession = $catalogSession;
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
            $childrenIds = $product->getTypeInstance()->getChildrenIds($product->getId());
            $this->catalogSession->setDeleteYotpoIds($childrenIds);
        }
    }
}
