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
        $table      =   $connection->getTableName('yotpo_category_sync');
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
            $collections    =   $response['collections'];
            $count = count($collections);
            for ($i=0; $i<$count; $i++) {
                $yotpoCollections[$collections[$i]['external_id']]  =   $collections[$i]['yotpo_id'];
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
        if ($responseData && $responseData['collection']) {
            $data['yotpo_id']   =   $responseData['collection']['yotpo_id'];
        } else {
            $data['yotpo_id']   =   null;
        }
        return $data;
    }

    /**
     * @param array<mixed> $yotpoTableFinalData
     * @return void
     */
    public function insertOrUpdateYotpoTableData(array $yotpoTableFinalData)
    {
        $finalData = [];
        foreach ($yotpoTableFinalData as $data) {
            $finalData[] = [
                'category_id'        =>  $data['category_id'],
                'synced_to_yotpo'    =>  $data['synced_to_yotpo'],
                'response_code'      =>  $data['response_code'],
                'yotpo_id'           =>  $data['yotpo_id'],
                'store_id'           =>  $data['store_id']
            ];
        }
        if ($finalData) {
            $this->insertOnDuplicate('yotpo_category_sync', $finalData);
        }
    }

    /**
     * @param array<mixed> $category
     * @param array <mixed>|int|string $yotpoId
     * @return bool
     */
    public function canResync(array $category = [], $yotpoId = []): bool
    {
        return $this->config->canResync($category['response_code'], $yotpoId);
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
        return $response && $response->getData('is_success');
    }

    /**
     * @param array<mixed> $categories
     * @return array<mixed>
     */
    public function getCategoriesFromPathNames($categories): array
    {
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
}
