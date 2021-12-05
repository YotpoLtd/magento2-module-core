<?php
declare(strict_types=1);

namespace Yotpo\Core\Model;

use Yotpo\Core\Model\ResourceModel\CategorySync as CategorySyncResourceModel;
use Magento\Framework\Model\AbstractModel;

/**
 * Class CategorySync - Manage category sync resource
 */
class CategorySync extends AbstractModel
{
    const CACHE_TAG = 'yotpo_category_sync';
    const ENTITY_ID = 'entity_id';

    protected function _construct()
    {
        $this->_init(CategorySyncResourceModel::class);
    }
}
