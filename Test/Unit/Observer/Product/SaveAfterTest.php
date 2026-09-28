<?php

namespace Yotpo\Core\Test\Unit\Observer\Product;

use Magento\Catalog\Model\Session as CatalogSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\CollectionsProducts\Services\CollectionsProductsService;
use Yotpo\Core\Model\Sync\Data\Main;
use Yotpo\Core\Observer\Product\SaveAfter;
use Yotpo\Core\Services\CatalogCategoryProductService;

/**
 * A store view with no row of its own in catalog_product_entity_int falls back to the store 0
 * flag value. Only an All Store Views save (store id 0) may reset that store 0 row - a single
 * store view save must keep touching only its own store id, since Magento already copies the
 * fallback value into the store view row on save.
 */
class SaveAfterTest extends TestCase
{
    /**
     * @param Config $config
     * @param CatalogCategoryProductService $catalogCategoryProductService
     * @param array<mixed> $capturedUpdates set to a list of ['table' => ..., 'bind' => ..., 'where' => ...]
     * @return SaveAfter
     */
    private function createObserver($config, $catalogCategoryProductService, &$capturedUpdates = [])
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);

        $connection = $this->createStub(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('update')->willReturnCallback(
            function ($table, $bind, $where) use (&$capturedUpdates) {
                $capturedUpdates[] = ['table' => $table, 'bind' => $bind, 'where' => $where];
                return 1;
            }
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $main = $this->createStub(Main::class);
        $main->method('getAttributeId')->willReturn('123');

        // getChildrenIds()/unsChildrenIds()/getProductCategoriesIds() aren't real declared
        // methods on Session - they're resolved through SessionManager's magic __call - so
        // neither createMock() nor addMethods() (removed in PHPUnit 10) can configure them.
        // A small subclass stands in instead.
        $catalogSession = new class extends CatalogSession {
            public function __construct()
            {
            }

            public function getChildrenIds()
            {
                return [];
            }

            public function unsChildrenIds()
            {
            }

            public function getProductCategoriesIds()
            {
                return [];
            }
        };

        $storeRepository = $this->createStub(StoreRepositoryInterface::class);
        $storeOne = $this->createStub(StoreInterface::class);
        $storeOne->method('getId')->willReturn(1);
        $storeTwo = $this->createStub(StoreInterface::class);
        $storeTwo->method('getId')->willReturn(2);
        $storeRepository->method('getList')->willReturn([$storeOne, $storeTwo]);

        $collectionsProductsService = $this->createStub(CollectionsProductsService::class);

        return new SaveAfter(
            $storeManager,
            $resourceConnection,
            $main,
            $catalogSession,
            $storeRepository,
            $config,
            $collectionsProductsService,
            $catalogCategoryProductService
        );
    }

    /**
     * $observer->getEvent()->getProduct() is untyped, so a plain stub stands in for the
     * real Magento\Catalog\Model\Product - which relies on DataObject's magic __call for
     * several of these getters (getRowId in particular) and so can't be reliably mocked
     * with PHPUnit's createMock().
     *
     * @param int $storeId
     * @param int $productId
     * @param bool $hasDataChanges
     * @return object
     */
    private function createProduct($storeId, $productId, $hasDataChanges)
    {
        return new class ($storeId, $productId, $hasDataChanges) {
            private $storeId;
            private $productId;
            private $hasDataChanges;

            public function __construct($storeId, $productId, $hasDataChanges)
            {
                $this->storeId = $storeId;
                $this->productId = $productId;
                $this->hasDataChanges = $hasDataChanges;
            }

            public function getId()
            {
                return $this->productId;
            }

            public function getStoreId()
            {
                return $this->storeId;
            }

            public function getRowId()
            {
                return null;
            }

            public function hasDataChanges()
            {
                return $this->hasDataChanges;
            }

            public function getOrigData($key = '')
            {
                return [1];
            }

            public function getData($key = '')
            {
                return [1];
            }

            public function getTypeInstance()
            {
                return new class {
                    public function getChildrenIds($productId, $skipSaleableCheck = null)
                    {
                        return [];
                    }
                };
            }
        };
    }

    /**
     * @param object $product
     * @return EventObserver
     */
    private function createObserverEvent($product)
    {
        $event = new Event(['product' => $product]);
        return new EventObserver(['event' => $event]);
    }

    /**
     * @param array<mixed> $capturedUpdates
     * @return array<mixed>|null
     */
    private function findFlagResetUpdate($capturedUpdates)
    {
        foreach ($capturedUpdates as $update) {
            if ($update['table'] === 'catalog_product_entity_int') {
                return $update;
            }
        }
        return null;
    }

    public function testAllStoreViewsSaveResetsFlagWithNoStoreFilter(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCatalogSyncActive')->willReturn(false);
        $catalogCategoryProductService = $this->createStub(CatalogCategoryProductService::class);
        $catalogCategoryProductService->method('getCategoryIdsFromCategoryProductsTableByProductId')
            ->willReturn([]);

        $capturedUpdates = [];
        $observer = $this->createObserver($config, $catalogCategoryProductService, $capturedUpdates);
        $product = $this->createProduct(0, 10, true);

        $observer->execute($this->createObserverEvent($product));

        $flagUpdate = $this->findFlagResetUpdate($capturedUpdates);
        $this->assertNotNull($flagUpdate, 'the store 0 flag reset update must run');
        $this->assertArrayNotHasKey(
            'store_id IN (?) ',
            $flagUpdate['where'],
            'an All Store Views save must not filter by store, so the store 0 row is reset too'
        );
    }

    public function testStoreViewSaveResetsFlagOnlyForItsOwnStore(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCatalogSyncActive')->willReturn(false);
        $catalogCategoryProductService = $this->createStub(CatalogCategoryProductService::class);
        $catalogCategoryProductService->method('getCategoryIdsFromCategoryProductsTableByProductId')
            ->willReturn([]);

        $capturedUpdates = [];
        $observer = $this->createObserver($config, $catalogCategoryProductService, $capturedUpdates);
        $product = $this->createProduct(5, 10, true);

        $observer->execute($this->createObserverEvent($product));

        $flagUpdate = $this->findFlagResetUpdate($capturedUpdates);
        $this->assertNotNull($flagUpdate, 'the flag reset update must run');
        $this->assertArrayHasKey(
            'store_id IN (?) ',
            $flagUpdate['where'],
            'a single store view save must still be filtered to its own store'
        );
        $this->assertSame([5], $flagUpdate['where']['store_id IN (?) ']);
    }

    public function testNoDataChangesSkipsTheFlagResetEntirely(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCatalogSyncActive')->willReturn(false);
        $catalogCategoryProductService = $this->createStub(CatalogCategoryProductService::class);
        $catalogCategoryProductService->method('getCategoryIdsFromCategoryProductsTableByProductId')
            ->willReturn([]);

        $capturedUpdates = [];
        $observer = $this->createObserver($config, $catalogCategoryProductService, $capturedUpdates);
        $product = $this->createProduct(0, 10, false);

        $observer->execute($this->createObserverEvent($product));

        $this->assertNull(
            $this->findFlagResetUpdate($capturedUpdates),
            'without data changes, the flag must not be touched'
        );
    }
}
