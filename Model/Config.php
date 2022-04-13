<?php
namespace Yotpo\Core\Model;

use Magento\Eav\Model\Entity;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Website;

/**
 * Class Config - Manage common configuration settings
 */
class Config
{
    const CATALOG_SYNC_ATTR_CODE = 'synced_to_yotpo_product';
    const CATEGORY_SYNC_ATTR_CODE = 'synced_to_yotpo_collection';

    const UPDATE_SQL_LIMIT = 50000;

    const YOTPO_RETRY_ATTEMPTS_AMOUNT = 3;

    const MODULE_NAME = 'Yotpo_Core';

    /**
     * API method types
     */
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const METHOD_PATCH = 'PATCH';
    const METHOD_DELETE = 'DELETE';
    const CUSTOM_RESPONSE_DATA = '000';
    const SUCCESS_RESPONSE_CODE = '200';
    const CREATED_STATUS_CODE = '201';
    const BAD_REQUEST_RESPONSE_CODE = 400;
    const RESPONSE_CODE_FOR_UNKNOWN_ERRORS = 500;
    const NOT_FOUND_RESPONSE_CODE = 404;
    const CONFLICT_RESPONSE_CODE = 409;
    const YOTPO_SYNC_RESPONSE_IS_SUCCESS_KEY = 'is_success';

    /**
     * @var mixed[]
     */
    protected $successfulResponseCodes = [200,201,204,222,'000'];

    /**
     * @var array<int, string>
     */
    protected $storeCode = [];

    /**
     * @var string[]
     */
    protected $productSyncMethods = [
        'createProduct' => 'createProduct',
        'updateProduct' => 'updateProduct',
        'createProductVariant' => 'createProductVariant',
        'updateProductVariant' => 'updateProductVariant',
        'deleteProduct' => 'deleteProduct',
        'deleteProductVariant' => 'deleteProductVariant',
        'unassignProduct' => 'unassignProduct',
        'unassignProductVariant' => 'unassignProductVariant'
    ];

    /**
     * @var string[]
     */
    protected $endPoints = [
        'updateProduct'             => 'products/{yotpo_product_id}',
        'variant'                   => 'products/{yotpo_product_id}/variants',
        'updateVariant'             => 'products/{yotpo_product_id}/variants/{yotpo_variant_id}',
        'collections'               =>  'collections',
        'collections_product'       =>  'collections/{yotpo_collection_id}/products',
        'collections_for_product'   =>  'products/{yotpo_product_id}/collections',
        'products'                  =>  'products',
        'collections_update'        =>  'collections/{yotpo_collection_id}',
        'orders'                    =>  'orders',
        'orders_update'             =>  'orders/{yotpo_order_id}',
        'metadata'                  =>  'account_platform/update_metadata'
    ];

    /**
     * @var mixed[]
     */
    protected $config = [
        'apiV1' => ['path' => 'yotpo_core/env/yotpo_api_v1_url'],
        'api' => ['path' => 'yotpo_core/env/yotpo_api_url'],
        'api_messaging' => ['path' => 'yotpo_core/env/yotpo_api_url_messaging'],
        'api_url_access_tokens' => ['path' => 'yotpo_core/env/yotpo_api_url_access_tokens'],
        'app_key' => ['path' => 'yotpo/settings/app_key'],
        'secret' => ['path' => 'yotpo/settings/secret','encrypted' => true],
        'auth_token' => ['path' => 'yotpo_core/settings/auth_token','encrypted' => true, 'read_from_db' => true],
        'debug_mode_active' => ['path' => 'yotpo/settings/debug_mode_active'],
        'product_api_start' => ['path' => 'yotpo_core/sync_settings/catalog_sync/settings_catalog/product_api_start'],
        'product_api_end' => ['path' => 'yotpo_core/sync_settings/catalog_sync/settings_catalog/product_api_end'],
        'product_sync_limit' => ['path' => 'yotpo_core/sync_settings/catalog_sync/product_sync_limit'],
        'yotpo_active' => ['path' => 'yotpo_core/settings/active'],
        'attr_mpn' => ['path' => 'yotpo_core/sync_settings/catalog_sync/settings_catalog/attr_mpn'],
        'attr_brand' => ['path' => 'yotpo_core/sync_settings/catalog_sync/settings_catalog/attr_brand'],
        'attr_blocklist' => ['path' => 'yotpo_core/sync_settings/catalog_sync/settings_catalog/attr_blocklist'],
        'attr_crf' => ['path' => 'yotpo_core/sync_settings/catalog_sync/settings_catalog/attr_crf'],
        'attr_product_group' => ['path' => 'yotpo_core/sync_settings/catalog_sync/settings_catalog/attr_product_group'],
        'attr_ean' => ['path' => 'yotpo_core/sync_settings/catalog_sync/settings_catalog/attr_ean'],
        'attr_upc' => ['path' => 'yotpo_core/sync_settings/catalog_sync/settings_catalog/attr_upc'],
        'attr_isbn' => ['path' => 'yotpo_core/sync_settings/catalog_sync/settings_catalog/attr_isbn'],
        'catalog_last_sync_time' => ['path' => 'yotpo_core/sync_settings/catalog_sync/last_sync_time'],
        'catalog_sync_frequency' => ['path' => 'yotpo_core/sync_settings/catalog_sync/frequency'],
        'orders_sync_frequency' => ['path' => 'yotpo_core/sync_settings/orders_sync/frequency'],
        'catalog_sync_enable' => ['path' => 'yotpo_core/sync_settings/catalog_sync/enable'],
        'sync_limit_collections' => ['path' => 'yotpo_core/sync_settings/catalog_sync/sync_limit_collections'],
        'orders_sync_active' => ['path' => 'yotpo_core/sync_settings/orders_sync/enable'],
        'orders_realtime_sync_active' => ['path' => 'yotpo_core/sync_settings/orders_sync/enable_real_time_sync'],
        'orders_sync_limit' => ['path' => 'yotpo_core/sync_settings/orders_sync/orders_sync_limit'],
        'orders_last_sync_time' => ['path' => 'yotpo_core/sync_settings/orders_sync/last_sync_time'],
        'orders_sync_time_limit' => ['path' => 'yotpo_core/sync_settings/orders_sync/sync_orders_since'],
        'orders_total_synced' => ['path' => 'yotpo_core/sync_settings/orders_sync/total_orders_synced',
            'read_from_db' => true],
        'orders_mapped_status' =>
            [
                'path' => 'yotpo_core/sync_settings/orders_sync/order_status/map_order_status'
            ],
        'orders_shipment_status' =>
            ['path' =>
                'yotpo_core/sync_settings/orders_sync/shipments/map_shipment_status'
            ],
        'is_fulfillment_based_on_shipment' =>
            ['path' =>
                'yotpo_core/sync_settings/orders_sync/shipments/shipments_flag'
            ],
        'last_reset_orders_sync_time' => ['path' => 'yotpo_core/sync_settings/orders_sync/last_reset_orders_sync_time',
            'read_from_db' => true],
        'sms_marketing_custom_attribute' =>
            [
                'path' => 'yotpo_core/widget_settings/marketing_settings/attr_customer'
            ],
        'reset_sync_in_progress_catalog' =>
            [
                'path' => 'yotpo_core/sync_settings/reset_sync/reset_sync_in_progress_catalog'
            ],
        'reset_sync_in_progress_order' =>
            [
                'path' => 'yotpo_core/sync_settings/reset_sync/reset_sync_in_progress_order'
            ],
        'reset_sync_in_progress_customer' =>
            [
                'path' => 'yotpo_core/sync_settings/reset_sync/reset_sync_in_progress_customer'
            ]
    ];

    /**
     * @var array<mixed>
     */
    protected $responseCodes = [
        'success' => ['200']
    ];

    /**
     * @var null[]
     */
    private $allStoreIds = [0 => null, 1 => null];

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var ModuleListInterface
     */
    protected $moduleList;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var WriterInterface
     */
    protected $configWriter;

    /**
     * @var ConfigResource
     */
    protected $configResource;

    /**
     * @var ProductMetadataInterface
     */
    protected $productMetadata;

    /**
     * @var Entity
     */
    protected $entity;

    /**
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param ModuleListInterface $moduleList
     * @param EncryptorInterface $encryptor
     * @param WriterInterface $configWriter
     * @param ConfigResource $configResource
     * @param ProductMetadataInterface $productMetadata
     * @param Entity $entity
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        ModuleListInterface $moduleList,
        EncryptorInterface $encryptor,
        WriterInterface $configWriter,
        ConfigResource $configResource,
        ProductMetadataInterface $productMetadata,
        Entity $entity
    ) {
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->moduleList = $moduleList;
        $this->encryptor = $encryptor;
        $this->configWriter = $configWriter;
        $this->configResource = $configResource;
        $this->productMetadata = $productMetadata;
        $this->entity = $entity;
    }

    /**
     * @param string $key
     * @param int|null $scopeId
     * @param string $scope
     * @return mixed|string
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function getConfig(string $key, int $scopeId = null, string $scope = ScopeInterface::SCOPE_STORE)
    {
        $config='';
        if (isset($this->config[$key]['path'])) {
            $configPath = $this->config[$key]['path'];
            if ($scopeId === null) {
                $scopeId = $this->storeManager->getStore()->getId();
            }
            if (isset($this->config[$key]['read_from_db'])) {
                $config = $this->getConfigFromDb($configPath, $scope, $scopeId);
            } else {
                $config = $this->scopeConfig->getValue($configPath, $scope, $scopeId);
            }

            if (isset($this->config[$key]['encrypted']) && $this->config[$key]['encrypted'] === true && $config) {
                $config = $this->encryptor->decrypt($config);
            }
        }
        return $config;
    }

    /**
     * @param string $key
     * @return array<string>
     */
    public function getResponseCode(string $key)
    {
        return $this->responseCodes[$key];
    }

    /**
     * Find the installed module version
     *
     * @return mixed
     */
    public function getModuleVersion()
    {
        $module = $this->moduleList->getOne(self::MODULE_NAME);
        return $module ? $module ['setup_version'] : null;
    }

    /**
     * @param string $key
     * @param string $value
     * @param string|int|null $scopeId
     * @param string|null $scope
     * @throws NoSuchEntityException
     * @return void
     */
    public function saveConfig($key, $value, $scopeId = null, $scope = null)
    {
        $configPath = $this->config[$key]['path'];
        $scope  = $scope?: ScopeInterface::SCOPE_STORES;
        $scopeId = $scopeId === null ? $this->storeManager->getStore()->getId() : (int)$scopeId;
        if (isset($this->config[$key]['encrypted']) && $this->config[$key]['encrypted'] === true && $value) {
            $value = $this->encryptor->encrypt($value);
        }
        $this->configWriter->save($configPath, $value, $scope, $scopeId);
    }

    /**
     * @param string $key
     * @param int|null $scopeId
     * @param string $scope
     * @throws NoSuchEntityException
     * @return void
     */
    public function deleteConfig($key, $scope = null, $scopeId = null)
    {
        $configPath = $this->config[$key]['path'];
        $scope  = $scope?: ScopeInterface::SCOPE_STORES;
        $scopeId = $scopeId === null ? $this->storeManager->getStore()->getId() : (int)$scopeId;

        $this->configWriter->delete($configPath, $scope, $scopeId);
    }

    /**
     * @param string $path
     * @param string $scope
     * @param int $scopeId
     * @return string
     * @throws LocalizedException
     */
    public function getConfigFromDb(string $path, string $scope = ScopeInterface::SCOPE_STORES, int $scopeId = 0)
    {
        if ($scope == ScopeInterface::SCOPE_STORE) {
            $scope = ScopeInterface::SCOPE_STORES;
        }
        $connection = $this->configResource->getConnection();
        if (!$connection) {
            return '';
        }
        $select = $connection->select()->from(
            $this->configResource->getMainTable(),
            ['value']
        )->where(
            'path = ?',
            $path
        )->where(
            'scope = ?',
            $scope
        )->where(
            'scope_id = ?',
            $scopeId
        );
        return $connection->fetchOne($select);
    }

    /**
     * Get All Active store Ids
     *
     * @param bool $withDefault
     * @param bool $onlyActive
     * @return array<mixed>
     */
    public function getAllStoreIds(bool $withDefault = false, bool $onlyActive = true): ?array
    {
        $cacheKey = ($withDefault) ? 1 : 0;
        if ($this->allStoreIds[$cacheKey] === null) {
            /** @phpstan-ignore-next-line */
            $this->allStoreIds[$cacheKey] = [];
            foreach ($this->storeManager->getStores($withDefault) as $store) {
                /** @var Store $store */
                if ($onlyActive && !$store->isActive()) {
                    continue;
                }
                $this->allStoreIds[$cacheKey][] = $store->getId();
            }
        }
        return $this->allStoreIds[$cacheKey];
    }

    /**
     * Check if Yotpo module is enabled
     *
     * @param int|null $scopeId
     * @param string $scope
     * @return boolean
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function isEnabled($scopeId = null, string $scope = ScopeInterface::SCOPE_STORE): bool
    {
        return (bool)$this->getConfig('yotpo_active', $scopeId, $scope);
    }

    /**
     * Get store identifier
     *
     * @return  int
     * @throws NoSuchEntityException
     */
    public function getStoreId(): int
    {
        return $this->storeManager->getStore()->getId();
    }

    /**
     * Get website identifier
     *
     * @return  int
     * @throws NoSuchEntityException
     */
    public function getWebsiteId(): int
    {
        return $this->storeManager->getStore()->getWebsiteId();
    }

    /**
     * @param string $type
     * @return string
     * @throws NoSuchEntityException
     */
    public function getBaseUrl(string $type = UrlInterface::URL_TYPE_WEB): string
    {
        return $this->storeManager->getStore()->getBaseUrl($type);
    }

    /**
     * @return string
     * @throws NoSuchEntityException
     */
    public function getCurrentCurrency(): string
    {
        /** @phpstan-ignore-next-line */
        return $this->storeManager->getStore()->getCurrentCurrencyCode();
    }

    /**
     * @param string $key
     * @param array<mixed> $search
     * @param array<mixed> $repl
     * @return string
     */
    public function getEndpoint(string $key, array $search = [], array $repl = []): string
    {
        return str_ireplace($search, $repl, $this->endPoints[$key]);
    }

    /**
     * @param string $yotpoCollectonId
     * @return array|string|string[]
     */
    public function getCollectionsAddProductEndpoint(string $yotpoCollectonId = '')
    {
        $endPoint = $this->getEndpoint('collections_add_product');
        return str_ireplace('{yotpo_collection_id}', $yotpoCollectonId, $endPoint);
    }

    /**
     * @param string $responseCode
     * @param array <mixed> $yotpoId
     * @param bool $isRetrySync
     * @return bool
     */
    public function canResync($responseCode = '', $yotpoId = [], $isRetrySync = false): bool
    {
        if ($isRetrySync) {
            return true;
        }
        if (!$yotpoId) {
            $yotpoId = ['yotpo_id' => ''];
        } elseif (!is_array($yotpoId)) {
            $yotpoId = ['yotpo_id' => $yotpoId];
        }
        if ($yotpoId['yotpo_id'] && $responseCode == '404') {
            return false;
        }
        if (!$responseCode) {
            return true;
        }
        if ($responseCode === '000') {
            return true;
        }
        if ($responseCode < 400) {
            return true;
        }
        if ($this->isNetworkRetriableResponse($responseCode)) {
            return true;
        }
        return false;
    }

    /**
     * @param string $responseCode
     * @return bool
     */
    public function isNetworkRetriableResponse(string $responseCode): bool
    {
        return $responseCode == 429 || ($responseCode >= 500 && $responseCode <= 599);
    }

    /**
     * @param string $responseCode
     * @return bool
     */
    public function canUpdateCustomAttribute($responseCode = ''): bool
    {
        return ($responseCode
                && in_array($responseCode, $this->successfulResponseCodes))
            || !$this->canResync($responseCode);
    }

    /**
     * @param string $responseCode
     * @return bool
     */
    public function canUpdateCustomAttributeForProducts($responseCode = ''): bool
    {
        return ($responseCode && in_array($responseCode, $this->successfulResponseCodes))
            || !($this->canResync($responseCode) || $responseCode == self::CONFLICT_RESPONSE_CODE);
    }

    /**
     * Get default website ID
     *
     * @return int
     */
    public function getDefaultWebsiteId(): int
    {
        $websiteId = 0;
        $storeView = $this->storeManager->getDefaultStoreView();
        if ($storeView) {
            $websiteId = $storeView->getWebsiteId();
        }
        return $websiteId;
    }

    /**
     * Get default store ID from website
     *
     * @param int $websiteId
     * @return int
     * @throws LocalizedException
     */
    public function getDefaultStoreId(int $websiteId): int
    {
        $storeId = 0;
        /** @var Website $website */
        $website = $this->storeManager->getWebsite($websiteId);
        if ($website instanceof Website) {
            $storeId = $website->getDefaultStore()->getId();
        }
        return $storeId;
    }

    /**
     * Check if Yotpo is enabled and if order sync is active.
     * @param int|null $storeId
     * @return bool
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function isCatalogSyncActive($storeId = null)
    {
        return ($this->isEnabled($storeId) && $this->getConfig('catalog_sync_enable', $storeId));
    }

    /**
     * Get API method type for product sync
     *
     * @param string $key
     * @return string
     */
    public function getProductSyncMethod(string $key): string
    {
        return $this->productSyncMethods[$key];
    }

    /**
     * Check if Yotpo is enabled and if order sync is active.
     *
     * @param int|null $storeId
     * @return bool
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function isOrdersSyncActive($storeId = null)
    {
        return $this->isEnabled($storeId) && $this->getConfig('orders_sync_active', $storeId);
    }

    /**
     * Check if Yotpo is enabled and if realtime order sync is active.
     *
     * @param int|null $storeId
     * @return bool
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function isRealTimeOrdersSyncActive($storeId = null)
    {
        return $this->isEnabled($storeId) && $this->getConfig('orders_realtime_sync_active', $storeId);
    }

    /**
     * Check if system is run in the single store mode
     *
     * @return bool
     */
    public function isSingleStoreMode()
    {
        return $this->storeManager->isSingleStoreMode();
    }

    /**
     * @param mixed $response
     * @return boolean
     */
    public function isResponseIndicatesSuccess($response)
    {
        if ($response && $response->getData($this::YOTPO_SYNC_RESPONSE_IS_SUCCESS_KEY)) {
            return $response->getData($this::YOTPO_SYNC_RESPONSE_IS_SUCCESS_KEY);
        }

        return false;
    }

    /**
     * get Magento version
     * @return string
     */
    public function getMagentoVersion(): string
    {
        return $this->productMetadata->getVersion();
    }

    /**
     * Get Magento Edition
     * @return string
     */
    public function getMagentoEdition(): string
    {
        return $this->productMetadata->getEdition();
    }

    /**
     * Get Store Code by Store ID
     * @param int $storeId
     * @return string
     * @throws NoSuchEntityException
     */
    public function getStoreName($storeId): string
    {
        if (!array_key_exists($storeId, $this->storeCode)) {
            $this->storeCode[$storeId] = $this->storeManager->getStore($storeId)->getName();
        }
        return $this->storeCode[$storeId];
    }

    /**
     * @return string|null
     */
    public function getEavRowIdFieldName(): ?string
    {
        return $this->entity->setType('catalog_product')->getLinkField();
    }

    /**
     * @return bool
     */
    public function isRowIdAvailable()
    {
        return $this->getEavRowIdFieldName() == 'row_id';
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function getConfigPath(string $key)
    {
        return $this->config[$key]['path'];
    }

    /**
     * @return int
     */
    public function getCustRespCodeMissingProd()
    {
        return 222;
    }

    /**
     * @return int
     */
    public function getUpdateSqlLimit(): int
    {
        return self::UPDATE_SQL_LIMIT;
    }

    /**
     * @param int|null $scopeId
     * @param string $entity
     * @return boolean
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function isSyncResetInProgress($scopeId, $entity)
    {
        return (bool)$this->getConfig('reset_sync_in_progress_' . $entity, $scopeId);
    }

    /**
     * @return int
     */
    public function getYotpoRetryAttemptsAmount(): int
    {
        return self::YOTPO_RETRY_ATTEMPTS_AMOUNT;
    }

    /**
     * @return mixed[]
     */
    public function getSuccessfulResponseCodes()
    {
        return $this->successfulResponseCodes;
    }
}
