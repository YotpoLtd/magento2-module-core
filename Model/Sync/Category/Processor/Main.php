<?php

namespace Yotpo\Core\Model\Sync\Category\Processor;

use Magento\Catalog\Model\Category;
use Magento\Framework\DataObject;
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

/**
 * Class Main - Manage Category sync
 */
class Main extends AbstractJobs
{
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
     * Main constructor.
     * @param AppEmulation $appEmulation
     * @param ResourceConnection $resourceConnection
     * @param Config $config
     * @param Data $data
     * @param YotpoCoreApiSync $yotpoCoreApiSync
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param YotpoCoreCatalogLogger $yotpoCoreCatalogLogger
     */
    public function __construct(
        AppEmulation $appEmulation,
        ResourceConnection $resourceConnection,
        Config $config,
        Data $data,
        YotpoCoreApiSync $yotpoCoreApiSync,
        CategoryCollectionFactory $categoryCollectionFactory,
        YotpoCoreCatalogLogger $yotpoCoreCatalogLogger
    ) {
        $this->config   =   $config;
        $this->data   =   $data;
        $this->yotpoCoreApiSync             =   $yotpoCoreApiSync;
        $this->categoryCollectionFactory    =   $categoryCollectionFactory;
        $this->yotpoCoreCatalogLogger       =   $yotpoCoreCatalogLogger;
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
     * @param array<mixed> $yotpoCollections
     * @param int|null $catId
     * @return mixed|string
     */
    public function getYotpoIdFromCollectionArray($yotpoCollections, $catId)
    {
        $return = '';
        if (is_array($yotpoCollections)
            && $catId && isset($yotpoCollections[$catId])
        ) {
            $return = $yotpoCollections[$catId];
        }
        return $return;
    }
}
