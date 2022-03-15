<?php

namespace Yotpo\Core\Model\Sync\CollectionsProducts;

use Exception;
use Yotpo\Core\Model\AbstractJobs;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Yotpo\Core\Model\Api\Sync as YotpoCoreSync;
use Yotpo\Core\Model\Config as CoreConfig;
use Yotpo\Core\Model\Sync\Catalog\Logger as CatalogLogger;
use Yotpo\Core\Model\Sync\Catalog\YotpoResource;
use Yotpo\Core\Model\Sync\CollectionsProducts\Services\CollectionsProductsService;
use Yotpo\Core\Model\Sync\Category\Processor\Main as YotpoCategoryProcessorMain;
use Yotpo\Core\Model\Sync\Category\Processor\ProcessByCategory as YotpoCategoryProcessor;
use Yotpo\Core\Model\Sync\Catalog\Processor as YotpoProductProcessor;
use Yotpo\Core\Model\Sync\Catalog\Processor\Main as YotpoProductProcessorMain;

class Processor extends AbstractJobs
{
    const PRODUCT_SYNC_LIMIT_CONFIG_KEY = 'product_sync_limit';
    const COLLECTIONS_PRODUCT_ENDPOINT_STRING = 'collections_product';
    const YOTPO_COLLECTION_ID_REQUEST_PARAM_STRING = '{yotpo_collection_id}';
    const LOGGER_LOG_ENTITY_FILE = 'catalog';

    /**
     * @var CoreConfig
     */
    protected $coreConfig;

    /**
     * @var YotpoCoreSync
     */
    protected $yotpoCoreSync;

    /**
     * @var CatalogLogger
     */
    protected $catalogLogger;

    /**
     * @var YotpoResource
     */
    protected $yotpoResource;

    /**
     * @var YotpoCategoryProcessorMain
     */
    protected $yotpoCategoryProcessorMain;

    /**
     * @var YotpoCategoryProcessor
     */
    protected $yotpoCategoryProcessor;

    /**
     * @var YotpoProductProcessorMain
     */
    protected $yotpoProductProcessorMain;

    /**
     * @var YotpoProductProcessor
     */
    protected $yotpoProductProcessor;

    /**
     * @var CollectionsProductsService
     */
    protected $collectionsProductsService;

    /**
     * @var int
     */
    protected $collectionsProductsSyncBatchSize = null;

    /**
     * Processor constructor.
     * @param AppEmulation $appEmulation
     * @param ResourceConnection $resourceConnection
     * @param CoreConfig $coreConfig
     * @param YotpoCoreSync $yotpoCoreSync
     * @param CatalogLogger $catalogLogger
     * @param YotpoResource $yotpoResource
     * @param YotpoCategoryProcessorMain $yotpoCategoryProcessorMain
     * @param YotpoCategoryProcessor $yotpoCategoryProcessor
     * @param YotpoProductProcessorMain $yotpoProductProcessorMain
     * @param YotpoProductProcessor $yotpoProductProcessor
     * @param CollectionsProductsService $collectionsProductsService
    **/
    public function __construct(
        AppEmulation $appEmulation,
        ResourceConnection $resourceConnection,
        CoreConfig $coreConfig,
        YotpoCoreSync $yotpoCoreSync,
        CatalogLogger $catalogLogger,
        YotpoResource $yotpoResource,
        YotpoCategoryProcessorMain $yotpoCategoryProcessorMain,
        YotpoCategoryProcessor $yotpoCategoryProcessor,
        YotpoProductProcessorMain $yotpoProductProcessorMain,
        YotpoProductProcessor $yotpoProductProcessor,
        CollectionsProductsService $collectionsProductsService
    ) {
        $this->coreConfig = $coreConfig;
        $this->yotpoCoreSync = $yotpoCoreSync;
        $this->catalogLogger = $catalogLogger;
        $this->yotpoResource = $yotpoResource;
        $this->yotpoCategoryProcessorMain = $yotpoCategoryProcessorMain;
        $this->yotpoCategoryProcessor = $yotpoCategoryProcessor;
        $this->yotpoProductProcessorMain = $yotpoProductProcessorMain;
        $this->yotpoProductProcessor = $yotpoProductProcessor;
        $this->collectionsProductsService = $collectionsProductsService;
        $this->collectionsProductsSyncBatchSize = $this->coreConfig->getConfig($this::PRODUCT_SYNC_LIMIT_CONFIG_KEY);
        parent::__construct($appEmulation, $resourceConnection);
    }

    public function process()
    {
        $storeIdsList = (array) $this->coreConfig->getAllStoreIds();

        foreach ($storeIdsList as $storeId) {
            try {
                $this->emulateFrontendArea($storeId);
                if (!$this->shouldSyncCollectionsProducts()) {
                    $this->catalogLogger->info(
                        __(
                            'Collections Products Sync - Sync for store is disabled - Magento Store ID: %1, Name: %2',
                            $storeId,
                            $this->coreConfig->getStoreName($storeId)
                        )
                    );

                    continue;
                }

                $this->catalogLogger->info(
                    __(
                        'Collections Products Sync - Starting sync for store - Magento Store ID: %1, Name: %2',
                        $storeId,
                        $this->coreConfig->getStoreName($storeId)
                    )
                );

                $collectionsProductsEntitiesToSync = $this->collectionsProductsService->getCollectionsProductsToSync($this->collectionsProductsSyncBatchSize);
                if (!$collectionsProductsEntitiesToSync) {
                    $this->logFinishedCollectionsProductsSync($storeId);
                    continue;
                }

                $requestEndpointKey = $this::COLLECTIONS_PRODUCT_ENDPOINT_STRING;
                $collectionIdRequestParamString = $this::YOTPO_COLLECTION_ID_REQUEST_PARAM_STRING;
                $enrichedCollectionsProductsEntitiesToSync = $this->enrichCollectionsProductsDataWithYotpoIds($collectionsProductsEntitiesToSync);

                foreach ($enrichedCollectionsProductsEntitiesToSync as $enrichedCollectionProductEntityToSync) {
                    $collectionYotpoId = $enrichedCollectionProductEntityToSync['category_yotpo_id'];
                    $productYotpoId = $enrichedCollectionProductEntityToSync['product_yotpo_id'];

                    if (!$collectionYotpoId) {
                        $magentoCategoryId = $enrichedCollectionProductEntityToSync['magento_category_id'];
                        $collectionYotpoId = $this->syncCollectionAndGetCreatedYotpoId($magentoCategoryId, $storeId);
                        if ($collectionYotpoId == null) {
                            $this->collectionsProductsService->updateCollectionsProductsSyncData($storeId, $enrichedCollectionProductEntityToSync);
                            continue;
                        }
                    }

                    if (!$productYotpoId) {
                        $magentoProductId = $enrichedCollectionProductEntityToSync['magento_product_id'];
                        $productYotpoId = $this->syncProductAndGetCreatedYotpoId($magentoProductId, $storeId);
                        if ($productYotpoId == null) {
                            $this->collectionsProductsService->updateCollectionsProductsSyncData($storeId, $enrichedCollectionProductEntityToSync);
                            continue;
                        }
                    }

                    $requestEndpoint = $this->coreConfig->getEndpoint($requestEndpointKey, [$collectionIdRequestParamString], [$collectionYotpoId]);
                    $collectionProductDataToSync = $this->prepareCollectionProductToSync($enrichedCollectionProductEntityToSync);
                    $collectionProductDataToSync['entityLog'] = $this::LOGGER_LOG_ENTITY_FILE;
                    $isCollectionProductDeletedInMagento = (bool) $enrichedCollectionProductEntityToSync['is_deleted_in_magento'];

                    $response = $this->syncCollectionProductToYotpo($requestEndpoint, $collectionProductDataToSync, $isCollectionProductDeletedInMagento);
                    if ($response->getData('status') == CoreConfig::CONFLICT_RESPONSE_CODE) {
                        $this->setAsSuccessfulCollectionProductSync($storeId, $enrichedCollectionProductEntityToSync);
                        continue;
                    }

                    $isResyncableStatusCode = $this->coreConfig->canResync($response->getData('status'));
                    if ($response->getData('is_success')) {
                        $this->setAsSuccessfulCollectionProductSync($storeId, $enrichedCollectionProductEntityToSync);
                    } elseif (!$isResyncableStatusCode) {
                        $this->setAsSuccessfulCollectionProductSync($storeId, $enrichedCollectionProductEntityToSync);
                    }
                }

                $this->logFinishedCollectionsProductsSync($storeId);
            } catch (Exception $exception) {
                $this->catalogLogger->info(
                    __(
                        'Collections Products Sync - Stopped sync for store - Magento Store ID: %1, Name: %2, Exception: %3',
                        $storeId,
                        $this->coreConfig->getStoreName($storeId),
                        $exception->getMessage()
                    )
                );
            } finally {
                $this->stopEnvironmentEmulation();
            }
        }
    }

    /**
     * @return bool
     */
    private function shouldSyncCollectionsProducts()
    {
        return $this->coreConfig->isEnabled() && $this->coreConfig->isCatalogSyncActive();
    }

    /**
     * @param array $collectionProductEntityToSync
     * @return array
     */
    private function prepareCollectionProductToSync($collectionProductEntityToSync)
    {
        return [
            'product' => [
                'external_id' => $collectionProductEntityToSync['magento_product_id']
            ]
        ];
    }

    /**
     * @param string $requestEndpoint
     * @param array $collectionProductDataToSync
     * @param boolean $isCollectionProductIsDeletedInMagento
     * @return mixed
     */
    private function syncCollectionProductToYotpo($requestEndpoint, array $collectionProductDataToSync, $isCollectionProductIsDeletedInMagento)
    {
        if ($isCollectionProductIsDeletedInMagento) {
            $response = $this->yotpoCoreSync->sync($this->coreConfig::METHOD_DELETE, $requestEndpoint, $collectionProductDataToSync);
        } else {
            $response = $this->yotpoCoreSync->sync($this->coreConfig::METHOD_POST, $requestEndpoint, $collectionProductDataToSync);
        }

        return $response;
    }

    /**
     * @param string $magentoCategoryId
     * @param string $storeId
     * @return string|null
     */
    private function syncCollectionAndGetCreatedYotpoId($magentoCategoryId, $storeId)
    {
        $this->yotpoCategoryProcessor->processEntities($magentoCategoryId, $storeId);
        $collectionYotpoId = $this->yotpoCategoryProcessorMain->getYotpoIdFromCategoriesSyncTableByCategoryId($magentoCategoryId);
        if (!$collectionYotpoId) {
            return null;
        }

        return $collectionYotpoId;
    }

    /**
     * @param string $magentoProductId
     * @param string $storeId
     * @return string|null
     */
    private function syncProductAndGetCreatedYotpoId($magentoProductId, $storeId)
    {
        $this->yotpoProductProcessorMain->setNormalSyncFlag(false);
        $unSyncedProductIds = [$storeId => [$magentoProductId]];
        $this->yotpoProductProcessor->process($unSyncedProductIds, [$storeId]);
        $productYotpoId = $this->yotpoProductProcessorMain->getYotpoIdFromProductsSyncTableByProductId($magentoProductId);
        if (!$productYotpoId) {
            return null;
        }

        return $productYotpoId;
    }

    /**
     * @param string $storeId
     * @param array $collectionProductEntityToSync
     * @return void
     */
    private function setAsSuccessfulCollectionProductSync($storeId, $collectionProductEntityToSync)
    {
        $this->collectionsProductsService->updateCollectionsProductsSyncData($storeId, $collectionProductEntityToSync);

        $this->catalogLogger->info(
            __(
                'Collections Products Sync - Synced Collection Product to Yotpo successfully - Magento Store ID: %1, Name: %2, Category ID: %3, Product ID: %4',
                $storeId,
                $this->coreConfig->getStoreName($storeId),
                $collectionProductEntityToSync['magento_category_id'],
                $collectionProductEntityToSync['magento_product_id']
            )
        );
    }

    /**
     * @param string $storeId
     * @return void
     */
    private function logFinishedCollectionsProductsSync($storeId)
    {
        $this->catalogLogger->info(
            __(
                'Collections Products Sync - Finished sync for store - Magento Store ID: %1, Name: %2',
                $storeId,
                $this->coreConfig->getStoreName($storeId)
            )
        );
    }

    /**
     * @param array $collectionsProductsEntitiesToSync
     * @return array
     */
    private function enrichCollectionsProductsDataWithYotpoIds(array $collectionsProductsEntitiesToSync)
    {
        $collectionsProductsCategoriesIds = [];
        $collectionsProductsProductIds = [];

        foreach ($collectionsProductsEntitiesToSync as $collectionProductEntityToSync) {
            $magentoCategoryId = $collectionProductEntityToSync['magento_category_id'];
            $magentoProductId = $collectionProductEntityToSync['magento_product_id'];
            if (!isset($collectionsProductsCategoriesIds[$magentoCategoryId])) {
                $collectionsProductsCategoriesIds[] = $magentoCategoryId;
            }

            if (!isset($collectionsProductsCategoriesIds[$magentoProductId])) {
                $collectionsProductsProductIds[] = $magentoProductId;
            }
        }

        $collectionsProductsCategoriesIdsToYotpoIdsMap = $this->yotpoCategoryProcessorMain->getYotpoIdFromCategoriesSyncTableByCategoryIds($collectionsProductsCategoriesIds);
        $collectionsProductsProductIdToYotpoIdsMap = $this->yotpoProductProcessorMain->getYotpoIdFromProductsSyncTableByProductIds($collectionsProductsProductIds);

        $encirchedCollectionsProductsEntitiesToSync = [];
        foreach ($collectionsProductsEntitiesToSync as $collectionProductEntityToSync) {
            $magentoCategoryId = $collectionProductEntityToSync['magento_category_id'];
            $collectionProductEntityToSync['category_yotpo_id'] = $collectionsProductsCategoriesIdsToYotpoIdsMap[$magentoCategoryId] ?? null;

            $magentoProductId = $collectionProductEntityToSync['magento_product_id'];
            $collectionProductEntityToSync['product_yotpo_id'] = $collectionsProductsProductIdToYotpoIdsMap[$magentoProductId] ?? null;

            $encirchedCollectionsProductsEntitiesToSync[] = $collectionProductEntityToSync;
        }

        return $encirchedCollectionsProductsEntitiesToSync;
    }
}
