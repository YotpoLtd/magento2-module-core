<?php

namespace Yotpo\Core\Services;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Yotpo\Core\Model\AbstractJobs;

/**
 * Class CatalogCategoryProductService - Used for read operations on catalog_category_product table
 */
class CatalogCategoryProductService extends AbstractJobs
{

    const CATALOG_CATEGORY_PRODUCT_TABLE = 'catalog_category_product';

    /**
     * @var AppEmulation
     */
    protected $appEmulation;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * Data constructor.
     * @param ResourceConnection $resourceConnection
     * @param AppEmulation $appEmulation
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        AppEmulation $appEmulation
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->appEmulation = $appEmulation;
        parent::__construct($appEmulation, $resourceConnection);
    }

    /**
     * @param string $productId
     * @return array<int>
     */
    public function getCategoryIdsFromCategoryProductsTableByProductId($productId)
    {
        $connection = $this->resourceConnection->getConnection();
        $categoryProductsQuery = $connection->select()->from(
            [ $this->resourceConnection->getTableName($this::CATALOG_CATEGORY_PRODUCT_TABLE) ],
            [ 'category_id' ]
        )->where(
            'product_id = ?',
            $productId
        );

        $productCategoriesIdsMap = $connection->fetchAssoc($categoryProductsQuery);
        return array_keys($productCategoriesIdsMap);
    }

    /**
     * @param string $categoryId
     * @return array<int>
     */
    public function getProductIdsFromCategoryProductsTableByCategoryId($categoryId)
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from(
            $this->resourceConnection->getTableName($this::CATALOG_CATEGORY_PRODUCT_TABLE),
            [ 'product_id' ]
        )->where(
            'category_id = ?',
            $categoryId
        );

        $currentProductsInCategory = $connection->fetchAssoc($select);
        return array_keys($currentProductsInCategory);
    }
}
