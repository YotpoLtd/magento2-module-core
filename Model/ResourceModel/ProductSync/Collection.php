<?php

namespace Yotpo\Core\Model\ResourceModel\ProductSync;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Yotpo\Core\Model\ResourceModel\ProductSync as ResourceProductSync;
use Yotpo\Core\Model\ProductSync;

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
        $this->_init(ProductSync::class, ResourceProductSync::class);
    }
}
