<?php

namespace Yotpo\Core\Model\Sync\Catalog;

use Magento\Catalog\Helper\Image as CatalogImageHelper;
use Magento\CatalogInventory\Model\StockRegistry;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Yotpo\Core\Model\Config as YotpoCoreConfig;
use Magento\Framework\UrlInterface;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;
use Magento\Catalog\Model\ProductRepository;
use Yotpo\Core\Model\Sync\Catalog\Logger as YotpoCoreCatalogLogger;
use Yotpo\Core\Model\Sync\Data\Main;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

/**
 * Class Data - Prepare data for product sync
 */
class Data extends Main
{
    /**
     * @var CatalogImageHelper
     */
    private $catalogImageHelper;

    /**
     * @var YotpoCoreConfig
     */
    protected $yotpoCoreConfig;

    /**
     * @var YotpoResource
     */
    protected $yotpoResource;

    /**
     * @var Configurable
     */
    protected $resourceConfigurable;

    /**
     * @var ProductRepository
     */
    protected $productRepository;

    /**
     * @var array<int, array>
     */
    protected $parentOptions;

    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * @var array<string, array>
     */
    protected $attributesMapping = [
        'row_id' => [
            'default' => 1,
            'attr_code' => 'row_id'
        ],
        'external_id' => [
            'default' => 1,
            'attr_code' => 'entity_id'
        ],
        'name' => [
            'default' => 1,
            'attr_code' => 'name'
        ],
        'description' => [
            'default' => 0,
            'attr_code' => '',
            'method' => 'getProductDescription'
        ],
        'url' => [
            'default' => 1,
            'attr_code' => 'request_path',
            'type' => 'url'
        ],
        'image_url' => [
            'default' => 1,
            'attr_code' => 'image',
            'type' => 'image'
        ],
        'price' => [
            'default' => 0,
            'attr_code' => '',
            'method' => 'getProductPrice'
        ],
        'currency' => [
            'default' => 0,
            'attr_code' => '',
            'method' => 'getCurrentCurrency'
        ],
        'inventory_quantity' => [
            'default' => 0,
            'attr_code' => '',
            'method' => 'getProductQty'
        ],
        'is_discontinued' => [
            'default' => 0,
            'attr_code' => '',
            'method' => ''
        ],
        'group_name' => [
            'default' => 0,
            'attr_code' => 'attr_product_group',
            'method' => 'getDataFromConfig'
        ],
        'brand' => [
            'default' => 0,
            'attr_code' => 'attr_brand',
            'method' => 'getDataFromConfig'
        ],
        'sku' => [
            'default' => 1,
            'attr_code' => 'sku'
        ],
        'mpn' => [
            'default' => 0,
            'attr_code' => 'attr_mpn',
            'method' => 'getDataFromConfig'
        ],
        'handle' => [
            'default' => 1,
            'attr_code' => 'sku'
        ],
        'gtins' => [
            'EAN' => [
                'default' => 0,
                'attr_code' => 'attr_ean',
                'method' => 'getDataFromConfig'
            ],
            'UPC' => [
                'default' => 0,
                'attr_code' => 'attr_upc',
                'method' => 'getDataFromConfig'
            ],
            'ISBN' => [
                'default' => 0,
                'attr_code' => 'attr_isbn',
                'method' => 'getDataFromConfig'
            ]
        ],
        'custom_properties' => [
            'is_blocklisted' => [
                'default' => 0,
                'attr_code' => 'attr_blocklist',
                'method' => 'getDataFromConfig'
            ],
            'review_form_tag' => [
                'default' => 0,
                'attr_code' => 'attr_crf',
                'method' => 'getDataFromConfig'
            ]
        ]
    ];

    /**
     * @var StockRegistry
     */
    protected $stockRegistry;

    /**
     * @var YotpoCoreCatalogLogger
     */
    protected $logger;

    /**
     * Data constructor.
     * @param CatalogImageHelper $catalogImageHelper
     * @param YotpoCoreConfig $yotpoCoreConfig
     * @param YotpoResource $yotpoResource
     * @param Configurable $resourceConfigurable
     * @param ProductRepository $productRepository
     * @param ResourceConnection $resourceConnection
     * @param CollectionFactory $collectionFactory
     * @param StockRegistry $stockRegistry
     * @param YotpoCoreCatalogLogger $yotpoCatalogLogger
     */
    public function __construct(
        CatalogImageHelper $catalogImageHelper,
        YotpoCoreConfig $yotpoCoreConfig,
        YotpoResource $yotpoResource,
        Configurable $resourceConfigurable,
        ProductRepository $productRepository,
        ResourceConnection $resourceConnection,
        CollectionFactory $collectionFactory,
        StockRegistry $stockRegistry,
        YotpoCoreCatalogLogger $yotpoCatalogLogger
    ) {
        $this->catalogImageHelper = $catalogImageHelper;
        $this->yotpoCoreConfig = $yotpoCoreConfig;
        $this->yotpoResource = $yotpoResource;
        $this->resourceConfigurable = $resourceConfigurable;
        $this->productRepository = $productRepository;
        $this->collectionFactory = $collectionFactory;
        $this->stockRegistry = $stockRegistry;
        $this->attributesMapping['row_id']['attr_code'] = $this->yotpoCoreConfig->getEavRowIdFieldName();
        $this->logger = $yotpoCatalogLogger;
        parent::__construct($resourceConnection);
    }

    /**
     * @param array <mixed> $items
     * @param boolean $isVariantsDataIncluded
     * @return array <mixed>
     * @throws NoSuchEntityException
     */
    public function getSyncItems($items, $isVariantsDataIncluded)
    {
        $return = [
            'sync_data' => [],
            'parents_ids' => []
        ];
        $syncItems = $productsId = $productsObject = [];
        foreach ($items as $item) {
            $entityId = $item->getData('entity_id');
            $productsId[] = $entityId;
            $productsObject[$entityId] = $item;
            $syncItems[$entityId] = $this->attributeMapping($item);
        }
        $visibleVariantsData = [];
        $productIdsToParentIdsMap = [];
        $failedVariantsIds = [];
        if (!$isVariantsDataIncluded) {
            $productIdsToConfigurableIdsMapToCheck = $this->yotpoResource->getConfigProductIds($productsId, $failedVariantsIds);
            foreach ($failedVariantsIds as $failedVariantId) {
                unset($productsId[array_search($failedVariantId, $productsId)]);
                unset($productsObject[$failedVariantId]);
                unset($syncItems[$failedVariantId]);
            }

            $productIdsToConfigurableIdsMap = $this->filterNotConfigurableProducts(
                $productIdsToConfigurableIdsMapToCheck
            );
            $syncItems = $this->mergeProductOptions($syncItems, $productIdsToConfigurableIdsMap, $productsObject);
            $productIdsToGroupIdsMap = $this->yotpoResource->getGroupProductIds($productsId);
            $productIdsToParentIdsMap = $productIdsToConfigurableIdsMap + $productIdsToGroupIdsMap;
            foreach ($productIdsToParentIdsMap as $simpleId => $parentId) {
                $simpleProductObj = $productsObject[$simpleId];
                if ($simpleProductObj->isVisibleInSiteVisibility()) {
                    $visibleVariantsData[$simpleProductObj->getId()] = $simpleProductObj;
                }
            }
        }
        $return['sync_data'] = $syncItems;
        $return['parents_ids'] = $productIdsToParentIdsMap;
        $yotpoData = $this->fetchYotpoData($productsId, $productIdsToParentIdsMap);
        $return['yotpo_data'] = $yotpoData['yotpo_data'];
        $return['visible_variants'] = $visibleVariantsData;
        $return['failed_variants_ids'] = $failedVariantsIds;
        return $return;
    }

    /**
     * Fetch yotpo data from yotpo_product_sync
     * @param array<int, int> $productsId
     * @param array<int|string, int|string> $parentIds
     * @return array<int|string, mixed>
     * @throws NoSuchEntityException
     */
    protected function fetchYotpoData(array $productsId, array $parentIds): array
    {
        return $this->yotpoResource->fetchYotpoData($productsId, $parentIds);
    }

    /**
     * @param array<int|string, array<string, array|string>> $syncItems
     * @param array<int|string, int|string> $configIds
     * @param array<int|string, mixed> $productObjects
     * @return array<int|string, mixed>
     */
    protected function mergeProductOptions($syncItems, $configIds, $productObjects)
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect('*');
        $collection->addAttributeToFilter('entity_id', ['in' => $configIds]);
        if ($collection->getSize()) {
            foreach ($collection->getItems() as $item) {
                try {
                    $this->getChildOptions($item);
                } catch (\Exception $e) {
                    $this->logger->info('error in mergeProductOptions() :  ' . $e->getMessage(), []);
                }
            }
        }
        return $this->prepareOptions($syncItems, $configIds, $productObjects);
    }

    /**
     * Get Configurable options for child product
     *
     * @param mixed $parentProduct
     * @return void
     */
    protected function getChildOptions($parentProduct)
    {
        if (!isset($this->parentOptions[$parentProduct->getId()])) {
            $options = $parentProduct->getTypeInstance()->getConfigurableAttributesAsArray($parentProduct);
            $this->parentOptions[$parentProduct->getId()] = $this->arrangeConfigOptions($options);
        }
    }

    /**
     * Group configurable attribute options for product
     *
     * @param array<int, array> $options
     * @return array<int|string, array<int|string, mixed>>
     */
    protected function arrangeConfigOptions($options)
    {
        $attributeOptions = [];
        foreach ($options as $productAttribute) {
            foreach ($productAttribute['values'] as $attribute) {
                $attributeOptions[$productAttribute['attribute_code']][$attribute['value_index']]
                    = $attribute['store_label'];
            }
            $attributeOptions[$productAttribute['attribute_code']]['label'] = $productAttribute['store_label'];
        }
        return $attributeOptions;
    }

    /**
     * Prepare options for child product from parent options
     *
     * @param array<int|string, mixed> $syncItems
     * @param array<int|string, int|string> $configIds
     * @param array<int|string, mixed> $productObjects
     * @return array<int|string, mixed>
     */
    protected function prepareOptions($syncItems, $configIds, $productObjects)
    {
        foreach ($configIds as $key => $id) {
            try {
                $configOptions = [];
                if (isset($this->parentOptions[$id])
                    && $options = $this->parentOptions[$id]) {
                    foreach ($options as $attribute_code => $option) {
                        $simpleProductAttributeCode = $productObjects[$key]->getData($attribute_code);
                        if ($simpleProductAttributeCode === null) {
                            continue;
                        }

                        $configOptions[] = [
                            'name' => $option['label'],
                            'value' => $option[$simpleProductAttributeCode]
                        ];
                    }
                    $syncItems[$key]['options'] = $configOptions;
                }
            } catch (\Exception $e) {
                $this->logger->info(
                    __(
                        'Exception raised within prepareOptions - $key: %1, $id: %2 Exception Message: %3',
                        $key,
                        $id,
                        $e->getMessage()
                    )
                );
            }
        }

        return $syncItems;
    }

    /**
     * Mapping the yotpo data with magento data - product sync
     *
     * @param Product $item
     * @return array<string, string|array>
     * @throws NoSuchEntityException
     */
    public function attributeMapping(Product $item)
    {
        $itemAttributesData = [];

        foreach ($this->attributesMapping as $attributeKey => $attributeDetails) {
            try {
                $attributeDataValue = '';
                $attributeDetailsAttributeCode = '';

                switch ($attributeKey) {
                    case 'gtins':
                        $attributeDataValue = $this->prepareGtinsData($attributeDetails, $item);
                        break;
                    case 'custom_properties':
                        $attributeDataValue = $this->prepareCustomProperties($attributeDetails, $item);
                        break;
                    case 'is_discontinued':
                        $attributeDataValue = false;
                        break;
                    case 'url':
                        $attributeDataValue = $item->getProductUrl();
                        break;
                    case 'image_url':
                        $attributeDataValue = $this->getProductImageUrl($item);
                        break;
                    case 'group_name':
                        $itemValue = $this->getAttributeDetailsMethodValue($attributeDetails, $attributeDetailsAttributeCode, $item);

                        if ($itemValue) {
                            $attributeDataValue = substr((string)$itemValue, 0, 100);
                            $attributeDataValue = strtolower($attributeDataValue);
                            $attributeDataValue = str_replace(' ', '_', $attributeDataValue);
                            $attributeDataValue = preg_replace('/[^A-Za-z0-9_-]/', '-', $attributeDataValue);
                        }
                        break;
                    default:
                        $itemValue = $this->getAttributeDetailsMethodValue($attributeDetails, $attributeDetailsAttributeCode, $item);
                        if (!$attributeDetails['default'] && isset($attributeDetails['method']) && $attributeDetails['method']) {
                            $attributeDetailsMethod = $attributeDetails['method'];
                            if ($itemValue) {
                                $attributeDataValue = $itemValue;
                            } elseif ($attributeDetailsMethod === 'getProductPrice') {
                                $attributeDataValue = 0.00;
                            } else {
                                $attributeDataValue = $itemValue;
                            }
                        }
                }

                $itemAttributesData[$attributeKey] = $attributeDataValue;
                if (($attributeKey === 'custom_properties' || $attributeKey === 'gtins') && !$attributeDataValue) {
                    unset($itemAttributesData[$attributeKey]);
                }
            } catch (\Exception $e) {
                $this->logger->info(
                    __(
                        'Exception raised within attributeMapping - $key: %1, $attr: %2 Exception Message: %3',
                        $attributeKey,
                        $attributeDetails,
                        $e->getMessage()
                    )
                );
            }
        }

        return $itemAttributesData;
    }

    /**
     * Get Current Currency
     *
     * @return string
     * @throws NoSuchEntityException
     */
    public function getCurrentCurrency()
    {
        return $this->yotpoCoreConfig->getCurrentCurrency();
    }

    /**
     * Get product quantity
     * @param Product $item
     * @return float
     * @throws NoSuchEntityException
     */
    public function getProductQty($item)
    {
        return $this->stockRegistry->getStockItem($item->getId(), $this->yotpoCoreConfig->getWebsiteId())->getQty();
    }

    /**
     * Get value from config table - dynamically
     * @param Product $item
     * @param string $configKey
     * @return mixed|string|null
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getDataFromConfig($item, $configKey = '')
    {
        $configValue = $this->yotpoCoreConfig->getConfig($configKey) ?: '';
        if ($configValue) {
            $value = $item->getAttributeText($configValue) ?: '';
            if (!$value) {
                $value = $item->getData($configValue) ?: '';
            }
        } else {
            return null;
        }
        return $value ?: '';
    }

    /**
     * Get GTINs data from config table
     *
     * @param array<int, array> $array
     * @param Product $item
     * @return array<int, array>
     */
    protected function prepareGtinsData($array, $item)
    {
        $resultArray = [];
        foreach ($array as $key => $value) {
            $configKey = isset($value['attr_code']) && $value['attr_code'] ?
                $value['attr_code'] : '';
            $method = $value['method'];
            $value = $this->$method($item, $configKey);

            if ($value && $value !== 'NULL') {
                $resultArray[] = [
                    'declared_type' => $key,
                    'value' => $value
                ];
            }
        }
        return $resultArray;
    }

    /**
     * Prepare custom attributes for product sync
     *
     * @param array<string, array> $array
     * @param Product $item
     * @return array<string, array>
     */
    protected function prepareCustomProperties($array, $item)
    {
        $resultArray = [];
        foreach ($array as $key => $value) {
            $configKey = isset($value['attr_code']) && $value['attr_code'] ?
                $value['attr_code'] : '';

            $method = $value['method'];
            $itemValue = $this->$method($item, $configKey);
            if ($key === 'is_blocklisted' || $key === 'review_form_tag') {
                $configValue = $this->yotpoCoreConfig->getConfig($configKey) ?: '';
                if ($configValue) {
                    if ($key === 'is_blocklisted') {
                        $resultArray[$key] = $itemValue === 1 || $itemValue == 'Yes' || $itemValue === true;
                    } elseif ($key === 'review_form_tag') {
                        $itemValue = str_replace(',', '_', $itemValue);
                        $itemValue = substr($itemValue, 0, 255);
                        $resultArray[$key] = $itemValue ?: '';
                    }
                }
            } else {
                $resultArray[$key] = $itemValue;
            }
        }
        return $resultArray;
    }

    /**
     * Get product price
     * @param Product $item
     * @return float
     */
    public function getProductPrice($item)
    {
        return $item->getPrice() ?: 0.00;
    }

    /**
     * Filter only parent products
     * @param array<int, mixed> $sqlData
     * @return array<int, mixed>
     */
    public function filterDataForCatSync($sqlData)
    {
        $result = [];
        foreach ($sqlData as $data) {
            if (!(isset($data['yotpo_id_parent']) && $data['yotpo_id_parent'])) {
                $result[] = $data;
            }
        }

        return $result;
    }

    /**
     * Get product description
     * @param Product $item
     * @return string
     */
    public function getProductDescription(Product $item)
    {
        return trim($item->getData('description')) ?:
            trim($item->getData('short_description'));
    }

    /**
     * @param array<string, mixed> $yotpoItemData
     * @return array<string, integer>
     */
    public function getMinimalProductRequestData($yotpoItemData)
    {
        $externalId = $yotpoItemData['external_id'];
        return ['external_id' => $externalId];
    }

    /**
     * @param array<mixed> $productIds
     * @return array<mixed>
     */
    private function filterNotConfigurableProducts($productIds)
    {
        if (!$productIds) {
            return [];
        }

        $filteredProductIds = $productIds;
        $configurableProductTypeCode = \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE;

        $productCollection = $this->collectionFactory->create();
        $productCollection->addAttributeToSelect('*');
        $productCollection->addAttributeToFilter('entity_id', ['in' => $filteredProductIds]);
        $products = $productCollection->getItems();

        foreach ($products as $product) {
            if ($product->getTypeId() != $configurableProductTypeCode) {
                $keysToDeleteFromMap = array_keys($filteredProductIds, $product->getId());
                foreach ($keysToDeleteFromMap as $keyToDeleteFromMap) {
                    $this->logger->info(
                        __(
                            'A non-configurable product is being filtered - Key: %1, Product ID: %2',
                            $keyToDeleteFromMap,
                            $productIds[$keyToDeleteFromMap]
                        )
                    );
                    unset($filteredProductIds[$keyToDeleteFromMap]);
                }
            }
        }

        return $filteredProductIds;
    }

    /**
     * Get Product Image from product object
     *
     * @param Product $product
     * @param string  $imageId
     * @return string|null
     */
    private function getProductImageUrl($product, $imageId = 'product_page_image_large')
    {
        return $this->catalogImageHelper->init($product, $imageId)->getUrl();
    }

    /**
     * @param mixed $attributeDetails
     * @param mixed $attributeDetailsAttributeCode
     * @param Product $item
     * @return array
     */
    private function getAttributeDetailsMethodValue($attributeDetails, $attributeDetailsAttributeCode, Product $item)
    {
        $attributeDetailsMethod = $attributeDetails['method'];
        if (isset($attributeDetails['attr_code']) && $attributeDetails['attr_code']) {
            $attributeDetailsAttributeCode = $attributeDetails['attr_code'];
        }
        return $this->$attributeDetailsMethod($item, $attributeDetailsAttributeCode);
    }
}
