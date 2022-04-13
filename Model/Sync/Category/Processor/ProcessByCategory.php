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
use Yotpo\Core\Model\Sync\CollectionsProducts\Services\CollectionsProductsService;
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
     * @var CollectionsProductsService
     */
    protected $collectionsProductsService;

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
     * @param CollectionsProductsService $collectionsProductsService
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
        StoreManagerInterface $storeManager,
        CollectionsProductsService $collectionsProductsService
    ) {
        parent::__construct(
            $appEmulation,
            $resourceConnection,
            $config,
            $data,
            $yotpoCoreApiSync,
            $categoryCollectionFactory,
            $yotpoCoreCatalogLogger,
            $storeManager,
            $collectionsProductsService
        );
        $this->categoryHelper = $categoryHelper;
        $this->categorySyncRepositoryInterface = $categorySyncRepositoryInterface;
        $this->collectionsProductsService = $collectionsProductsService;
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
                $syncShouldProgress = true;
                if (!$this->config->isCatalogSyncActive()) {
                    $syncShouldProgress = false;
                    $this->yotpoCoreCatalogLogger->info(
                        __(
                            'Catalog Sync is Disabled - Magento Store ID: %1, Name: %2',
                            $storeId,
                            $this->config->getStoreName($storeId)
                        )
                    );
                }
                if ($this->config->isSyncResetInProgress($storeId, 'catalog')) {
                    $syncShouldProgress = false;
                    $this->yotpoCoreCatalogLogger->info(
                        __(
                            'Category sync is skipped because catalog sync
                            reset is in progress - Magento Store ID: %1, Name: %2',
                            $storeId,
                            $this->config->getStoreName($storeId)
                        )
                    );
                }
                if (!$syncShouldProgress) {
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
                $this->processEntities($retryCategoryIds);
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
     * @param array<mixed> $retryCategoryIds
     * @param int|null $storeId
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function processEntities($retryCategoryIds = [], $storeId = null)
    {
        if (!$storeId) {
            $storeId = $this->config->getStoreId();
        }

        $currentTime = date('Y-m-d H:i:s');
        $batchSize = $this->config->getConfig('product_sync_limit');
        $storeId = $this->config->getStoreId();
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
            if ($this->config->isSyncResetInProgress($storeId, 'catalog')) {
                $this->yotpoCoreCatalogLogger->info(
                    __(
                        'Category sync is skipped because catalog sync
                            reset is in progress - Magento Store ID: %1, Name: %2',
                        $storeId,
                        $this->config->getStoreName($storeId)
                    )
                );
                continue;
            }
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
            try {
                /** @var Category $magentoCategory */
                $magentoCategory->setData('nameWithPath', $this->getNameWithPath($magentoCategory, $categoriesByPath));
                $categoryId = $magentoCategory->getId();
                $currentCategoryYotpoId = $yotpoSyncedCategories[$categoryId]['yotpo_id'] ?? 0;
                $response = null;
                if (!$this->isCategoryWasEverSynced($yotpoSyncedCategories, $existingCollections, $magentoCategory)
                    || $this->isSyncedCategoryMissingYotpoId($yotpoSyncedCategories, $magentoCategory)
                ) {
                    $response = $this->syncAsNewCollection($magentoCategory);
                    $isCategorySyncedSuccessfully = $this->config->isResponseIndicatesSuccess($response);
                    if ($isCategorySyncedSuccessfully) {
                        $this->updateCategoryProductsForCollectionsProductsSync($magentoCategory);
                    }
                } elseif ($this->canResync(
                    $yotpoSyncedCategories[$categoryId],
                    $currentCategoryYotpoId,
                    $this->isCommandLineSync
                )) {
                    $response = $this->syncExistingOrNewCollection(
                        $magentoCategory,
                        $currentCategoryYotpoId
                    );
                } else {
                    $categoryIdToUpdate = $magentoCategory->getRowId() ?: $categoryId;
                    $this->updateCategoryAttribute($categoryIdToUpdate);
                }
                if (isset($existingCollections[$categoryId])) {
                    $response = $this->syncExistingOrNewCollection(
                        $magentoCategory,
                        $existingCollections[$categoryId]
                    );
                    if (!$response->getData('yotpo_id')) {
                        $response->setData('yotpo_id', $existingCollections[$categoryId]);
                    }
                }
                if ($this->checkForCollectionExistsError($response)) {
                    $response = false;
                    $existColls[] = $categoryId;
                }
                $yotpoTableData = $response ? $this->prepareYotpoTableData($response) : [];
                if ($yotpoTableData) {
                    if (array_key_exists('yotpo_id', $yotpoTableData)
                        && !$yotpoTableData['yotpo_id']
                        && array_key_exists($categoryId, $yotpoSyncedCategories)
                    ) {
                        $yotpoTableData['yotpo_id'] = $currentCategoryYotpoId;
                    }

                    if ($currentCategoryYotpoId
                        && array_key_exists('yotpo_id', $yotpoTableData)
                        && $yotpoTableData['yotpo_id']
                        && $currentCategoryYotpoId != $yotpoTableData['yotpo_id']
                    ) {
                        $this->updateCategoryProductsForCollectionsProductsSync($magentoCategory);
                    }

                    $yotpoTableData['store_id'] = $storeId;
                    $yotpoTableData['category_id'] = $categoryId;
                    $yotpoTableData['synced_to_yotpo'] = $currentTime;
                    $this->insertOrUpdateYotpoTableData($yotpoTableData);
                    if ($this->config->canUpdateCustomAttribute($yotpoTableData['response_code'])) {
                        $categoryIdToUpdate = $magentoCategory->getRowId() ?: $categoryId;
                        $this->updateCategoryAttribute($categoryIdToUpdate);
                    }
                    $this->yotpoCoreCatalogLogger->info(
                        sprintf('Category Sync - sync success - Category ID: %s', $categoryId)
                    );
                    if ($this->isCommandLineSync) {
                        // phpcs:ignore
                        echo 'Category process completed for categoryId - ' . $categoryId . PHP_EOL;
                    }
                }
            } catch (\Exception $e) {
                $magentoCategoryId =  $magentoCategory->getId();
                $this->updateCategoryAttribute($magentoCategoryId);
                $this->yotpoCoreCatalogLogger->info(
                    __(
                        'Exception raised within processEntities - $magentoCategoryId: %1, Exception Message: %2',
                        $magentoCategoryId,
                        $e->getMessage()
                    )
                );
            }
        }
        $existingCollections = $this->getExistingCollectionIds($existColls);
        foreach ($existingCollections as $mageCatId => $yotpoId) {
            try {
                $data = [
                    'response_code' => '201',
                    'yotpo_id' => $yotpoId,
                    'store_id' => $storeId,
                    'category_id' => $mageCatId,
                    'synced_to_yotpo' => $currentTime
                ];
                $this->insertOrUpdateYotpoTableData($data);
                if ($this->config->canUpdateCustomAttribute($data['response_code'])) {
                    $categoryIdToUpdate = $magentoCategories[$mageCatId]->getRowId()
                        ?: $magentoCategories[$mageCatId]->getId();
                    $this->updateCategoryAttribute($categoryIdToUpdate);
                }
            } catch (\Exception $e) {
                $this->yotpoCoreCatalogLogger->info(
                    __(
                        'Exception raised within processEntities - $mageCatId: %1, $yotpoId: %2, Exception Message: %3',
                        $mageCatId,
                        $yotpoId,
                        $e->getMessage()
                    )
                );
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
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function deleteCollections()
    {
        $storeId = $this->config->getStoreId();
        $this->yotpoCoreCatalogLogger->info(
            __(
                'Category Sync - Starting assigning deleted categories for store - Magento Store ID: %1, Name: %2',
                $storeId,
                $this->config->getStoreName($storeId)
            )
        );
        $categoriesToDelete = $this->getCollectionsToDelete();
        foreach ($categoriesToDelete as $category) {
            $categoryId = $category['category_id'];
            try {
                $this->yotpoCoreCatalogLogger->info(
                    __(
                        'Category Sync - Deleting category - Magento Store ID: %1, Name: %2, Category ID - %3',
                        $storeId,
                        $this->config->getStoreName($storeId),
                        $categoryId
                    )
                );
                $categoryProductsIds = $this->collectionsProductsService->getCategoryProductsIdsFromSyncTable($categoryId);
                if ($categoryProductsIds) {
                    $this->collectionsProductsService->assignCategoryProductsForCollectionsProductsSync(
                        $categoryProductsIds,
                        $storeId,
                        $categoryId,
                        true
                    );
                }
                $this->updateYotpoTblForDeletedCategories($categoryId);
            } catch (\Exception $e) {
                $this->yotpoCoreCatalogLogger->info(
                    __(
                        'Exception raised within deleteCollections - $categoryId: %1, Exception Message: %2',
                        $categoryId,
                        $e->getMessage()
                    )
                );
            }
        }

        $this->yotpoCoreCatalogLogger->info(
            __(
                'Category Sync - Finished assigning deleted categories for store - Magento Store ID: %1, Name: %2',
                $storeId,
                $this->config->getStoreName($storeId)
            )
        );
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

    /**
     * @param array<mixed> $yotpoSyncedCategories
     * @param array<mixed> $existingCollections
     * @param Category $magentoCategory
     * @return bool
     */
    private function isCategoryWasEverSynced(
        $yotpoSyncedCategories,
        $existingCollections,
        $magentoCategory
    ) {
        return isset(
            $yotpoSyncedCategories[$magentoCategory->getId()]
        ) && isset(
            $existingCollections[$magentoCategory->getId()]
        );
    }

    /**
     * @param array<mixed> $yotpoSyncedCategories
     * @param Category $magentoCategory
     * @return bool
     */
    private function isSyncedCategoryMissingYotpoId(array $yotpoSyncedCategories, Category $magentoCategory)
    {
        return isset(
            $yotpoSyncedCategories[$magentoCategory->getId()]
        ) && !$yotpoSyncedCategories[$magentoCategory->getId()]['yotpo_id'];
    }
}
