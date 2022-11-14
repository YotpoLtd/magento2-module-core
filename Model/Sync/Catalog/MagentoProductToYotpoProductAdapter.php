<?php

namespace Yotpo\Core\Model\Sync\Catalog;

use Magento\Framework\UrlInterface;
use Magento\Catalog\Model\Product;
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

    const ENTITY_ID_ATTRIBUTE_NAME = 'entity_id';

    const PRODUCT_NAME_ATTRIBUTE_NAME = 'name';

    const SKU_ATTRIBUTE_NAME = 'sku';

    const DESCRIPTION_ATTRIBUTE_NAME = 'description';

    const SHORT_DESCRIPTION_ATTRIBUTE_NAME = 'short_description';

    const BRAND_CONFIG_KEY = 'attr_brand';

    const MPN_CONFIG_KEY = 'attr_mpn';

    const UPC_CONFIG_KEY = 'attr_upc';

    const ISBN_CONFIG_KEY = 'attr_isbn';

    const EAN_CONFIG_KEY = 'attr_ean';

    const GROUP_NAME_CONFIG_KEY = 'attr_product_group';

    const BLOCKLISTED_CONFIG_KEY = 'attr_blocklist';

    const CRF_CONFIG_KEY = 'attr_crf';

    const GTIN_DECLARED_TYPE_KEY = 'declared_type';

    const GTIN_VALUE_KEY = 'value';

    /**
     * @var StockRegistry
     */
    protected $stockRegistry;

    /**
     * @var YotpoCoreConfig
     */
    protected $yotpoCoreConfig;

    /**
     * @param StockRegistry $stockRegistry
     * @param YotpoCoreConfig $yotpoCoreConfig
     */
    public function __construct(
        StockRegistry $stockRegistry,
        YotpoCoreConfig $yotpoCoreConfig
    ) {
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
        $yotpoProduct['external_id'] = $item->getData(self::ENTITY_ID_ATTRIBUTE_NAME);
        $yotpoProduct['name'] = $item->getData(self::PRODUCT_NAME_ATTRIBUTE_NAME);
        $yotpoProduct['description'] = $this->getDescription($item);
        $yotpoProduct['url'] = $item->getProductUrl();
        $yotpoProduct['image_url'] = $this->getImageUrl($item);
        $yotpoProduct['price'] = $this->getPrice($item);
        $yotpoProduct['currency'] = $this->yotpoCoreConfig->getCurrentCurrency();
        $yotpoProduct['inventory_quantity'] = $this->getInventoryQuantity($item);
        $yotpoProduct['is_discontinued'] = false;
        $yotpoProduct['group_name'] = $this->getGroupName($item);
        $yotpoProduct['brand'] = $this->getAttributeValueForItemByConfigKey($item, self::BRAND_CONFIG_KEY);
        $yotpoProduct['sku'] = $item->getData(self::SKU_ATTRIBUTE_NAME);
        $yotpoProduct['mpn'] = $this->getAttributeValueForItemByConfigKey($item, self::MPN_CONFIG_KEY);
        $yotpoProduct['handle'] = $item->getData(self::SKU_ATTRIBUTE_NAME);

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
     * @return string
     */
    private function getDescription(Product $item) {
        $description = $item->getData(self::DESCRIPTION_ATTRIBUTE_NAME);
        if ($description) {
            return trim($description);
        }

        $shortDescription = $item->getData(self::SHORT_DESCRIPTION_ATTRIBUTE_NAME);
        if ($shortDescription) {
            return trim($shortDescription);
        }

        return null;
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
        $productPrice = $item->getPrice() ?: 0.00;
        return $productPrice ?: 0.00;
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
        $groupName = $this->getAttributeValueForItemByConfigKey($item, self::GROUP_NAME_CONFIG_KEY);
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
     * @return array
     */
    private function getGtins(Product $item) {
        $gtins = [];

        foreach (self::GTIN_TYPE_TO_GETTER_METHOD_NAME_MAP as $gtinType => $getterMethodName) {
            $gtinValue = $this->$getterMethodName($item);
            if ($gtinValue && $gtinValue !== 'NULL') {
                $gtins[] = [
                    self::GTIN_DECLARED_TYPE_KEY => $gtinType,
                    self::GTIN_VALUE_KEY => $gtinValue
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
        return $this->getAttributeValueForItemByConfigKey($item, self::EAN_CONFIG_KEY);
    }

    /**
     * @param Product $item
     * @return mixed|string|null
     */
    private function getUpc(Product $item) {
        return $this->getAttributeValueForItemByConfigKey($item, self::UPC_CONFIG_KEY);
    }

    /**
     * @param Product $item
     * @return mixed|string|null
     */
    private function getIsbn(Product $item) {
        return $this->getAttributeValueForItemByConfigKey($item, self::ISBN_CONFIG_KEY);
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
        $isBlocklisted = $this->getAttributeValueForItemByConfigKey($item, self::BLOCKLISTED_CONFIG_KEY);
        return $isBlocklisted === 1 || $isBlocklisted == 'Yes' || $isBlocklisted === true;
    }

    /**
     * @param Product $item
     * @return false|string
     */
    private function getReviewFormTag(Product $item) {
        $reviewFormTag = $this->getAttributeValueForItemByConfigKey($item, self::CRF_CONFIG_KEY);
        if ($reviewFormTag === null) {
            return '';
        }

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
