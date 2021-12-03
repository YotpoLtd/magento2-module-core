<?php
namespace Yotpo\Core\Observer\Product;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\ResourceConnection;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\Data\Main;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Session as CatalogSession;
use Magento\Store\Api\StoreRepositoryInterface;

/**
 * Class SaveAfter - Update yotpo attribute value when product updated
 */
class SaveAfter implements ObserverInterface
{
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

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
     * SaveAfter constructor.
     * @param StoreManagerInterface $storeManager
     * @param ResourceConnection $resourceConnection
     * @param Main $main
     * @param CatalogSession $catalogSession
     * @param StoreRepositoryInterface $storeRepository
     * @param Config $config
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        ResourceConnection $resourceConnection,
        Main $main,
        CatalogSession $catalogSession,
        StoreRepositoryInterface $storeRepository,
        Config $config
    ) {
        $this->storeManager = $storeManager;
        $this->resourceConnection = $resourceConnection;
        $this->main = $main;
        $this->catalogSession = $catalogSession;
        $this->storeRepository = $storeRepository;
        $this->config = $config;
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
        $storeIds = [];
        $product = $observer->getEvent()->getProduct();
        $currentStoreId = $product->getStoreId();
        if ($currentStoreId == 0) {
            $stores = $this->storeRepository->getList();
            foreach ($stores as $store) {
                $storeIds[] = $store->getId();
            }
        }
        $storeIdsToUpdate  = $currentStoreId == 0 ? $storeIds : [$product->getStoreId()];
        if ($product->hasDataChanges()) {
            $this->updateProductAttribute([$product->getRowId() ?: $product->getId()], $storeIdsToUpdate);
            $this->updateIsDeleted($product);
            $tableData = ['response_code' => Config::CUSTOM_RESPONSE_DATA];
            $this->updateYotpoSyncTable($tableData, $storeIds, [$product->getId()]);
        }

        $oldChildIds = $this->catalogSession->getChildrenIds();
        $newChildIds = $product->getTypeInstance()->getChildrenIds($product->getId());
        $this->manageUnAssign($oldChildIds, $newChildIds, $product);
        $this->catalogSession->unsChildrenIds();
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

        $removedWebsite = $this->findDifferentArray($existingIds, $newIds);
        if (count($removedWebsite) > 0) {
            $storeIds = $this->collectStoreIds($removedWebsite);
            $tableData = [
                'is_deleted' => 1,
                'is_deleted_at_yotpo' => 0,
                'response_code' => Config::CUSTOM_RESPONSE_DATA
            ];
            $this->updateYotpoSyncTable($tableData, $storeIds, [$product->getId()]);
        }

        $newWebsite = $this->findDifferentArray($newIds, $existingIds);
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
     * @param array<int, int> $oldChild
     * @param array<int, int> $newChild
     * @param Product $product
     * @return void
     */
    protected function manageUnAssign($oldChild, $newChild, $product)
    {
        if (isset($oldChild[0])) {
            $oldChild = (array)$oldChild[0];
        }
        if (isset($newChild[0])) {
            $newChild = (array)$newChild[0];
        }

        $oldChild = $this->checkIfMultiDimensional($oldChild);
        $newChild = $this->checkIfMultiDimensional($newChild);

        $result = array_merge(array_diff($oldChild, $newChild), array_diff($newChild, $oldChild));
        if (count($result) > 0) {
            $this->updateUnAssign($result, $product);
        }

        $result = $this->findDifferentArray($newChild, $oldChild);
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
     * @param array<int, int>|int $firstArray
     * @param array<int, int>|int $secondArray
     * @return array<int, int>
     */
    public function findDifferentArray($firstArray, $secondArray)
    {
        $result = [];
        if (is_array($firstArray) && is_array($secondArray)) {
            $result = array_diff($firstArray, $secondArray);
        }
        return $result;
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
}
