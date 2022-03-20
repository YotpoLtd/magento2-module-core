<?php

namespace Yotpo\Core\Model\Sync\Category\Processor;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Rest\Request;
use Yotpo\Core\Model\Sync\CollectionsProducts\Services\CollectionsProductsService;

/**
 * Class ProcessByProduct - Process category sync
 */
class ProcessByProduct extends Main
{

    public function process(array $productItems)
    {
        $this->yotpoCoreCatalogLogger->info('Category Sync - Process categories by product - START ');

        foreach ($productItems as $productItem) {
            $productId = $productItem->getId();
            $this->yotpoCoreCatalogLogger->info(
                __(
                    'Product Sync - Setting product collections sync for product ID %1',
                    $productId
                )
            );

            $productCategoriesIds = $productItem->getCategoryIds();
            $categoryIdsSyncedForProduct = $this->collectionsProductsService->getCategoryIdsFromSyncTableByProductId($productId);

            foreach ($productCategoriesIds as $categoryId) {
                if (in_array($categoryId, $categoryIdsSyncedForProduct)) {
                    continue;
                }

                $this->assignProductCategoryForCollectionsProductsSync($productItem, $categoryId);
            }

            $deletedCategoryIdsFromProduct = array_diff($categoryIdsSyncedForProduct, $productCategoriesIds);
            $this->assignProductCategoriesForCollectionsProductsSyncAsDeleted($productItem, $deletedCategoryIdsFromProduct);
        }
    }

    /**
     * @param Product $product
     * @param int $categoryId
     * @return void
     */
    public function assignProductCategoryForCollectionsProductsSync(Product $product, $categoryId)
    {
        $productId = $product->getId();
        $productStoreId =$product->getStoreId();
        $this->collectionsProductsService->assignCategoryProductsForCollectionsProductsSync([$productId], $productStoreId, $categoryId);
    }

    /**
     * @param Product $product
     * @param array $deletedCategoriesIds
     * @return void
     */
    public function assignProductCategoriesForCollectionsProductsSyncAsDeleted(Product $product, array $deletedCategoriesIds)
    {
        $productId = $product->getId();
        $productStoreId = $product->getStoreId();
        foreach ($deletedCategoriesIds as $deletedCategoryId) {
            $this->collectionsProductsService->assignCategoryProductsForCollectionsProductsSync([$productId], $productStoreId, $deletedCategoryId, true);
        }
    }

    /**
     * @param string $storeId
     * @param string $productId
     * @return void
     */
    public function forceProductCollectionsResync($storeId, $productId) {
        $connection = $this->resourceConnection->getConnection();
        $updateCondition = [
            'magento_store_id = ?' => $storeId,
            'magento_product_id = ?' => $productId
        ];
        $currentDatetime = date('Y-m-d H:i:s');

        $connection->update(
            $this->resourceConnection->getTableName(CollectionsProductsService::YOTPO_COLLECTIONS_PRODUCTS_SYNC_TABLE_NAME),
            ['is_synced_to_yotpo' => 1, 'last_updated_at' => $currentDatetime],
            $updateCondition
        );
    }
}
