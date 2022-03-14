<?php
namespace Yotpo\Core\Observer\Product;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\ResourceConnection;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\Data\Main;
use Yotpo\Core\Model\Sync\CollectionsProducts\Services\CollectionsProductsService;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Session as CatalogSession;
use Magento\Store\Api\StoreRepositoryInterface;

/**
 * Class SaveAfter - Update yotpo attribute value when product updated
 */
class SaveAfter extends Data implements ObserverInterface
{
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var AppEmulation
     */
    protected $resourceConnection;

    /**
     * @var ResourceConnection
     */
    protected $appEmulation;

    /**
     * @var Main
     */
    protected $main;

    /**
     * @var CatalogSession
     */
    protected $catalogSession;

    /**
     * @var StoreRepositoryInterface
     */
    protected $storeRepository;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var CollectionsProductsService
     */
    protected $collectionsProductsService;

    /**
     * SaveAfter constructor.
     * @param StoreManagerInterface $storeManager
     * @param ResourceConnection $resourceConnection
     * @param AppEmulation $appEmulation
     * @param Main $main
     * @param CatalogSession $catalogSession
     * @param StoreRepositoryInterface $storeRepository
     * @param Config $config
     * @param CollectionsProductsService $collectionsProductsService
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        ResourceConnection $resourceConnection,
        AppEmulation $appEmulation,
        Main $main,
        CatalogSession $catalogSession,
        StoreRepositoryInterface $storeRepository,
        Config $config,
        CollectionsProductsService $collectionsProductsService
    ) {
        $this->storeManager = $storeManager;
        $this->resourceConnection = $resourceConnection;
        $this->appEmulation = $appEmulation;
        $this->main = $main;
        $this->catalogSession = $catalogSession;
        $this->storeRepository = $storeRepository;
        $this->config = $config;
        $this->collectionsProductsService = $collectionsProductsService;
        parent::__construct($resourceConnection, $appEmulation);
    }

    /**
     * Execute observer - Update yotpo attribute, Manage is_deleted attr.
     *
     * @param EventObserver $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(EventObserver $observer)
    {
        $storeIdsToUpdate = [];
        $product = $observer->getEvent()->getProduct();
        $currentStoreId = $product->getStoreId();
        if ($currentStoreId == 0) {
            $stores = $this->storeRepository->getList();
            foreach ($stores as $store) {
                $storeIdsToUpdate[] = $store->getId();
            }
        } else {
            $storeIdsToUpdate[] = $currentStoreId;
        }

        $productId = $product->getId();
        if ($product->hasDataChanges()) {
            $this->updateProductAttribute([$product->getRowId() ?: $productId], $storeIdsToUpdate);
            $this->updateIsDeleted($product);
            $tableData = ['response_code' => Config::CUSTOM_RESPONSE_DATA];
            $this->updateYotpoSyncTable($tableData, $storeIdsToUpdate, [$productId]);
        }

        $this->unassignProductChildrensForSync($product);

        $productCategoriesBeforeSave = $this->catalogSession->getProductCategoriesIds();
        $productCategories = $this->getCategoryIdsFromCategoryProductsTableByProductId($productId);
        foreach($storeIdsToUpdate as $storeId) {
            if (!$this->config->isCatalogSyncActive($storeId)) {
                continue;
            }

            $this->assignProductCategoriesForCollectionsProductsSync($productCategoriesBeforeSave, $productCategories, $storeId, $productId);
            $this->assignDeletedProductCategoriesForCollectionsProductsSync($productCategoriesBeforeSave, $productCategories, $storeId, $productId);
        }

    }

    /**
     * update Yotpo product attribute
     * @param array<mixed> $productIds
     * @param array<mixed> $storeIds
     * @return void
     */
    private function updateProductAttribute($productIds = [], $storeIds = [])
    {
        $connection = $this->resourceConnection->getConnection();
        $cond   =   [
            $this->config->getEavRowIdFieldName() . ' IN (?) ' => $productIds,
            'attribute_id = ?' => $this->main->getAttributeId(Config::CATALOG_SYNC_ATTR_CODE)
        ];
        $storeIds = array_filter($storeIds);
        if ($storeIds) {
            $cond['store_id IN (?) '] = $storeIds;
        }
        $connection->update(
            $this->resourceConnection->getTableName('catalog_product_entity_int'),
            ['value' => 0],
            $cond
        );
    }

    /**
     * Update 'is_deleted' in yotpo table
     * @param Product $product
     * @return void
     * @throws LocalizedException
     */
    private function updateIsDeleted($product)
    {
        $existingIds = $product->getOrigData('website_ids');
        $newIds = $product->getData('website_ids');

        $removedWebsite = $this->findAndRetrieveDifferenceBetweenArrays($existingIds, $newIds);
        if (count($removedWebsite) > 0) {
            $storeIds = $this->collectStoreIds($removedWebsite);
            $tableData = [
                'is_deleted' => 1,
                'is_deleted_at_yotpo' => 0,
                'response_code' => Config::CUSTOM_RESPONSE_DATA
            ];
            $this->updateYotpoSyncTable($tableData, $storeIds, [$product->getId()]);
        }

        $newWebsite = $this->findAndRetrieveDifferenceBetweenArrays($newIds, $existingIds);
        if (count($newWebsite) > 0) {
            $storeIds = $this->collectStoreIds($newWebsite);

            $tableData = [
                'is_deleted' => 0,
                'response_code' => Config::CUSTOM_RESPONSE_DATA
            ];
            $this->updateYotpoSyncTable($tableData, $storeIds, [$product->getId()]);

            //If it is already deleted, should re-sync this product
            $this->updateProductAttribute([$product->getRowId() ?: $product->getId()], $storeIds);
        }
    }

    /**
     * Collect store_id from website id
     * @param array<int, int> $websiteIds
     * @return array<int, int>
     * @throws LocalizedException
     */
    private function collectStoreIds($websiteIds)
    {
        $storeIds = [];
        foreach ($websiteIds as $websiteId) {
            /* @phpstan-ignore-next-line */
            $tempStoreIds = $this->storeManager->getWebsite($websiteId)->getStoreIds();
            $storeIds = $storeIds + $tempStoreIds;
        }
        return $storeIds;
    }

    /**
     * @param array<int, int> $productChildrenIdsBeforeSave
     * @param array<int, int> $productChildrenIds
     * @param Product $product
     * @return void
     */
    protected function manageUnAssign($productChildrenIdsBeforeSave, $productChildrenIds, $product)
    {
        if (isset($productChildrenIdsBeforeSave[0])) {
            $productChildrenIdsBeforeSave = (array)$productChildrenIdsBeforeSave[0];
        }
        if (isset($productChildrenIds[0])) {
            $productChildrenIds = (array)$productChildrenIds[0];
        }

        $productChildrenIdsBeforeSave = $this->checkIfMultiDimensional($productChildrenIdsBeforeSave);
        $productChildrenIds = $this->checkIfMultiDimensional($productChildrenIds);

        $result = array_merge(array_diff($productChildrenIdsBeforeSave, $productChildrenIds), array_diff($productChildrenIds, $productChildrenIdsBeforeSave));
        if (count($result) > 0) {
            $this->updateUnAssign($result, $product);
        }

        $result = $this->findAndRetrieveDifferenceBetweenArrays($productChildrenIds, $productChildrenIdsBeforeSave);
        if (count($result) > 0) {
            $this->updateProductAttribute($result, [$product->getStoreId()]);
            $tableData = ['response_code' => Config::CUSTOM_RESPONSE_DATA];
            $this->updateYotpoSyncTable($tableData, [$product->getStoreId()], $result);
        }
    }

    /**
     * @param array<int, int> $productIds
     * @param Product $product
     * @return void
     */
    protected function updateUnAssign($productIds, $product)
    {
        $connection = $this->resourceConnection->getConnection();
        $cond = [
            'product_id IN (?)' => $productIds,
            'yotpo_id != 0'
        ];
        if ($product->getStoreId() != 0) {
            $cond ['store_id = ?'] = $product->getStoreId();
        }

        $data = [
            'yotpo_id_unassign' => new \Zend_Db_Expr('yotpo_id'),
            'yotpo_id' => '0',
            'response_code' => Config::CUSTOM_RESPONSE_DATA
        ];

        $connection->update(
            $this->resourceConnection->getTableName('yotpo_product_sync'),
            $data,
            $cond
        );
    }

    /**
     * @param array<mixed> $data
     * @param array<mixed> $storeIds
     * @param array<mixed> $productIds
     * @return void
     */
    public function updateYotpoSyncTable($data, $storeIds, $productIds)
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName('yotpo_product_sync'),
            $data,
            ['store_id IN (?)' => $storeIds, 'product_id IN (?) ' => $productIds]
        );
    }

    /**
     * Check if it is multi dimensional array
     *
     * @param array<int, mixed> $arrays
     * @return mixed
     */
    protected function checkIfMultiDimensional($arrays)
    {
        $isMulti = false;
        $returnArray = [];
        foreach ($arrays as $array) {
            if (is_array($array)) {
                $isMulti = true;
            }
        }

        if ($isMulti) {
            foreach ($arrays as $array) {
                foreach ($array as $arr) {
                    $returnArray[] = $arr;
                }
            }
        } else {
            $returnArray = $arrays;
        }

        return $returnArray;
    }

    /**
     * @param $product
     * @return void
     */
    private function unassignProductChildrensForSync($product)
    {
        $productChildrenIdsBeforeSave = $this->catalogSession->getChildrenIds();
        $productChildrenIds = $product->getTypeInstance()->getChildrenIds($product->getId());
        $this->manageUnAssign($productChildrenIdsBeforeSave, $productChildrenIds, $product);
        $this->catalogSession->unsChildrenIds();
    }

    /**
     * @param array $productCategoriesBeforeSave
     * @param array $productCategories
     * @param string $storeId
     * @param string $productId
     * @return void
     */
    private function assignProductCategoriesForCollectionsProductsSync($productCategoriesBeforeSave, $productCategories, $storeId, $productId)
    {
        $categoriesIdsForSync = $this->findAndRetrieveDifferenceBetweenArrays($productCategories, $productCategoriesBeforeSave);
        $this->collectionsProductsService->assignProductCategoriesForCollectionsProductsSync($categoriesIdsForSync, $storeId, $productId);
    }

    /**
     * @param array $productCategoriesBeforeSave
     * @param array $productCategories
     * @param string $storeId
     * @param string $productId
     * @return void
     */
    private function assignDeletedProductCategoriesForCollectionsProductsSync($productCategoriesBeforeSave, $productCategories, $storeId, $productId)
    {
        $categoriesIdsForSync = $this->findAndRetrieveDifferenceBetweenArrays($productCategoriesBeforeSave, $productCategories);
        $this->collectionsProductsService->assignProductCategoriesForCollectionsProductsSync($categoriesIdsForSync, $storeId, $productId, true);
    }

    /**
     * @param array<int, int>|int $firstArray
     * @param array<int, int>|int $secondArray
     * @return array<int, int>
     */
    private function findAndRetrieveDifferenceBetweenArrays($firstArray, $secondArray)
    {
        $result = [];
        if (is_array($firstArray) && is_array($secondArray)) {
            $result = array_diff($firstArray, $secondArray);
        }

        return $result;
    }
}
