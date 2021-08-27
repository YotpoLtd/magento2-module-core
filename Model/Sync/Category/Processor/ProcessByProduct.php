<?php

namespace Yotpo\Core\Model\Sync\Category\Processor;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Rest\Request;

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
     * @param array<mixed> $products
     * @return void
     * @throws NoSuchEntityException
     */
    public function process(array $products = [])
    {
        $this->yotpoCoreCatalogLogger->info('Category Sync - Process categories by product - START ', []);
        $url                    =   $this->config->getEndpoint('collections');
        $categories             =   $this->prepareCategories($products);
        $existingCollections    =   $this->getYotpoSyncedCategories(array_keys($categories));

        foreach ($existingCollections as $catId => $cat) {
            if (!$this->config->canResync($cat['response_code'], $cat['yotpo_id'])) {
                $this->yotpoCoreCatalogLogger->info(
                    'Category Sync - Process categories by product - Category can\'t be synced %1 , response_code - %1',
                    [$catId, $cat['response_code']]
                );
                unset($categories[$catId]);
            }
        }
        $categoriesByPath       =   $this->getCategoriesFromPathNames(array_values($categories));
        $existingProductsMap    =   [];
        $categoriesProduct      =   [];
        $newCollections         =   [];
        $currentTime            =   date('Y-m-d H:i:s');

        foreach ($products as $yotpoProductId => $product) {
            /** @var Product $product **/
            $categoriesProduct[$product->getId()]   =   [];
            $productCategories                      =   $product->getCategoryIds();
            $existingProductsMap[$product->getId()] =   $this->getYotpoCollectionsMap($yotpoProductId);
            $addProductData                         =   $this->data->prepareProductData($product->getId());

            foreach ($productCategories as $categoryId) {
                $categoriesProduct[$product->getId()][] =   $categoryId;
                if (!array_key_exists($categoryId, $existingCollections)) {
                    $categories[$categoryId]->setData(
                        'nameWithPath',
                        $this->getNameWithPath($categories[$categoryId], $categoriesByPath)
                    );
                    $collectionData                 =   $this->data->prepareData($categories[$categoryId]);
                    $collectionData['entityLog']    = 'catalog';

                    $createCollection   =   $this->yotpoCoreApiSync->sync(
                        Request::HTTP_METHOD_POST,
                        $url,
                        $collectionData
                    );
                    $createCollection               =   $createCollection->getData('response');
                    if ($createCollection) {
                        $existingCollections[$categoryId]['yotpo_id']   =   $createCollection['collection']['yotpo_id'];
                        $newCollections[$categoryId]        =   [
                            'yotpo_id' => $createCollection['collection']['yotpo_id'],
                            'synced_to_yotpo' => $currentTime
                        ];
                        $yotpoCollectionId  =   $existingCollections[$categoryId]['yotpo_id'];
                    } else {
                        $existingCollections[$categoryId]   =   '';
                        $yotpoCollectionId  =   '';
                    }
                } else {
                    $yotpoCollectionId  =   $existingCollections[$categoryId]['yotpo_id'];
                }
                if ($yotpoCollectionId && !array_key_exists($categoryId, $existingProductsMap[$product->getId()])) {
                    $addProductUrl  =   $this->config->getEndpoint(
                        'collections_product',
                        ['{yotpo_collection_id}'],
                        [$yotpoCollectionId]
                    );
                    $addProductData['entityLog']    =   'catalog';

                    $this->yotpoCoreApiSync->sync(Request::HTTP_METHOD_POST, $addProductUrl, $addProductData);
                    $existingProductsMap[$product->getId()][$categoryId]   =   $yotpoCollectionId;
                }
            }

            $currentMap     =   array_keys($existingProductsMap[$product->getId()]);
            $catUnmap       =   array_diff($currentMap, $categoriesProduct[$product->getId()]);
            $this->yotpoCoreCatalogLogger->info(
                'Category Sync by product -  unassign products - Category IDs -
                    ' . implode(',', array_unique($catUnmap)),
                []
            );
            $this->unAssignProducts($catUnmap, $product->getId(), $existingProductsMap[$product->getId()]);
        }
        $this->yotpoCoreCatalogLogger->info(
            'Category Sync by product -  Finish - Cateogyr ID - ' . implode(',', array_keys($newCollections)),
            []
        );
        $this->addNewCollectionsToYotpoTable($newCollections);
    }

    /**
     * @param string $yotpoProductId
     * @return  array<mixed>
     * @throws NoSuchEntityException
     */
    public function getYotpoCollectionsMap(string $yotpoProductId): array
    {
        $return = [];
        $data   = [];
        if ($yotpoProductId) {
            $url    =   $this->config->getEndpoint(
                'collections_for_product',
                ['{yotpo_product_id}'],
                [$yotpoProductId]
            );
            $data['entityLog']  =   'catalog';
            $collections        =   $this->yotpoCoreApiSync->sync(Request::HTTP_METHOD_GET, $url, $data);
            $collections        =   $collections->getData('response');
            if (!$collections) {
                return $return;
            }
            $collections    =   $collections['collections'];
            if ($collections) {
                $count = count($collections);
                for ($i=0; $i<$count; $i++) {
                    $return[$collections[$i]['external_id']] = $collections[$i]['yotpo_id'];
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
        $returnCategories   =   [];
        $categoryIds        =   [];
        foreach ($products as $product) {
            $categories     =   $product->getCategoryIds();
            $categoryIds[]    =  $categories;
        }

        $categoryIds = array_merge(...$categoryIds);
        $categoryIds    =   array_unique(array_filter($categoryIds));

        if ($categoryIds) {
            $collection =   $this->categoryCollectionFactory->create();
            $collection->addNameToResult();
            $collection->addIdFilter($categoryIds);

            foreach ($collection->getItems() as $category) {
                $returnCategories[$category->getId()]   =   $category;
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
        $finalData = [];
        foreach ($newCollections as $categoryId => $collection) {
            $finalData[] = [
                'category_id'        =>  $categoryId,
                'synced_to_yotpo'    =>  $collection['synced_to_yotpo'],
                'response_code'      =>  '201',
                'yotpo_id'           =>  $collection['yotpo_id'],
                'store_id'           =>  $this->config->getStoreId()
            ];
        }
        $this->insertOrUpdateYotpoTableData($finalData);
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
            $yotpoId    =   $existingProductsMap[$catId];
            $this->unAssignProductFromCollection($yotpoId, $productId);
        }
    }
}
