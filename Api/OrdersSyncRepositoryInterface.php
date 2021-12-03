<?php
declare(strict_types=1);

namespace Yotpo\Core\Api;

use Magento\Framework\DataObject;

/**
 * Grid CRUD interface.
 * @api
 */
interface OrdersSyncRepositoryInterface
{
    /**
     * @return DataObject[]
     */
    public function getByResponseCodes();

    /**
     * @return mixed
     */
    public function create();
}
