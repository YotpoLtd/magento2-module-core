<?php

namespace Yotpo\Core\Model\Sync\Category;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
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
     * @param Category|Product $entity
     * @return array[]
     */
    public function prepareData($entity)
    {
        return [
            'collection' => [
                'external_id' => $entity->getId(),
                'name' => $entity->getData('nameWithPath') ?: $entity->getName()
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
