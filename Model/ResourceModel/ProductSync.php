<?php
declare(strict_types=1);

namespace Yotpo\Core\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ProductSync extends AbstractDb
{
    /**
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('yotpo_product_sync', 'entity_id');
    }
}
