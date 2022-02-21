<?php

namespace Yotpo\Core\Model\Sync\Catalog;

use Magento\CatalogInventory\Model\StockRegistry;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Yotpo\Core\Model\Config as YotpoCoreConfig;
use Magento\Framework\UrlInterface;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;
use Magento\Catalog\Model\ProductRepository;
use Yotpo\Core\Model\Sync\Data\Main;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

/**
 * Class Data - Prepare data for product sync
 */
class Data extends Main
{
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
    protected $mappingAttributes = [
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
     * Data constructor.
     * @param YotpoCoreConfig $yotpoCoreConfig
     * @param YotpoResource $yotpoResource
     * @param Configurable $resourceConfigurable
     * @param ProductRepository $productRepository
     * @param ResourceConnection $resourceConnection
     * @param CollectionFactory $collectionFactory
     * @param StockRegistry $stockRegistry
     */
    public function __construct(
        YotpoCoreConfig $yotpoCoreConfig,
        YotpoResource $yotpoResource,
        Configurable $resourceConfigurable,
        ProductRepository $productRepository,
        ResourceConnection $resourceConnection,
        CollectionFactory $collectionFactory,
        StockRegistry $stockRegistry
    ) {
        $this->yotpoCoreConfig = $yotpoCoreConfig;
        $this->yotpoResource = $yotpoResource;
        $this->resourceConfigurable = $resourceConfigurable;
        $this->productRepository = $productRepository;
        $this->collectionFactory = $collectionFactory;
        $this->stockRegistry = $stockRegistry;
        $this->mappingAttributes['row_id']['attr_code'] = $this->yotpoCoreConfig->getEavRowIdFieldName();
        parent::__construct($resourceConnection);
    }

    /**
     * @param array <mixed> $items
     * @param boolean $includeVariantsData
     * @return array <mixed>
     * @throws NoSuchEntityException
     */
    public function getSyncItemsData($items, $includeVariantsData)
    {
        $return = [
            'sync_data' => [],
            'parent_ids' => [],
            'parent_data' => []
        ];
        $syncItems = $productsId = $productsObject = [];
        foreach ($items as $item) {
            $entityId = $item->getData('entity_id');
            $productsId[] = $entityId;
            $productsObject[$entityId] = $item;
            $syncItems[$entityId] = $this->attributeMapping($item);
        }
        $visibleVariantsData = [];
        $parentIds = [];
        if ($includeVariantsData) {
            $configIds = $this->yotpoResource->getConfigProductIds($productsId);
            $syncItems = $this->mergeProductOptions($syncItems, $configIds, $productsObject);
            $groupIds = $this->yotpoResource->getGroupProductIds($productsId);
            $parentIds = $configIds + $groupIds;
            foreach ($parentIds as $simpleId => $parentId) {
                $simpleProductObj = $productsObject[$simpleId];
                if ($simpleProductObj->isVisibleInSiteVisibility()) {
                    $visibleVariantsData[$simpleProductObj->getId()] = $simpleProductObj;
                }
            }
        }
        $return['sync_data'] = $syncItems;
        $return['parent_ids'] = $parentIds;
        $yotpoData = $this->fetchYotpoData($productsId, $parentIds);
        $return['yotpo_data'] = $yotpoData['yotpo_data'];
        $return['parent_data'] = $yotpoData['parent_data'];
        $return['visible_variants'] = $visibleVariantsData;
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
                $this->getChildOptions($item);
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
            $configOptions = [];
            if (isset($this->parentOptions[$id])
                && $options = $this->parentOptions[$id]) {
                foreach ($options as $attribute_code => $option) {
                    $configOptions[] = [
                        'name' => $option['label'],
                        'value' => $option[$productObjects[$key]->getData($attribute_code)]
                    ];
                }
                $syncItems[$key]['options'] = $configOptions;
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
    protected function attributeMapping(Product $item)
    {
        $itemArray = [];
        $mapAttributes = $this->mappingAttributes;

        foreach ($mapAttributes as $key => $attr) {
            if ($key === 'gtins') {
                $value = $this->prepareGtinsData($attr, $item);
            } elseif ($key === 'custom_properties') {
                $value = $this->prepareCustomProperties($attr, $item);
            } elseif ($key === 'is_discontinued') {
                $value = false;
            } else {
                if ($attr['default']) {
                    $data = $item->getData($attr['attr_code']);

                    if (isset($attr['type']) && $attr['type'] === 'url') {
                        $data = $item->getProductUrl();
                    }

                    if (isset($attr['type']) && $attr['type'] === 'image') {
                        $baseUrl = $this->yotpoCoreConfig->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
                        $data = $data ? $baseUrl . 'catalog/product' . $data : "";
                    }
                    $value = $data;
                } elseif (isset($attr['method']) && $attr['method']) {

                    $configKey = isset($attr['attr_code']) && $attr['attr_code'] ?
                        $attr['attr_code'] : '';

                    $method = $attr['method'];
                    $itemValue = $this->$method($item, $configKey);
                    $value = $itemValue ?: ($method == 'getProductPrice' ? 0.00 : $itemValue);
                } else {
                    $value = '';
                }
                if ($key == 'group_name' && $value) {
                    $value = strtolower($value);
                    $value = str_replace(' ', '_', $value);
                    $value = preg_replace('/[^A-Za-z0-9_-]/', '-', $value);
                    $value = substr((string)$value, 0, 100);
                }
            }

            $itemArray[$key] = $value;
            if (($key == 'custom_properties' || $key == 'gtins') && !$value) {
                unset($itemArray[$key]);
            }
        }

        return $itemArray;
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
}
