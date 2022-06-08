<?php

namespace Yotpo\Core\Model\Sync\Category\Processor;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Rest\Request;

/**
 * Class ProcessByProduct - Process category sync
 */
class ProcessByProduct extends Main
{

    /**
     * @param array<mixed> $productItems
     * @return void
     */
    public function process(array $productItems)
    {
        $this->yotpoCoreCatalogLogger->infoLog('Category Sync - Process categories by product - START ');

        foreach ($productItems as $productItem) {
            $productId = $productItem->getId();
            $this->yotpoCoreCatalogLogger->infoLog(
                __(
                    'Product Sync - Setting product collections sync for product ID %1',
                    $productId
                )
            );

            $productCategoriesIds = $productItem->getCategoryIds();
            $categoryIdsSyncedForProduct =
                $this->collectionsProductsService->getCategoryIdsFromSyncTableByProductId(
                    $productId
                );

            foreach ($productCategoriesIds as $categoryId) {
                if (in_array($categoryId, $categoryIdsSyncedForProduct)) {
                    continue;
                }

                $this->assignProductCategoryForCollectionsProductsSync($productItem, $categoryId);
            }

            $deletedCategoryIdsFromProduct = array_diff($categoryIdsSyncedForProduct, $productCategoriesIds);
            $this->assignProductCategoriesForCollectionsProductsSyncAsDeleted(
                $productItem,
                $deletedCategoryIdsFromProduct
            );
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
        $this->collectionsProductsService->assignCategoryProductsForCollectionsProductsSync(
            [$productId],
            $productStoreId,
            $categoryId
        );
    }

    /**
     * @param Product $product
     * @param array<int|string> $deletedCategoriesIds
     * @return void
     */
    public function assignProductCategoriesForCollectionsProductsSyncAsDeleted(
        $product,
        $deletedCategoriesIds
    ) {
        $productId = $product->getId();
        $productStoreId = $product->getStoreId();
        foreach ($deletedCategoriesIds as $deletedCategoryId) {
            $this->collectionsProductsService->assignCategoryProductsForCollectionsProductsSync(
                [$productId],
                $productStoreId,
                $deletedCategoryId,
                true
            );
        }
    }
}
