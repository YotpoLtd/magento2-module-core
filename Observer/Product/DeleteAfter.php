<?php
namespace Yotpo\Core\Observer\Product;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\Session as CatalogSession;
use Yotpo\Core\Model\Config as YotpoCoreConfig;
use Yotpo\Core\Model\Sync\CollectionsProducts\Services\CollectionsProductsService;
use Yotpo\Core\Model\Sync\Category\Processor\Main as YotpoCategoryProcessorMain;

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
     * @var YotpoCoreConfig
     */
    protected $yotpoCoreConfig;

    /**
     * @var CollectionsProductsService
     */
    protected $collectionsProductsService;

    /**
     * @var YotpoCategoryProcessorMain
     */
    protected $yotpoCategoryProcessorMain;

    /**
     * DeleteAfter constructor.
     * @param ResourceConnection $resourceConnection
     * @param CatalogSession $catalogSession
     * @param YotpoCoreConfig $yotpoCoreConfig
     * @param CollectionsProductsService $collectionsProductsService
     * @param YotpoCategoryProcessorMain $yotpoCategoryProcessorMain
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        CatalogSession $catalogSession,
        YotpoCoreConfig $yotpoCoreConfig,
        CollectionsProductsService $collectionsProductsService,
        YotpoCategoryProcessorMain $yotpoCategoryProcessorMain
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->catalogSession = $catalogSession;
        $this->yotpoCoreConfig = $yotpoCoreConfig;
        $this->collectionsProductsService = $collectionsProductsService;
        $this->yotpoCategoryProcessorMain = $yotpoCategoryProcessorMain;
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
        $productId = $product->getId();

        $connection->update(
            $this->resourceConnection->getTableName('yotpo_product_sync'),
            ['is_deleted' => 1, 'is_deleted_at_yotpo' => 0, 'response_code' => YotpoCoreConfig::CUSTOM_RESPONSE_DATA],
            $connection->quoteInto('product_id = ?', $productId)
        );

        $this->unassignProductChildrenForSync();
        $this->unassignProductCategoriesForSync($productId);
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

    /**
     * @param int $productId
     * @return void
     */
    private function unassignProductCategoriesForSync($productId)
    {
        $productCategoriesIdsForDeletion = $this->catalogSession->getProductCategoriesIds();
        if ($productCategoriesIdsForDeletion === null) {
            return;
        }

        foreach ($productCategoriesIdsForDeletion as $categoryId) {
            $storeIdsSuccessfullySyncedWithCategory =
                $this->yotpoCategoryProcessorMain->getStoresSuccessfullySyncedWithCategory(
                    $categoryId
                );
            if ($storeIdsSuccessfullySyncedWithCategory) {
                foreach ($storeIdsSuccessfullySyncedWithCategory as $storeId) {
                    if ($this->yotpoCoreConfig->isCatalogSyncActive($storeId)) {
                        $this->collectionsProductsService->assignProductCategoriesForCollectionsProductsSync(
                            [$categoryId],
                            $storeId,
                            $productId,
                            true
                        );
                    }
                }
            }
        }
    }
}
