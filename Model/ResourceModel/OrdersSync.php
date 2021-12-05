<?php
declare(strict_types=1);

namespace Yotpo\Core\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class OrdersSync extends AbstractDb
{
    /**
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('yotpo_orders_sync', 'entity_id');
    }
}
