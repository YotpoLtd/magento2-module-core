<?php

namespace Yotpo\Core\Model\Sync\Category\Processor;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Magento\Store\Model\StoreManagerInterface;
use Yotpo\Core\Model\Api\Sync as YotpoCoreApiSync;
use Yotpo\Core\Model\Config;
use Magento\Catalog\Helper\Category as CategoryHelper;
use Yotpo\Core\Model\Sync\Catalog\Logger as YotpoCoreCatalogLogger;
use Yotpo\Core\Model\Sync\Category\Data;
use Yotpo\Core\Api\CategorySyncRepositoryInterface;

/**
 * Class ProcessByCategory - Process categories
 */
class ProcessByCategory extends Main
{
    /**
     * @var CategoryHelper
     */
    protected $categoryHelper;

    /**
     * @var CategorySyncRepositoryInterface
     */
    protected $categorySyncRepositoryInterface;

    /**
     * @var bool
     */
    protected $isCommandLineSync = false;

    /**
     * ProcessByCategory constructor.
     * @param AppEmulation $appEmulation
     * @param ResourceConnection $resourceConnection
     * @param Config $config
     * @param Data $data
     * @param YotpoCoreApiSync $yotpoCoreApiSync
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param YotpoCoreCatalogLogger $yotpoCoreCatalogLogger
     * @param CategoryHelper $categoryHelper
     * @param CategorySyncRepositoryInterface $categorySyncRepositoryInterface
     */
    public function __construct(
        AppEmulation $appEmulation,
        ResourceConnection $resourceConnection,
        Config $config,
        Data $data,
        YotpoCoreApiSync $yotpoCoreApiSync,
        CategoryCollectionFactory $categoryCollectionFactory,
        YotpoCoreCatalogLogger $yotpoCoreCatalogLogger,
        CategoryHelper $categoryHelper,
        CategorySyncRepositoryInterface $categorySyncRepositoryInterface,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct(
            $appEmulation,
            $resourceConnection,
            $config,
            $data,
            $yotpoCoreApiSync,
            $categoryCollectionFactory,
            $yotpoCoreCatalogLogger,
            $storeManager
        );
        $this->categoryHelper = $categoryHelper;
        $this->categorySyncRepositoryInterface = $categorySyncRepositoryInterface;
    }

    /**
     * @param array <mixed> $retryCategories
     * @param array <mixed> $storeIds
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function process($retryCategories = [], $storeIds = [])
    {
        try {
            if (!$storeIds) {
                $storeIds = (array)$this->config->getAllStoreIds(false);
            }
            foreach ($storeIds as $storeId) {
                if ($this->isCommandLineSync) {
                    // phpcs:ignore
                    echo 'Category process started for store - ' .
                        $this->config->getStoreName($storeId) . PHP_EOL;
                }
                $this->emulateFrontendArea($storeId);
                if (!$this->config->isCatalogSyncActive()) {
                    $this->stopEnvironmentEmulation();
                    continue;
                }
                $this->yotpoCoreCatalogLogger->info(
                    sprintf(
                        'Category Sync - Start - Magento Store ID: %s, Name: %s',
                        $storeId,
                        $this->config->getStoreName($storeId)
                    )
                );
                $retryCategoryIds = $retryCategories[$storeId] ?? $retryCategories;
                $this->processEntity($retryCategoryIds);
                $this->stopEnvironmentEmulation();
                $this->yotpoCoreCatalogLogger->info(
                    sprintf(
                        'Category Sync - Finish - Magento Store ID: %s, Name: %s',
                        $storeId,
                        $this->config->getStoreName($storeId)
                    )
                );
            }
            $this->stopEnvironmentEmulation();
        } catch (NoSuchEntityException $e) {
            $this->stopEnvironmentEmulation();
            throw new NoSuchEntityException(
                __('Category Sync - ProcessByCategory - process() - NoSuchEntityException %1', $e->getMessage())
            );

        } catch (LocalizedException $e) {
            $this->stopEnvironmentEmulation();
            throw new LocalizedException(
                __('Category Sync - ProcessByCategory - process() - LocalizedException %1', $e->getMessage())
            );
        }
    }

    /**
     * @param array <mixed> $retryCategoryIds
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function processEntity($retryCategoryIds = [])
    {
        $storeId = $this->config->getStoreId();
        $currentTime = date('Y-m-d H:i:s');
        $batchSize = $this->config->getConfig('product_sync_limit');
        $existColls = [];
        $collection = $this->getStoreCategoryCollection();
        if (!$retryCategoryIds) {
            $collection->addAttributeToFilter(
                [
                    ['attribute' => Config::CATEGORY_SYNC_ATTR_CODE, 'null' => true],
                    ['attribute' => Config::CATEGORY_SYNC_ATTR_CODE, 'eq' => '0'],
                ]
            );
        } else {
            $collection->addFieldToFilter('entity_id', ['in' => $retryCategoryIds]);
        }
        $collection->getSelect()->limit($batchSize);
        $magentoCategories = [];
        foreach ($collection->getItems() as $category) {
            $magentoCategories[$category->getId()] = $category;
        }
        $existingCollections = $this->getExistingCollectionIds(array_keys($magentoCategories));
        $categoriesByPath = $this->getCategoriesFromPathNames(array_values($magentoCategories));
        $yotpoSyncedCategories = $this->getYotpoSyncedCategories(array_keys($magentoCategories));
        if (!$magentoCategories) {
            $this->yotpoCoreCatalogLogger->info(
                'Category Sync - There are no items left to sync'
            );
        }

        foreach ($magentoCategories as $magentoCategory) {
            /** @var Category $magentoCategory */
            $magentoCategory->setData('nameWithPath', $this->getNameWithPath($magentoCategory, $categoriesByPath));
            $response = null;
            if (!isset($yotpoSyncedCategories[$magentoCategory->getId()]) &&
                !isset($existingCollections[$magentoCategory->getId()])
            ) {
                $response = $this->syncAsNewCollection($magentoCategory);
            } else {
                if (isset($yotpoSyncedCategories[$magentoCategory->getId()])) {
                    if ($yotpoSyncedCategories[$magentoCategory->getId()]['yotpo_id']) {
                        if ($this->canResync(
                            $yotpoSyncedCategories[$magentoCategory->getId()],
                            $yotpoSyncedCategories[$magentoCategory->getId()]['yotpo_id'],
                            $this->isCommandLineSync
                        )) {
                            $response = $this->syncExistingCollection(
                                $magentoCategory,
                                $yotpoSyncedCategories[$magentoCategory->getId()]['yotpo_id']
                            );
                        } else {
                            $categoryIdToUpdate = $magentoCategory->getRowId() ?: $magentoCategory->getId();
                            $this->updateCategoryAttribute($categoryIdToUpdate);
                        }
                    } else {
                        $response = $this->syncAsNewCollection($magentoCategory);
                    }
                } elseif (isset($existingCollections[$magentoCategory->getId()])) {
                    $response = $this->syncExistingCollection(
                        $magentoCategory,
                        $existingCollections[$magentoCategory->getId()]
                    );
                    if (!$response->getData('yotpo_id')) {
                        $response->setData('yotpo_id', $existingCollections[$magentoCategory->getId()]);
                    }
                }
            }
            if ($this->checkForCollectionExistsError($response)) {
                $response = false;
                $existColls[] = $magentoCategory->getId();
            }
            $yotpoTableData = $response ? $this->prepareYotpoTableData($response) : [];
            if ($yotpoTableData) {
                if (array_key_exists('yotpo_id', $yotpoTableData) &&
                    !$yotpoTableData['yotpo_id']
                    && array_key_exists($magentoCategory->getId(), $yotpoSyncedCategories)
                ) {
                    $yotpoTableData['yotpo_id'] = $yotpoSyncedCategories[$magentoCategory->getId()]['yotpo_id'];
                }
                $yotpoTableData['store_id']         =   $storeId;
                $yotpoTableData['category_id']      =   $magentoCategory->getId();
                $yotpoTableData['synced_to_yotpo']  =   $currentTime;
                $this->insertOrUpdateYotpoTableData($yotpoTableData);
                if ($this->config->canUpdateCustomAttribute($yotpoTableData['response_code'])) {
                    $categoryIdToUpdate = $magentoCategory->getRowId() ?: $magentoCategory->getId();
                    $this->updateCategoryAttribute($categoryIdToUpdate);
                }
                $this->yotpoCoreCatalogLogger->info(
                    sprintf('Category Sync - sync success - Category ID: %s', $magentoCategory->getId())
                );
                if ($this->isCommandLineSync) {
                    // phpcs:ignore
                    echo 'Category process completed for categoryId - ' . $magentoCategory->getId() . PHP_EOL;
                }
            }
        }
        $existingCollections = $this->getExistingCollectionIds($existColls);
        foreach ($existingCollections as $mageCatId => $yotpoId) {
            $data = [
                'response_code' => '201',
                'yotpo_id' => $yotpoId,
                'store_id' => $storeId,
                'category_id' => $mageCatId,
                'synced_to_yotpo' => $currentTime
            ];
            $this->insertOrUpdateYotpoTableData($data);
            if ($this->config->canUpdateCustomAttribute($data['response_code'])) {
                $categoryIdToUpdate   =   $magentoCategories[$mageCatId]->getRowId()
                    ?: $magentoCategories[$mageCatId]->getId();
                $this->updateCategoryAttribute($categoryIdToUpdate);
            }
        }
        $this->deleteCollections();
        $this->yotpoCoreCatalogLogger->info(
            sprintf(
                'Category Sync - sync completed - Magento Store ID: %s, Name: %s',
                $storeId,
                $this->config->getStoreName($storeId)
            )
        );
    }

    /**
     * @return void
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function deleteCollections()
    {
        $this->yotpoCoreCatalogLogger->info('Category Sync - delete categories start');
        $categoriesToDelete = $this->getCollectionsToDelete();
        foreach ($categoriesToDelete as $category) {
            $products = $this->getProductsUnderCategory($category['yotpo_id']);
            $this->yotpoCoreCatalogLogger->info(
                sprintf('Category Sync - delete categories - Category ID - %s', $category['category_id'])
            );
            foreach ($products as $product) {
                $success = $this->unAssignProductFromCollection($category['yotpo_id'], $product['external_id']);
                if ($success) {
                    $this->updateYotpoTblForDeletedCategories($category['category_id']);
                    $this->yotpoCoreCatalogLogger->info(
                        'Category Sync - Delete categories - Finished - Update Category ID -
                    ' . $category['category_id']
                    );
                }
            }
        }
        $this->yotpoCoreCatalogLogger->info('Category Sync - delete categories complete');
    }

    /**
     * @param int $yotpoId
     * @return array<mixed>
     * @throws NoSuchEntityException
     */
    public function getProductsUnderCategory(int $yotpoId): array
    {
        $url = $this->config->getEndpoint('collections_product', ['{yotpo_collection_id}'], [$yotpoId]);
        $data['entityLog'] = 'catalog';
        $response = $this->yotpoCoreApiSync->sync(Request::HTTP_METHOD_GET, $url, $data);
        $response = $response->getData('response');
        if (!$response) {
            return [];
        }
        return array_key_exists('products', $response) ? $response['products'] : [];
    }

    /**
     * @return array<mixed>
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getCollectionsToDelete(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $storeId = $this->config->getStoreId();
        $table = $this->resourceConnection->getTableName('yotpo_category_sync');
        $categories = $connection->select()
            ->from($table)
            ->where('store_id=(?)', $storeId)
            ->where('is_deleted = \'1\'')
            ->where('is_deleted_at_yotpo != \'1\'')
            ->where('yotpo_id is not null')
            ->limit($this->config->getConfig('sync_limit_collections'));
        return $connection->fetchAssoc($categories);
    }

    /**
     * @param Category $category
     * @return mixed
     * @throws NoSuchEntityException
     */
    public function syncAsNewCollection(Category $category)
    {
        $collectionData = $this->data->prepareData($category);
        $collectionData['entityLog'] = 'catalog';
        $url = $this->config->getEndpoint('collections');
        return $this->yotpoCoreApiSync->sync(Request::HTTP_METHOD_POST, $url, $collectionData);
    }

    /**
     * @param array <int> | int $categoryIds
     * @return void
     * @throws NoSuchEntityException
     */
    protected function updateYotpoTblForDeletedCategories($categoryIds)
    {
        if (!is_array($categoryIds)) {
            $categoryIds = [$categoryIds];
        }
        $connection =   $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName('yotpo_category_sync'),
            ['is_deleted_at_yotpo' => '1'],
            [
                'category_id IN (?)' => $categoryIds,
                'store_id' => $this->config->getStoreId()
            ]
        );
    }

    /**
     * @return void
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function retryCategorySync()
    {
        $this->isCommandLineSync = true;
        $categoryIds = [];
        $storeIds = [];
        $categoryByStore = [];
        $items = $this->categorySyncRepositoryInterface->getByResponseCodes();
        foreach ($items as $item) {
            $categoryIds[] = $item['category_id'];
            $storeIds[] = $item['store_id'];
            $categoryByStore[$item['store_id']][] = $item['category_id'];
        }
        if ($categoryByStore) {
            $this->process($categoryByStore, array_unique($storeIds));
        } else {
            // phpcs:ignore
            echo 'No category data to process.' . PHP_EOL;
        }
    }
}
