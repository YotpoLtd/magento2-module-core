<?php

namespace Yotpo\Core\Model\Sync\Category\Processor;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Rest\Request;
use Yotpo\Core\Model\Config as YotpoCoreConfig;

/**
 * Class ProcessByProduct - Process category sync
 */
class ProcessByProduct extends Main
{

    /**
     * @var null
     */
    protected $collections = null;

    /**
     * @var string
     */
    protected $entity = 'product_category';

    /**
     * @var array <mixed>
     */
    protected $prodCollExistYotpo = [];

    /**
     * @param array<mixed> $products
     * @return void
     * @throws NoSuchEntityException|LocalizedException
     */
    public function process(array $products = [])
    {
        $this->yotpoCoreCatalogLogger->info('Category Sync - Process categories by product - START ', []);
        $categories = $this->prepareCategories($products);
        $existingCollections = $this->getYotpoSyncedCategories(array_keys($categories));
        $newCollectionsToLog = [];

        foreach ($existingCollections as $catId => $cat) {
            if (!$this->config->canResync($cat['response_code'], $cat['yotpo_id'])) {
                $this->yotpoCoreCatalogLogger->info(
                    'Category Sync - Process categories by product - Category can\'t be synced %1 , response_code - %1',
                    [$catId, $cat['response_code']]
                );
                unset($categories[$catId]);
            }
        }
        $categoriesByPath = $this->getCategoriesFromPathNames(array_values($categories));
        $existingProductsMap = [];
        $categoriesProduct = [];

        foreach ($products as $yotpoProductId => $product) {
            /** @var Product $product * */
            $categoriesProduct[$product->getId()] = [];
            $productCategories = $product->getCategoryIds();
            $productCategories = array_intersect(array_keys($categories), $productCategories);
            $existingProductsMap[$product->getId()] = $this->getYotpoCollectionsMap($yotpoProductId);
            $addProductData = $this->data->prepareProductData($product->getId());

            foreach ($productCategories as $categoryId) {
                $categoriesProduct[$product->getId()][] = $categoryId;
                $categories[$categoryId]->setData(
                    'nameWithPath',
                    $this->getNameWithPath($categories[$categoryId], $categoriesByPath)
                );
                if (!array_key_exists($categoryId, $existingCollections)) {
                    $collectionData = $this->data->prepareData($categories[$categoryId]);
                    $collectionData['entityLog'] = 'catalog';
                    $yotpoCollectionId = $this->createOrUpdateCollection($collectionData, $categoryId, $categories);
                    if ($yotpoCollectionId) {
                        $existingCollections[$categoryId]['yotpo_id'] = $yotpoCollectionId;
                        $newCollectionsToLog[] = $categoryId;
                    } else {
                        $existingCollections[$categoryId] = '';
                        $yotpoCollectionId = '';
                    }
                } else {
                    $yotpoCollectionId = $existingCollections[$categoryId]['yotpo_id'];
                }

                if ($yotpoCollectionId
                    && $this->canAddProductToCollection(
                        $yotpoCollectionId,
                        $categoryId,
                        $existingProductsMap,
                        $product->getId()
                    )) {
                    $yotpoCollectionId = $this->addProductsToCollection(
                        $yotpoCollectionId,
                        $addProductData,
                        $product,
                        $categoryId,
                        $categories,
                        $existingProductsMap
                    );
                    $existingProductsMap[$product->getId()][$categoryId] = $yotpoCollectionId ?: 0;
                }
            }
            $existingProductsMap[$product->getId()] = array_filter($existingProductsMap[$product->getId()]);
            $currentMap = array_keys($existingProductsMap[$product->getId()]);
            $catUnmap = array_diff($currentMap, $categoriesProduct[$product->getId()]);
            $this->yotpoCoreCatalogLogger->info(
                'Category Sync by product -  unassign products - Category IDs -
                    ' . implode(',', array_unique($catUnmap)),
                []
            );
            $this->unAssignProducts($catUnmap, $product->getId(), $existingProductsMap[$product->getId()]);
        }
        $this->yotpoCoreCatalogLogger->info(
            'Category Sync by product -  Finished - Category ID - ' . implode(',', array_unique($newCollectionsToLog)),
            []
        );
    }

    /**
     * @param int $collectionId
     * @param int $categoryId
     * @param array<mixed> $existingProducts
     * @param int $productId
     * @return bool
     */
    protected function canAddProductToCollection($collectionId, $categoryId, $existingProducts, $productId)
    {
        if (!array_key_exists($categoryId, $existingProducts[$productId])) {
            return true;
        }
        if (isset($existingProducts[$productId][$categoryId]) &&
            $existingProducts[$productId][$categoryId] != $collectionId
        ) {
            return true;
        }
        return false;
    }

    /**
     * @param int|null|string $yotpoCollectionId
     * @param array<mixed> $addProductData
     * @param Product $product
     * @param int $categoryId
     * @param array<mixed> $categories
     * @param array<mixed> $existingProductsMap
     * @return int|null|string
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function addProductsToCollection(
        $yotpoCollectionId,
        $addProductData,
        Product $product,
        $categoryId,
        $categories,
        $existingProductsMap
    ) {
        $yotpoCollectionIdReturn = $yotpoCollectionId;
        $addProductUrl = $this->config->getEndpoint(
            'collections_product',
            ['{yotpo_collection_id}'],
            [$yotpoCollectionId]
        );
        $addProductData['entityLog'] = 'catalog';
        $response = $this->yotpoCoreApiSync->sync(Request::HTTP_METHOD_POST, $addProductUrl, $addProductData);
        $storeId = $product->getStoreId();
        if ($this->isImmediateRetry($response, $this->entity, $categoryId, $storeId)) {
            $this->setImmediateRetryAlreadyDone($this->entity, $categoryId, $storeId);
            $collectionData = $this->data->prepareData($categories[$categoryId]);
            $yotpoCollectionIdNew = $this->createOrUpdateCollection($collectionData, $categoryId, $categories);
            if ($yotpoCollectionIdNew) {
                if ($this->canAddProductToCollection(
                    $yotpoCollectionIdNew,
                    $categoryId,
                    $existingProductsMap,
                    $product->getId()
                )) {
                    $yotpoCollectionIdReturn = $this->addProductsToCollection(
                        $yotpoCollectionIdNew,
                        $addProductData,
                        $product,
                        $categoryId,
                        $categories,
                        $existingProductsMap
                    );
                }
            } else {
                $yotpoCollectionIdReturn = '';
            }
        }
        return $yotpoCollectionIdReturn;
    }

    /**
     * @param Category $category
     * @return int
     * @throws NoSuchEntityException
     */
    public function createCollection(Category $category)
    {
        $currentTime = date('Y-m-d H:i:s');
        $categoryId = $category->getId();
        $existingCollection = $this->getExistingCollectionIds([$categoryId]);
        $yotpoTableData = [];
        $yotpoTableData['store_id'] = $this->config->getStoreId();
        $yotpoTableData['category_id'] = $category->getId();
        $yotpoTableData['synced_to_yotpo'] = $currentTime;
        if (!$existingCollection) {
            $response = $this->syncAsNewCollection($category);
            $yotpoId = $this->getYotpoIdFromResponse($response);
            $yotpoTableRespData = $response ? $this->prepareYotpoTableData($response) : [];
            if ($yotpoTableRespData && is_array($yotpoTableRespData)) {
                $yotpoTableData = array_merge($yotpoTableData, $yotpoTableRespData);
            }
            $this->insertOrUpdateYotpoTableData($yotpoTableData);
        } else {
            $yotpoId = array_key_exists($categoryId, $existingCollection) ?
                $existingCollection[$categoryId] : 0;
            $yotpoTableData['yotpo_id'] = $yotpoId;
            $yotpoTableData['response_code'] = YotpoCoreConfig::SUCCESS_RESPONSE_CODE;
        }
        if ($yotpoId) {
            $this->insertOrUpdateYotpoTableData($yotpoTableData);
        }
        return $yotpoId;
    }

    /**
     * @param string $yotpoProductId
     * @return  array<mixed>
     * @throws NoSuchEntityException
     */
    public function getYotpoCollectionsMap($yotpoProductId): array
    {
        $return = [];
        $data = [];
        if ($yotpoProductId) {
            $url = $this->config->getEndpoint(
                'collections_for_product',
                ['{yotpo_product_id}'],
                [$yotpoProductId]
            );
            $data['entityLog'] = 'catalog';
            $collections = $this->yotpoCoreApiSync->sync(Request::HTTP_METHOD_GET, $url, $data);
            $collections = $collections->getData('response');
            if (!$collections) {
                return $return;
            }
            $collections = is_array($collections) &&
            isset($collections['collections']) ?
                $collections['collections'] : '';
            if ($collections) {
                $count = count($collections);
                for ($i = 0; $i < $count; $i++) {
                    $return[$collections[$i]['external_id']] = $collections[$i]['yotpo_id'];
                    $this->prodCollExistYotpo[$collections[$i]['external_id']] = [
                        'yotpo_id' => $collections[$i]['yotpo_id'],
                        'name' => $collections[$i]['name']
                    ];
                }
            }
        }
        return $return;
    }

    /**
     * @param array<mixed> $products
     * @return array<mixed>
     */
    protected function prepareCategories(array $products): array
    {
        $returnCategories = [];
        $categoryIds = [];
        foreach ($products as $product) {
            $categories = $product->getCategoryIds();
            $categoryIds[] = $categories;
        }

        $categoryIds = array_merge(...$categoryIds);
        $categoryIds = array_unique(array_filter($categoryIds));

        if ($categoryIds) {
            $collection = $this->getStoreCategoryCollection();
            $collection->addIdFilter($categoryIds);

            foreach ($collection->getItems() as $category) {
                $returnCategories[$category->getId()] = $category;
            }
        }
        $this->yotpoCoreCatalogLogger->info(
            'Category Sync - Process categories by product -
                    ' . implode(',', array_unique(array_keys($returnCategories))),
            []
        );
        return $returnCategories;
    }

    /**
     * @param array<mixed> $newCollections
     * @return void
     * @throws NoSuchEntityException
     */
    public function addNewCollectionsToYotpoTable(array $newCollections)
    {
        foreach ($newCollections as $categoryId => $collection) {
            $finalData = [
                'category_id' => $categoryId,
                'synced_to_yotpo' => $collection['synced_to_yotpo'],
                'response_code' => '201',
                'yotpo_id' => $collection['yotpo_id'],
                'store_id' => $this->config->getStoreId()
            ];
            $this->insertOrUpdateYotpoTableData($finalData);
        }
    }

    /**
     * @param array<mixed> $catUnmap
     * @param int $productId
     * @param array<mixed> $existingProductsMap
     * @return void
     */
    public function unAssignProducts(array $catUnmap, int $productId, array $existingProductsMap)
    {
        foreach ($catUnmap as $catId) {
            $yotpoId = $existingProductsMap[$catId];
            $this->unAssignProductFromCollection($yotpoId, $productId);
        }
    }

    /**
     * @param array<mixed> $collectionData
     * @param int $categoryId
     * @param array<mixed> $categories
     * @return mixed|string
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function createOrUpdateCollection($collectionData, $categoryId, $categories)
    {
        $newCollections = null;
        $categoryIdToUpdate = null;
        $currentTime = date('Y-m-d H:i:s');
        $url = $this->config->getEndpoint('collections');
        $yotpoIdToReturn = $this->updateIfNameIsDifferent($this->prodCollExistYotpo, $categories, $categoryId);
        if ($yotpoIdToReturn) {
            $newCollections = [];
            $newCollections[$categoryId] = [
                'yotpo_id' => $yotpoIdToReturn,
                'synced_to_yotpo' => $currentTime
            ];
            $categoryIdToUpdate = $categories[$categoryId]->getRowId()
                ?: $categories[$categoryId]->getId();
        } else {
            $newCollectionResponse = $this->yotpoCoreApiSync->sync(
                Request::HTTP_METHOD_POST,
                $url,
                $collectionData
            );
            if (!$newCollectionResponse) {
                return $yotpoIdToReturn;
            }
            if ($this->checkForCollectionExistsError($newCollectionResponse)) {
                $existingCollections = $this->getExistingCollection([$categoryId]);
                $yotpoIdToReturn = $this->updateIfNameIsDifferent($existingCollections, $categories, $categoryId);
                if ($yotpoIdToReturn) {
                    $newCollections = [];
                    $newCollections[$categoryId] = [
                        'yotpo_id' => $yotpoIdToReturn,
                        'synced_to_yotpo' => $currentTime
                    ];
                    $categoryIdToUpdate = $categories[$categoryId]->getRowId()
                        ?: $categories[$categoryId]->getId();
                }
            } else {
                $yotpoIdToReturn = $this->getYotpoIdFromResponse($newCollectionResponse);
                $newCollections = [];
                $newCollections[$categoryId] = [
                    'yotpo_id' => $yotpoIdToReturn,
                    'synced_to_yotpo' => $currentTime
                ];
                $categoryIdToUpdate = $categories[$categoryId]->getRowId()
                    ?: $categories[$categoryId]->getId();
            }
        }
        if ($categoryIdToUpdate) {
            $this->updateCategoryAttribute($categoryIdToUpdate);
        }
        if ($newCollections) {
            $this->addNewCollectionsToYotpoTable($newCollections);
        }
        return $yotpoIdToReturn;
    }

    /**
     * @param array <mixed> $existingProdCollYotpo
     * @param array<mixed> $categories
     * @param int $categoryId
     * @return int|string|null
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function updateIfNameIsDifferent($existingProdCollYotpo, $categories, $categoryId)
    {
        $yotpoIdToReturn = null;
        if (array_key_exists($categoryId, $existingProdCollYotpo)) {
            $nameWithPath = $categories[$categoryId]->getData('nameWithPath');
            if ($existingProdCollYotpo[$categoryId]['name'] != $nameWithPath) {
                //update the collection
                $response = $this->syncExistingCollection(
                    $categories[$categoryId],
                    $existingProdCollYotpo[$categoryId]['yotpo_id']
                );
                $yotpoIdToReturn = $this->getYotpoIdFromResponse($response);
            } else {
                $yotpoIdToReturn = $existingProdCollYotpo[$categoryId]['yotpo_id'];
            }
        }
        return $yotpoIdToReturn;
    }
}
