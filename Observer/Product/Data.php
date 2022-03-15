<?php

namespace Yotpo\Core\Observer\Product;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Yotpo\Core\Model\AbstractJobs;

/**
 * Class Data - Product changes related data requests
 */
class Data extends AbstractJobs
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
     * @param string $magentoProductId
     * @return array<string>
     */
    public function getProductCategoriesIdsFromCategoryProductsTable($magentoProductId) {
        $connection = $this->resourceConnection->getConnection();
        $categoryProductsQuery = $connection->select(
        )->from(
            ['entity' => $this->resourceConnection->getTableName($this::CATALOG_CATEGORY_PRODUCT_TABLE)],
            ['category_id']
        )->where(
            'product_id = ?',
            $magentoProductId
        );

        $productCategoriesIdsMap = $connection->fetchAssoc($categoryProductsQuery, 'category_id');

        $productCategoriesIds = [];
        foreach ($productCategoriesIdsMap as $productCategoryIdMap) {
            $productCategoriesIds[] = $productCategoryIdMap['category_id'];
        }

        return $productCategoriesIds;
    }
}
