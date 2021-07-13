<?php

namespace Yotpo\Core\Model\Sync\Category;

use Yotpo\Core\Helper\Data as CoreHelper;
use Yotpo\Core\Model\Sync\Data\Main;
use Magento\Framework\App\ResourceConnection;

/**
 * Class Data - Prepare data for checkout sync
 */
class Data extends Main
{
    /**
     * @var CoreHelper
     */
    protected $coreHelper;

    /**
     * Data constructor.
     * @param CoreHelper $coreHelper
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        CoreHelper $coreHelper,
        ResourceConnection $resourceConnection
    ) {
        $this->coreHelper = $coreHelper;
        parent::__construct($resourceConnection);
    }

    /**
     * @param \Magento\Catalog\Model\Category $category
     * @return array[]
     */
    public function prepareData(\Magento\Catalog\Model\Category $category)
    {
        return [
            'collection' => [
                'external_id' => $category->getId(),
                'name' => $category->getData('nameWithPath') ?: $category->getName()
            ]
        ];
    }

    /**
     * @param int $productId
     * @return array[]
     */
    public function prepareProductData(int $productId): array
    {
        return [
            'product' => [
                'external_id' => $productId
            ]
        ];
    }
}
