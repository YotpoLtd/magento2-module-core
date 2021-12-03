<?php
declare(strict_types=1);

namespace Yotpo\Core\Model;

use Yotpo\Core\Model\ResourceModel\ProductSync as ProductSyncResourceModel;
use Magento\Framework\Model\AbstractModel;

/**
 * Class ProductSync - Manage products sync resource
 */
class ProductSync extends AbstractModel
{
    const CACHE_TAG = 'yotpo_product_sync';
    const ENTITY_ID = 'entity_id';

    protected function _construct()
    {
        $this->_init(ProductSyncResourceModel::class);
    }
}
