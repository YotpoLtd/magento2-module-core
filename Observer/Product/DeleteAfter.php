<?php
namespace Yotpo\Core\Observer\Product;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\Session as CatalogSession;
use Yotpo\Core\Model\Config as YotpoCoreConfig;

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

        $connection->update(
            $this->resourceConnection->getTableName('yotpo_product_sync'),
            ['is_deleted' => 1, 'is_deleted_at_yotpo' => 0, 'response_code' => YotpoCoreConfig::CUSTOM_RESPONSE_DATA],
            $connection->quoteInto('product_id = ?', $product->getId())
        );

        $this->unassignProductChildrenForSync();
    }

    /**
     * @return void
     */
    private function unassignProductChildrenForSync()
    {
        $connection = $this->resourceConnection->getConnection();
        $productChildrenIdsForDeletion = $this->catalogSession->getDeleteYotpoIds();

        if (isset($productChildrenIdsForDeletion[0])) {
            $productChildrenIdsForDeletion = $productChildrenIdsForDeletion[0];
        }

        if ($productChildrenIdsForDeletion) {
            $condition = [
                'product_id IN (?) ' => $productChildrenIdsForDeletion,
                'yotpo_id != 0'
            ];
            $dataToUpdate = [
                'yotpo_id_unassign' => new \Zend_Db_Expr('yotpo_id'),
                'yotpo_id' => '0',
                'response_code' => YotpoCoreConfig::CUSTOM_RESPONSE_DATA
            ];

            $connection->update(
                $this->resourceConnection->getTableName('yotpo_product_sync'),
                $dataToUpdate,
                $condition
            );
        }
    }
}
