<?php

namespace Yotpo\Core\Model\ResourceModel\OrdersSync;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Yotpo\Core\Model\ResourceModel\OrdersSync as ResourceYotpoOrdersSync;
use Yotpo\Core\Model\OrdersSync;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    /**
     * Resource collection initialization
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(OrdersSync::class, ResourceYotpoOrdersSync::class);
    }
}
