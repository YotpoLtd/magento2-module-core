<?php
namespace Yotpo\Core\Observer\Product;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Catalog\Model\Session as CatalogSession;

/**
 * Class SaveBefore - Save childIds in session
 */
class SaveBefore implements ObserverInterface
{
    /**
     * @var CatalogSession
     */
    protected $catalogSession;

    /**
     * SaveBefore constructor.
     * @param CatalogSession $catalogSession
     */
    public function __construct(
        CatalogSession $catalogSession
    ) {
        $this->catalogSession = $catalogSession;
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
            $childrenIds = $product->getTypeInstance()->getChildrenIds($product->getId());
            $this->catalogSession->setChildrenIds($childrenIds);
        }
    }
}
