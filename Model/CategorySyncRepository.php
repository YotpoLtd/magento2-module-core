<?php
declare(strict_types=1);

namespace Yotpo\Core\Model;

use Magento\Framework\DataObject;
use Yotpo\Core\Api\CategorySyncRepositoryInterface;
use Yotpo\Core\Model\ResourceModel\CategorySync as ResourceModel;
use Yotpo\Core\Model\ResourceModel\CategorySync\CollectionFactory as CategorySyncCollectionFactory;

/**
 * Class CategorySyncRepository - Manage category sync resource
 */
class CategorySyncRepository implements CategorySyncRepositoryInterface
{
    /**
     * @var ResourceModel
     */
    protected $resource;

    /**
     * @var CategorySyncFactory
     */
    protected $modelFactory;

    /**
     * @var CategorySyncCollectionFactory
     */
    protected $categorySyncCollectionFactory;

    /**
     * CategorySyncRepository constructor.
     * @param ResourceModel $resource
     * @param CategorySyncFactory $modelFactory
     * @param CategorySyncCollectionFactory $categorySyncCollectionFactory
     */
    public function __construct(
        ResourceModel $resource,
        CategorySyncFactory $modelFactory,
        CategorySyncCollectionFactory $categorySyncCollectionFactory
    ) {
        $this->resource = $resource;
        $this->modelFactory = $modelFactory;
        $this->categorySyncCollectionFactory = $categorySyncCollectionFactory;
    }

    /**
     * @return DataObject[]
     */
    public function getByResponseCodes()
    {
        $categories = $this->categorySyncCollectionFactory->create();
        $categories
            ->addFieldToFilter('response_code', ['gteq' => \Yotpo\Core\Model\Config::BAD_REQUEST_RESPONSE_CODE])
            ->addFieldToSelect(['category_id', 'store_id']);
        return $categories->getItems();
    }

    /**
     * @return mixed
     */
    public function create()
    {
        return $this->modelFactory->create();
    }
}
