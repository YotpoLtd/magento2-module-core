<?php
declare(strict_types=1);

namespace Yotpo\Core\Model;

use Magento\Framework\DataObject;
use Yotpo\Core\Api\OrdersSyncRepositoryInterface;
use Yotpo\Core\Model\ResourceModel\OrdersSync as ResourceModel;
use Yotpo\Core\Model\ResourceModel\OrdersSync\CollectionFactory as YotpoOrdersSyncCollectionFactory;

/**
 * Class OrdersSyncRepository - Manage orders sync resource
 */
class OrdersSyncRepository implements OrdersSyncRepositoryInterface
{
    /**
     * @var ResourceModel
     */
    protected $resource;

    /**
     * @var OrdersSyncFactory
     */
    protected $modelFactory;

    /**
     * @var YotpoOrdersSyncCollectionFactory
     */
    protected $yotpoOrdersSyncCollectionFactory;

    /**
     * OrdersSyncRepository constructor.
     * @param ResourceModel $resource
     * @param OrdersSyncFactory $modelFactory
     * @param YotpoOrdersSyncCollectionFactory $yotpoOrdersSyncCollectionFactory
     */
    public function __construct(
        ResourceModel $resource,
        OrdersSyncFactory $modelFactory,
        YotpoOrdersSyncCollectionFactory $yotpoOrdersSyncCollectionFactory
    ) {
        $this->resource = $resource;
        $this->modelFactory = $modelFactory;
        $this->yotpoOrdersSyncCollectionFactory = $yotpoOrdersSyncCollectionFactory;
    }

    /**
     * @return DataObject[]
     */
    public function getByResponseCodes()
    {
        $orders = $this->yotpoOrdersSyncCollectionFactory->create();
        $orders
            ->addFieldToFilter('response_code', ['gteq' => \Yotpo\Core\Model\Config::BAD_REQUEST_RESPONSE_CODE])
            ->addFieldToSelect(['order_id']);
        return $orders->getItems();
    }

    /**
     * @return mixed|OrdersSync
     */
    public function create()
    {
        return $this->modelFactory->create();
    }
}
