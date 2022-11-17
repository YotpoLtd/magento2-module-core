<?php

namespace Yotpo\Core\Model\Sync\Catalog;

use Magento\Framework\UrlInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductRepository;
use Magento\CatalogInventory\Model\StockRegistry;
use Yotpo\Core\Model\Config as YotpoCoreConfig;

/**
 * Class Logger - For customized logging
 */
class MagentoProductToYotpoProductAdapter
{

    const GTIN_TYPE_TO_GETTER_METHOD_NAME_MAP = [
        'EAN' => 'getEan',
        'UPC' => 'getUpc',
        'ISBN' => 'getIsbn'
    ];

    const CUSTOM_ATTRIBUTE_TO_GETTER_METHOD_NAME_MAP = [
        'is_blocklisted' => 'getIsBlocklisted',
        'review_form_tag' => 'getReviewFormTag'
    ];

    const CONFIGURABLE_PRODUCT_CODE = \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE;

    /**
     * @var YotpoCoreConfig
     */
    protected $yotpoCoreConfig;

    /**
     * @var ProductRepository
     */
    protected $productRepository;

    /**
     * @var StockRegistry
     */
    protected $stockRegistry;

    /**
     * @param YotpoCoreConfig $yotpoCoreConfig
     */
    public function __construct(
        ProductRepository $productRepository,
        StockRegistry $stockRegistry,
        YotpoCoreConfig $yotpoCoreConfig
    ) {
        $this->productRepository = $productRepository;
        $this->stockRegistry = $stockRegistry;
        $this->yotpoCoreConfig = $yotpoCoreConfig;
    }

    /**
     * @param Product $item
     * @return array
     */
    public function adapt(Product $item) {
        $yotpoProduct = [];

        $yotpoProduct['row_id'] = $this->getRowId($item);
        $yotpoProduct['external_id'] = $this->getExternalId($item);
        $yotpoProduct['name'] = $this->getName($item);
        $yotpoProduct['description'] = $this->getDescription($item);
        $yotpoProduct['url'] = $this->getUrl($item);
        $yotpoProduct['image_url'] = $this->getImageUrl($item);
        $yotpoProduct['price'] = $this->getPrice($item);
        $yotpoProduct['currency'] = $this->getCurrency($item);
        $yotpoProduct['inventory_quantity'] = $this->getInventoryQuantity($item);
        $yotpoProduct['is_discontinued'] = false;
        $yotpoProduct['group_name'] = $this->getGroupName($item);
        $yotpoProduct['brand'] = $this->getBrand($item);
        $yotpoProduct['sku'] = $this->getSku($item);
        $yotpoProduct['mpn'] = $this->getMpn($item);
        $yotpoProduct['handle'] = $this->getHandle($item);

        $gtins = $this->getGtins($item);
        if ($gtins) {
            $yotpoProduct['gtins'] = $gtins;
        }

        $customProperties = $this->getCustomProperties($item);
        if ($customProperties) {
            $yotpoProduct['custom_properties'] = $customProperties;
        }

        return $yotpoProduct;
    }

    /**
     * @param Product $item
     * @return mixed
     */
    private function getRowId(Product $item) {
        $rowIdFieldName = $this->yotpoCoreConfig->getEavRowIdFieldName();
        return $item->getData($rowIdFieldName);
    }

    /**
     * @param Product $item
     * @return mixed
     */
    private function getExternalId(Product $item) {
        return $item->getData('entity_id');
    }

    /**
     * @param Product $item
     * @return mixed
     */
    private function getName(Product $item) {
        return $item->getData('name');
    }

    /**
     * @param Product $item
     * @return string
     */
    private function getDescription(Product $item) {
        return trim($item->getData('description')) ?: trim($item->getData('short_description'));
    }

    /**
     * @param Product $item
     * @return mixed
     */
    private function getUrl(Product $item) {
        return $item->getProductUrl();
    }

    /**
     * @param Product $item
     * @return string|null
     */
    private function getImageUrl(Product $item) {
        $image = $item->getData('image');
        $baseUrl = $this->yotpoCoreConfig->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        return $image ? $baseUrl . 'catalog/product' . $image : null;
    }

    /**
     * @param Product $item
     * @return float
     */
    private function getPrice(Product $item) {
        if ($item->getTypeId() == self::CONFIGURABLE_PRODUCT_CODE) {
            $itemVariantIds = $item->getTypeInstance()->getChildrenIds($item->getId());
            if (count($itemVariantIds) > 0) {
                $firstVariantId = $itemVariantIds[0];
                $variant = $this->productRepository->getById($firstVariantId);
                return $variant->getPrice() ?: 0.00;
            }
        }

        return $item->getPrice() ?: 0.00;
    }

    /**
     * @param Product $item
     * @return string
     */
    private function getCurrency(Product $item) {
        return $this->yotpoCoreConfig->getCurrentCurrency();
    }

    /**
     * @param Product $item
     * @return mixed
     */
    private function getInventoryQuantity(Product $item) {
        return $this->stockRegistry->getStockItem($item->getId(), $this->yotpoCoreConfig->getWebsiteId())->getQty();
    }

    /**
     * @param Product $item
     * @return false|mixed|string|null
     */
    private function getGroupName(Product $item) {
        $groupName = $this->getAttributeValueForItemByConfigKey($item, 'attr_product_group');
        if ($groupName) {
            $groupName = strtolower($groupName);
            $groupName = str_replace(' ', '_', $groupName);
            $groupName = preg_replace('/[^A-Za-z0-9_-]/', '-', $groupName);
            $groupName = substr((string) $groupName, 0, 100);
        }

        return $groupName;
    }

    /**
     * @param Product $item
     * @return mixed|string|null
     */
    private function getBrand(Product $item) {
        return $this->getAttributeValueForItemByConfigKey($item, 'attr_brand');
    }

    /**
     * @param Product $item
     * @return mixed
     */
    private function getSku(Product $item) {
        return $item->getData('sku');
    }

    /**
     * @param Product $item
     * @return mixed|string|null
     */
    private function getMpn(Product $item) {
        return $this->getAttributeValueForItemByConfigKey($item, 'attr_mpn');
    }

    /**
     * @param Product $item
     * @return mixed
     */
    private function getHandle(Product $item) {
        return $item->getData('sku');
    }

    /**
     * @param Product $item
     * @return array
     */
    private function getGtins(Product $item) {
        $gtins = [];

        foreach (self::GTIN_TYPE_TO_GETTER_METHOD_NAME_MAP as $gtinType => $getterMethodName) {
            $gtinValue = $this->$getterMethodName($item);
            if ($gtinValue && $gtinValue !== 'NULL') {
                $gtins[] = [
                    'declared_type' => $gtinType,
                    'value' => $gtinValue
                ];
            }
        }

        return $gtins;
    }

    /**
     * @param Product $item
     * @return mixed|string|null
     */
    private function getEan(Product $item) {
        return $this->getAttributeValueForItemByConfigKey($item, 'attr_ean');
    }

    /**
     * @param Product $item
     * @return mixed|string|null
     */
    private function getUpc(Product $item) {
        return $this->getAttributeValueForItemByConfigKey($item, 'attr_upc');
    }

    /**
     * @param Product $item
     * @return mixed|string|null
     */
    private function getIsbn(Product $item) {
        return $this->getAttributeValueForItemByConfigKey($item, 'attr_isbn');
    }

    /**
     * @param Product $item
     * @return array
     */
    private function getCustomProperties(Product $item) {
        $customProperties = [];

        foreach (self::CUSTOM_ATTRIBUTE_TO_GETTER_METHOD_NAME_MAP as $customPropertyType => $getterMethodName) {
            $customPropertyValue = $this->$getterMethodName($item);
            if ($customPropertyValue && $customPropertyValue !== 'NULL') {
                $customProperties[$customPropertyType] = $customPropertyValue;
            }
        }

        return $customProperties;
    }

    /**
     * @param Product $item
     * @return bool
     */
    private function getIsBlocklisted(Product $item) {
        $isBlocklisted = $this->getAttributeValueForItemByConfigKey($item, 'attr_blocklist');
        return $isBlocklisted === 1 || $isBlocklisted == 'Yes' || $isBlocklisted === true;
    }

    /**
     * @param Product $item
     * @return false|string
     */
    private function getReviewFormTag(Product $item) {
        $reviewFormTag = $this->getAttributeValueForItemByConfigKey($item, 'attr_crf');
        $reviewFormTag = str_replace(',', '_', $reviewFormTag);
        $reviewFormTag = substr($reviewFormTag, 0, 255);
        return $reviewFormTag ?: '';
    }

    /**
     * @param Product $item
     * @param string $configKey
     * @return mixed|string|null
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function getAttributeValueForItemByConfigKey($item, $configKey)
    {
        $attributeKey = $this->getAttributeKeyByConfigKey($configKey);
        if ($attributeKey) {
            $attributeValue = $item->getAttributeText($attributeKey) ?: $item->getData($attributeKey);
        } else {
            return null;
        }
        return $attributeValue ?: '';
    }

    /**
     * @param string $configKey
     * @return string
     */
    private function getAttributeKeyByConfigKey($configKey) {
        return $this->yotpoCoreConfig->getConfig($configKey) ?: '';
    }
}
