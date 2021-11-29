<?php

namespace Yotpo\Core\Model\Sync\Category\Processor;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Yotpo\Core\Model\Api\Sync as YotpoCoreApiSync;
use Yotpo\Core\Model\Config;
use Magento\Catalog\Helper\Category as CategoryHelper;
use Yotpo\Core\Model\Sync\Catalog\Logger as YotpoCoreCatalogLogger;
use Yotpo\Core\Model\Sync\Category\Data;

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
     * @var string|null
     */
    protected $entityIdFieldValue;

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
     */
    public function __construct(
        AppEmulation $appEmulation,
        ResourceConnection $resourceConnection,
        Config $config,
        Data $data,
        YotpoCoreApiSync $yotpoCoreApiSync,
        CategoryCollectionFactory $categoryCollectionFactory,
        YotpoCoreCatalogLogger $yotpoCoreCatalogLogger,
        CategoryHelper $categoryHelper
    ) {
        parent::__construct(
            $appEmulation,
            $resourceConnection,
            $config,
            $data,
            $yotpoCoreApiSync,
            $categoryCollectionFactory,
            $yotpoCoreCatalogLogger
        );
        $this->categoryHelper = $categoryHelper;
        $this->entityIdFieldValue = $this->config->getEavRowIdFieldName();
    }

    /**
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function process()
    {
        try {
            foreach ((array)$this->config->getAllStoreIds(false) as $storeId) {
                $this->emulateFrontendArea($storeId);
                if (!$this->config->isCatalogSyncActive()) {
                    $this->stopEnvironmentEmulation();
                    continue;
                }
                $this->yotpoCoreCatalogLogger->info(
                    sprintf('Category Sync - Start - Magento Store : %s', $this->config->getStoreName($storeId))
                );

                $this->processEntity();
                $this->stopEnvironmentEmulation();
                $this->yotpoCoreCatalogLogger->info(
                    sprintf('Category Sync - Finish - Magento Store : %s', $this->config->getStoreName($storeId))
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
     * @throws NoSuchEntityException
     * @throws LocalizedException
     * @return void
     */
    public function processEntity()
    {
        $currentTime        =   date('Y-m-d H:i:s');
        $batchSize          =   $this->config->getConfig('product_sync_limit');
        $existColls         =   [];
        $attributeId = $this->data->getAttributeId(Config::CATEGORY_SYNC_ATTR_CODE);
        $collection         =   $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect('*');
        $collection->addNameToResult();
        $collection->getSelect()->joinLeft(
            ['at' => $this->resourceConnection->getTableName('catalog_category_entity_int')],
            'e.' . $this->entityIdFieldValue. ' = at.' . $this->entityIdFieldValue .
            ' AND at.attribute_id = ' . $attributeId .
            ' AND at.store_id=\''.$this->config->getStoreId().'\'',
            null
        );
        $collection->getSelect()->where(
            '(
              at.value is null OR at.value=0
            )'
        );
        $collection->getSelect()->limit($batchSize);
        $magentoCategories  =   [];
        foreach ($collection->getItems() as $category) {
            $magentoCategories[$category->getId()]  =   $category;
        }
        $existingCollections = $this->getExistingCollectionIds(array_keys($magentoCategories));
        $categoriesByPath   =   $this->getCategoriesFromPathNames(array_values($magentoCategories));
        $yotpoSyncedCategories  =   $this->getYotpoSyncedCategories(array_keys($magentoCategories));

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
                $response           =   $this->syncAsNewCollection($magentoCategory);
            } else {
                if (isset($yotpoSyncedCategories[$magentoCategory->getId()])) {
                    if ($yotpoSyncedCategories[$magentoCategory->getId()]['yotpo_id']) {
                        if ($this->canResync(
                            $yotpoSyncedCategories[$magentoCategory->getId()],
                            $yotpoSyncedCategories[$magentoCategory->getId()]['yotpo_id']
                        )) {
                            $response   =   $this->syncExistingCollection(
                                $magentoCategory,
                                $yotpoSyncedCategories[$magentoCategory->getId()]['yotpo_id']
                            );
                        } else {
                            $categoryIdToUpdate = $magentoCategory->getRowId() ?: $magentoCategory->getId();
                            $this->updateCategoryAttribute($categoryIdToUpdate);
                        }
                    } else {
                        $response   =   $this->syncAsNewCollection($magentoCategory);
                    }
                } elseif (isset($existingCollections[$magentoCategory->getId()])) {
                    $response   =   $this->syncExistingCollection(
                        $magentoCategory,
                        $existingCollections[$magentoCategory->getId()]
                    );
                    $response->setData('yotpo_id', $existingCollections[$magentoCategory->getId()]);
                }
            }
            if ($this->checkForCollectionExistsError($response)) {
                $response       =   false;
                $existColls[]   =   $magentoCategory->getId();
            }
            $yotpoTableData     =   $response ? $this->prepareYotpoTableData($response) : [];
            if ($yotpoTableData) {
                if (array_key_exists('yotpo_id', $yotpoTableData) &&
                    !$yotpoTableData['yotpo_id']
                    && array_key_exists($magentoCategory->getId(), $yotpoSyncedCategories)
                ) {
                    $yotpoTableData['yotpo_id'] =   $yotpoSyncedCategories[$magentoCategory->getId()]['yotpo_id'];
                }
                $yotpoTableData['store_id']         =   $this->config->getStoreId();
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
            }
        }
        $existingCollections    =   $this->getExistingCollectionIds($existColls);
        foreach ($existingCollections as $mageCatId => $yotpoId) {
            $data   =   [
                'response_code'     =>  '201',
                'yotpo_id'          =>  $yotpoId,
                'store_id'          =>  $this->config->getStoreId(),
                'category_id'       =>  $mageCatId,
                'synced_to_yotpo'   =>  $currentTime
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
                'Category Sync - sync completed - Magento Store : %s',
                $this->config->getStoreName($this->config->getStoreId())
            )
        );
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
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @return void
     */
    public function deleteCollections()
    {
        $this->yotpoCoreCatalogLogger->info('Category Sync - delete categories start');
        $categoriesToDelete =   $this->getCollectionsToDelete();
        $catToUpdateAsDel   =   [];
        foreach ($categoriesToDelete as $cat) {
            $products   =   $this->getProductsUnderCategory($cat['yotpo_id']);
            $this->yotpoCoreCatalogLogger->info(
                sprintf('Category Sync - delete categories - Category ID - %s', $cat['category_id'])
            );
            foreach ($products as $product) {
                $success = $this->unAssignProductFromCollection($cat['yotpo_id'], $product['external_id']);
                if ($success) {
                    $this->updateYotpoTblForDeletedCategories($cat['category_id']);
                    $this->yotpoCoreCatalogLogger->info(
                        'Category Sync - Delete categories - Finished - Update Category ID -
                    ' . $cat['category_id']
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
        $url    =   $this->config->getEndpoint('collections_product', ['{yotpo_collection_id}'], [$yotpoId]);
        $data['entityLog']  =   'catalog';
        $response           =   $this->yotpoCoreApiSync->sync(Request::HTTP_METHOD_GET, $url, $data);
        $response           =   $response->getData('response');
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
        $connection =   $this->resourceConnection->getConnection();
        $storeId    =   $this->config->getStoreId();
        $table      =   $this->resourceConnection->getTableName('yotpo_category_sync');
        $categories =   $connection->select()
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
        $collectionData                 =   $this->data->prepareData($category);
        $collectionData['entityLog']    = 'catalog';
        $url                            =   $this->config->getEndpoint('collections');
        return $this->yotpoCoreApiSync->sync(Request::HTTP_METHOD_POST, $url, $collectionData);
    }

    /**
     * @param Category $category
     * @param int $yotpoId
     * @return mixed
     * @throws NoSuchEntityException
     */
    public function syncExistingCollection(Category $category, int $yotpoId)
    {
        $collectionData                 =   $this->data->prepareData($category);
        $collectionData['entityLog']    = 'catalog';
        $url    =   $this->config->getEndpoint('collections_update', ['{yotpo_collection_id}'], [$yotpoId]);
        return $this->yotpoCoreApiSync->sync(\Zend_Http_Client::PATCH, $url, $collectionData);
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
            ['is_deleted_at_yotpo'  =>  '1'],
            [
                'category_id IN (?)' => $categoryIds,
                'store_id' => $this->config->getStoreId()
            ]
        );
    }
}
