<?php
declare(strict_types=1);


namespace Yotpo\Core\Model;

use Magento\Framework\DataObject;
use Yotpo\Core\Api\ProductSyncRepositoryInterface;
use Yotpo\Core\Model\ResourceModel\ProductSync as ResourceModel;
use Yotpo\Core\Model\ResourceModel\ProductSync\CollectionFactory as ProductSyncCollectionFactory;

/**
 * Class ProductSyncRepository - Manage products sync resource
 */
class ProductSyncRepository implements ProductSyncRepositoryInterface
{
    /**
     * @var ResourceModel
     */
    protected $resource;

    /**
     * @var ProductSyncFactory
     */
    protected $modelFactory;

    /**
     * @var ProductSyncCollectionFactory
     */
    protected $productSyncCollectionFactory;

    /**
     * ProductSyncRepository constructor.
     * @param ResourceModel $resource
     * @param ProductSyncFactory $modelFactory
     * @param ProductSyncCollectionFactory $productSyncCollectionFactory
     */
    public function __construct(
        ResourceModel $resource,
        ProductSyncFactory $modelFactory,
        ProductSyncCollectionFactory $productSyncCollectionFactory
    ) {
        $this->resource = $resource;
        $this->modelFactory = $modelFactory;
        $this->productSyncCollectionFactory = $productSyncCollectionFactory;
    }

    /**
     * @return DataObject[]
     */
    public function getByResponseCodes()
    {
        $products = $this->productSyncCollectionFactory->create();
        $products
            ->addFieldToFilter('response_code', ['gteq' => \Yotpo\Core\Model\Config::BAD_REQUEST_RESPONSE_CODE])
            ->addFieldToFilter('is_deleted', ['neq' => 1])
            ->addFieldToSelect(['product_id', 'store_id']);
        return $products->getItems();
    }

    /**
     * @return mixed
     */
    public function create()
    {
        return $this->modelFactory->create();
    }
}
