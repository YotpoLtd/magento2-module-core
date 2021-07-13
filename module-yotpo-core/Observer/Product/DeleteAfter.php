<?php
namespace Yotpo\Core\Observer\Product;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\Session as CatalogSession;

/**
 * Class DeleteAfter - Update yotpo is_delete attribute
 */
class DeleteAfter implements ObserverInterface
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
     * DeleteAfter constructor.
     * @param ResourceConnection $resourceConnection
     * @param CatalogSession $catalogSession
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        CatalogSession $catalogSession
    ) {
        $this->resourceConnection = $resourceConnection;
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
        $connection = $this->resourceConnection->getConnection();
        $product = $observer->getEvent()->getProduct();
        $childIds = $this->catalogSession->getDeleteYotpoIds();

        if (isset($childIds[0])) {
            $childIds = $childIds[0];
        }

        $connection->update(
            $connection->getTableName('yotpo_product_sync'),
            ['is_deleted' => 1, 'is_deleted_at_yotpo' => 0],
            $connection->quoteInto('product_id = ?', $product->getId())
        );

        if ($childIds) {
            $cond = $connection->quoteInto('product_id IN (?)', $childIds);
            $cond .= ' AND yotpo_id != 0';

            $query = 'UPDATE '.$connection->getTableName('yotpo_product_sync').'
                    SET yotpo_id_unassign = yotpo_id, yotpo_id = 0 WHERE '.$cond;

            $connection->query($query);
        }
    }
}
