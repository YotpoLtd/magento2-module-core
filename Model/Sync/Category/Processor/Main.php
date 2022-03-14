<?php

namespace Yotpo\Core\Model\Sync\Category\Processor;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Rest\Request;
use Yotpo\Core\Model\AbstractJobs;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Magento\Framework\App\ResourceConnection;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\Category\Data;
use Yotpo\Core\Model\Api\Sync as YotpoCoreApiSync;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Yotpo\Core\Model\Sync\Catalog\Logger as YotpoCoreCatalogLogger;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class Main - Manage Category sync
 */
class Main extends AbstractJobs
{
    const YOTPO_CATEGORY_SYNC_TABLE_NAME = 'yotpo_category_sync';

    /**
     * @var CategoryCollectionFactory
     */
    protected $categoryCollectionFactory;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Data
     */
    protected $data;

    /**
     * @var YotpoCoreApiSync
     */
    protected $yotpoCoreApiSync;

    /**
     * @var YotpoCoreCatalogLogger
     */
    protected $yotpoCoreCatalogLogger;

    /**
     * @var string
     */
    protected $entity = 'category';

    /**
     * @var string|null
     */
    protected $entityIdFieldValue;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * Main constructor.
     * @param AppEmulation $appEmulation
     * @param ResourceConnection $resourceConnection
     * @param Config $config
     * @param Data $data
     * @param YotpoCoreApiSync $yotpoCoreApiSync
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param YotpoCoreCatalogLogger $yotpoCoreCatalogLogger
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        AppEmulation $appEmulation,
        ResourceConnection $resourceConnection,
        Config $config,
        Data $data,
        YotpoCoreApiSync $yotpoCoreApiSync,
        CategoryCollectionFactory $categoryCollectionFactory,
        YotpoCoreCatalogLogger $yotpoCoreCatalogLogger,
        StoreManagerInterface $storeManager
    ) {
        $this->config   =   $config;
        $this->data   =   $data;
        $this->yotpoCoreApiSync             =   $yotpoCoreApiSync;
        $this->categoryCollectionFactory    =   $categoryCollectionFactory;
        $this->yotpoCoreCatalogLogger       =   $yotpoCoreCatalogLogger;
        $this->entityIdFieldValue           =   $this->config->getEavRowIdFieldName();
        $this->storeManager = $storeManager;
        parent::__construct($appEmulation, $resourceConnection);
    }

    /**
     * @param array<mixed> $magentoCategories
     * @return array<mixed>
     * @throws NoSuchEntityException
     */
    public function getYotpoSyncedCategories(array $magentoCategories): array
    {
        if (!$magentoCategories) {
            return [];
        }
        $return     =   [];
        $connection =   $this->resourceConnection->getConnection();
        $storeId    =   $this->config->getStoreId();
        $table      =   $this->resourceConnection->getTableName('yotpo_category_sync');
        $categories =   $connection->select()
            ->from($table)
            ->where('category_id IN(?) ', $magentoCategories)
            ->where('store_id=(?)', $storeId)
            ->where('yotpo_id > 0');

        $categories =   $connection->fetchAssoc($categories, []);
        foreach ($categories as $cat) {
            $return[$cat['category_id']]  =   $cat;
        }
        return $return;
    }

    /**
     * @param array<mixed> $categoryIds
     * @return array<mixed>
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function getExistingCollectionIds(array $categoryIds): array
    {
        if (!$categoryIds) {
            return [];
        }
        $yotpoCollections = [];
        $categoryIds    =   array_chunk($categoryIds, 100);
        foreach ($categoryIds as $chunk) {
            $url                =   $this->config->getEndpoint('collections');
            $data               =   ['external_ids' => implode(',', $chunk)];
            $data['entityLog']  =   'catalog';
            $response           =   $this->yotpoCoreApiSync->sync(Request::HTTP_METHOD_GET, $url, $data);
            $response           =   $response->getData('response');
            if (!$response) {
                continue;
            }
            $collections    =   is_array($response) && isset($response['collections']) ? $response['collections'] : [];
            $count = count($collections);
            for ($i=0; $i<$count; $i++) {
                if (is_array($collections[$i])
                    && isset($collections[$i]['external_id'])
                    && isset($collections[$i]['yotpo_id'])
                ) {
                    $yotpoCollections[$collections[$i]['external_id']]  =   $collections[$i]['yotpo_id'];
                }
            }
        }
        return $yotpoCollections;
    }

    /**
     * @param array<mixed> $categoryIds
     * @return array<mixed>
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function getExistingCollection(array $categoryIds): array
    {
        if (!$categoryIds) {
            return [];
        }
        $yotpoCollections = [];
        $categoryIds    =   array_chunk($categoryIds, 100);
        foreach ($categoryIds as $chunk) {
            $url                =   $this->config->getEndpoint('collections');
            $data               =   ['external_ids' => implode(',', $chunk)];
            $data['entityLog']  =   'catalog';
            $response           =   $this->yotpoCoreApiSync->sync(Request::HTTP_METHOD_GET, $url, $data);
            $response           =   $response->getData('response');
            if (!$response) {
                continue;
            }
            $collections    =   is_array($response) && isset($response['collections']) ? $response['collections'] : [];
            $count = count($collections);
            for ($i=0; $i<$count; $i++) {
                if (is_array($collections[$i])
                    && isset($collections[$i]['external_id'])
                    && isset($collections[$i]['yotpo_id'])
                ) {
                    $yotpoCollections[$collections[$i]['external_id']]  =   [
                        'yotpo_id' => $collections[$i]['yotpo_id'],
                        'name' => $collections[$i]['name']
                    ];
                }
            }
        }
        return $yotpoCollections;
    }

    /**
     * @param Category $category
     * @param int $yotpoId
     * @return mixed|void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function syncExistingCollection(Category $category, int $yotpoId)
    {
        if (!$yotpoId) {
            return ;
        }
        $collectionData                 =   $this->data->prepareData($category);
        $collectionData['entityLog']    = 'catalog';
        $url    =   $this->config->getEndpoint('collections_update', ['{yotpo_collection_id}'], [$yotpoId]);
        $response =  $this->yotpoCoreApiSync->sync(\Zend_Http_Client::PATCH, $url, $collectionData);
        $categoryId = $category->getId();
        $storeId = $category->getStoreId();
        if ($this->isImmediateRetry($response, $this->entity, $categoryId, $storeId)) {
            $this->setImmediateRetryAlreadyDone($this->entity, $categoryId, $storeId);
            $existingCollection = $this->getExistingCollectionIds([$categoryId]);
            if (!$existingCollection) {
                $response = $this->syncAsNewCollection($category);
            } else {
                $yotpoId = array_key_exists($categoryId, $existingCollection) ?
                    $existingCollection[$categoryId]  : 0;
                if ($yotpoId) {
                    $response = $this->syncExistingCollection($category, $yotpoId);
                }
            }
        }
        if ($yotpoId) {
            $response->setData('yotpo_id', $yotpoId);
        }
        return $response;
    }

    /**
     * @param DataObject|null $response
     * @return array<mixed>
     */
    public function prepareYotpoTableData(?DataObject $response): array
    {
        if (!$response) {
            return [];
        }
        $data = [
            'response_code' =>  $response->getData('status'),
        ];
        $responseData   =   $response->getData('response');
        $data['yotpo_id']   =   null;
        if ($response->getData('yotpo_id')) {
            $data['yotpo_id']   =   $response->getData('yotpo_id');
        } elseif ($responseData && is_array($responseData) &&
            array_key_exists('collection', $responseData) && $responseData['collection']
        ) {
            $data['yotpo_id']   =   $responseData['collection']['yotpo_id'];
        }
        return $data;
    }

    /**
     * @param array<mixed> $data
     * @return void
     */
    public function insertOrUpdateYotpoTableData(array $data)
    {
        $finalData = [];
        $finalData[] = [
            'category_id'        =>  $data['category_id'],
            'synced_to_yotpo'    =>  $data['synced_to_yotpo'],
            'response_code'      =>  $data['response_code'],
            'yotpo_id'           =>  $data['yotpo_id'],
            'store_id'           =>  $data['store_id'],
        ];
        $this->insertOnDuplicate('yotpo_category_sync', $finalData);
    }

    /**
     * @param array <mixed> $category
     * @param array <mixed> $yotpoId
     * @param bool $isCommandLineSync
     * @return bool
     */
    public function canResync(array $category = [], $yotpoId = [], $isCommandLineSync = false): bool
    {
        return $this->config->canResync($category['response_code'], $yotpoId, $isCommandLineSync);
    }

    /**
     * @param int $yotpoCollectionId
     * @param int $productId
     */
    public function unAssignProductFromCollection(int $yotpoCollectionId, int $productId): bool
    {

        $url    =   $this->config->getEndpoint('collections_product', ['{yotpo_collection_id}'], [$yotpoCollectionId]);
        $data               =   $this->data->prepareProductData($productId);
        $data['entityLog']  =   'catalog';
        $response           =   $this->yotpoCoreApiSync->sync(Request::HTTP_METHOD_DELETE, $url, $data);
        $responseCode = $response && $response->getData('status') ? $response->getData('status') : null;
        return ($response && $response->getData('is_success')) || $responseCode == '404';
    }

    /**
     * @param array<mixed> $categories
     * @return array<mixed>
     */
    public function getCategoriesFromPathNames($categories): array
    {
        if (!$categories) {
            return [];
        }
        $magentoCategories  =   [];
        $categoryPathIds    =   [];
        $categoriesByPath   =   [];
        foreach ($categories as $category) {
            $path   =   explode('/', $category->getPath());
            array_shift($path);
            $categoryPathIds[] = $path;
            $magentoCategories[$category->getId()]  =   $category;
        }
        $categoryPathIds = array_merge(...$categoryPathIds);
        $categoryPathIds    =   array_filter(array_unique($categoryPathIds));
        $existingInMagentoCategories    =   array_intersect($categoryPathIds, array_keys($magentoCategories));
        foreach ($existingInMagentoCategories as $exMageCatId) {
            $categoriesByPath[$exMageCatId] =   $magentoCategories[$exMageCatId];
        }
        $nonExistingInMagentoCategories    =   array_diff($categoryPathIds, array_keys($magentoCategories));
        $catCollectionOth   =   $this->categoryCollectionFactory->create();
        $catCollectionOth->addNameToResult();
        $catCollectionOth->addFieldToFilter(
            'entity_id',
            ['in' => $nonExistingInMagentoCategories]
        );

        foreach ($catCollectionOth->getItems() as $collectionOthCatItem) {
            $categoriesByPath[$collectionOthCatItem->getId()] =   $collectionOthCatItem;
        }

        return $categoriesByPath;
    }

    /**
     * @param Category $singleCategory
     * @param array<mixed> $categories
     * @return string|void
     */
    public function getNameWithPath(Category $singleCategory, array $categories)
    {
        $singleCatPath   =   explode('/', (string) $singleCategory->getPath());
        array_shift($singleCatPath);
        if (!$singleCatPath) {
            return;
        }
        $singleCatNames = [];

        foreach ($singleCatPath as $singleCatId) {
            $singleCatNames[]   =   $categories[$singleCatId]->getName();
        }

        return implode('/', $singleCatNames);
    }

    /**
     * @param Category $category
     * @return mixed
     * @throws NoSuchEntityException
     */
    public function syncAsNewCollection(Category $category)
    {
        $collectionData                 =   $this->data->prepareData($category);
        $collectionData['entityLog']    = 'catalog';
        $url                            =   $this->config->getEndpoint('collections');
        return $this->yotpoCoreApiSync->sync(Request::HTTP_METHOD_POST, $url, $collectionData);
    }

    /**
     * @param DataObject|null $response
     * @return int|string|null
     */
    public function getYotpoIdFromResponse($response)
    {
        if (!$response) {
            return 0;
        }
        $responseData   =   $response->getData('response');
        $yotpoId = null;
        if ($response->getData('yotpo_id')) {
            $yotpoId   =   $response->getData('yotpo_id');
        } elseif ($responseData && is_array($responseData) && isset($responseData['collection'])) {
            $yotpoId   =   is_array($responseData['collection']) && isset($responseData['collection']['yotpo_id']) ?
                $responseData['collection']['yotpo_id'] : 0;
        }
        return $yotpoId;
    }

    /**
     * @param int $categoryRowId
     * @return void
     * @throws NoSuchEntityException
     */
    public function updateCategoryAttribute($categoryRowId)
    {
        $dataToInsertOrUpdate = [];
        $data   =   [
            'attribute_id'  =>  $this->data->getAttributeId('synced_to_yotpo_collection'),
            'store_id'      =>  $this->config->getStoreid(),
            $this->entityIdFieldValue => $categoryRowId,
            'value'         =>  1
        ];
        $dataToInsertOrUpdate[] =   $data;
        $this->insertOnDuplicate('catalog_category_entity_int', $dataToInsertOrUpdate);
    }

    /**
     * @param DataObject $response
     * @return bool
     */
    public function checkForCollectionExistsError(DataObject $response): bool
    {
        return '409' == $response->getData('status');
    }

    /**
     * @return CategoryCollection<mixed>
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getStoreCategoryCollection()
    {
        /** @var \Magento\Store\Model\Store $currentStore**/
        $currentStore = $this->storeManager->getStore();
        $rootCategoryId = $currentStore->getRootCategoryId();
        $collection = $this->categoryCollectionFactory->create();
        $collection->addNameToResult();
        $collection->addAttributeToFilter(
            [
                ['attribute' => 'path', 'like' => "1/{$rootCategoryId}/%"],
                ['attribute' => 'path', 'eq' => "1/{$rootCategoryId}"]
            ]
        );
        return $collection;
    }

    /**
     * @param string $categoryId
     * @return string
     */
    public function getYotpoIdFromCategoriesSyncTableByCategoryId($categoryId)
    {
        $storeId = $this->config->getStoreId();
        $connection = $this->resourceConnection->getConnection();
        $categoryYotpoIdQuery = $connection->select(
        )->from(
            [ $this->resourceConnection->getTableName($this::YOTPO_CATEGORY_SYNC_TABLE_NAME) ],
            ['yotpo_id']
        )->where(
            'category_id = ?',
            $categoryId
        )->where(
            'store_id = ?',
            $storeId
        );

        $categoryYotpoId = $connection->fetchOne($categoryYotpoIdQuery, 'yotpo_id');
        return $categoryYotpoId;
    }

    /**
     * @param array $categoryIds
     * @return array<string>
     */
    public function getYotpoIdsFromCategoriesSyncTableByCategoryIds(array $categoryIds)
    {
        $storeId = $this->config->getStoreId();
        $connection = $this->resourceConnection->getConnection();
        $categoryYotpoIdsQuery = $connection->select(
        )->from(
            [ $this->resourceConnection->getTableName($this::YOTPO_CATEGORY_SYNC_TABLE_NAME) ],
            [ 'category_id', 'yotpo_id' ]
        )->where(
            'category_id IN (?)',
            $categoryIds
        )->where(
            'store_id = ?',
            $storeId
        );

        $categoriesSyncData = $connection->fetchAssoc($categoryYotpoIdsQuery, 'category_id');

        $categoryIdsToYotpoIdsMap = [];
        foreach ($categoriesSyncData as $categoryId => $categorySyncRecord) {
            $categoryIdsToYotpoIdsMap[$categoryId] = $categorySyncRecord['yotpo_id'];
        }

        return $categoryIdsToYotpoIdsMap;
    }
}
