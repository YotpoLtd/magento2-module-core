<?php

namespace Yotpo\Core\Model\ResourceModel\CategorySync;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Yotpo\Core\Model\ResourceModel\CategorySync as ResourceCategorySync;
use Yotpo\Core\Model\CategorySync;

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
        $this->_init(CategorySync::class, ResourceCategorySync::class);
    }
}
