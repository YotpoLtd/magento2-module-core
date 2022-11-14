<?php

namespace Yotpo\Core\Model\Sync\Catalog;

use Magento\CatalogInventory\Model\StockRegistry;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Yotpo\Core\Model\Config as YotpoCoreConfig;
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
     * @var MagentoProductToYotpoProductAdapter
     */
    protected $magentoProductToYotpoProductAdapter;

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
     * @param YotpoCoreConfig $yotpoCoreConfig
     * @param YotpoResource $yotpoResource
     * @param Configurable $resourceConfigurable
     * @param ProductRepository $productRepository
     * @param ResourceConnection $resourceConnection
     * @param CollectionFactory $collectionFactory
     * @param StockRegistry $stockRegistry
     * @param YotpoCoreCatalogLogger $yotpoCatalogLogger
     * @param MagentoProductToYotpoProductAdapter $magentoProductToYotpoProductAdapter
     */
    public function __construct(
        YotpoCoreConfig $yotpoCoreConfig,
        YotpoResource $yotpoResource,
        Configurable $resourceConfigurable,
        ProductRepository $productRepository,
        ResourceConnection $resourceConnection,
        CollectionFactory $collectionFactory,
        StockRegistry $stockRegistry,
        YotpoCoreCatalogLogger $yotpoCatalogLogger,
        MagentoProductToYotpoProductAdapter $magentoProductToYotpoProductAdapter
    ) {
        $this->yotpoCoreConfig = $yotpoCoreConfig;
        $this->yotpoResource = $yotpoResource;
        $this->resourceConfigurable = $resourceConfigurable;
        $this->productRepository = $productRepository;
        $this->collectionFactory = $collectionFactory;
        $this->stockRegistry = $stockRegistry;
        $this->logger = $yotpoCatalogLogger;
        $this->magentoProductToYotpoProductAdapter = $magentoProductToYotpoProductAdapter;
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
            $syncItems[$entityId] = $this->adaptMagentoProductToYotpoProduct($item);
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
                    $this->logger->infoLog('error in mergeProductOptions() :  ' . $e->getMessage(), []);
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
                $this->logger->infoLog(
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

    public function adaptMagentoProductToYotpoProduct(Product $item) {
        return $this->magentoProductToYotpoProductAdapter->adapt($item);
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
                    $this->logger->infoLog(
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
}
