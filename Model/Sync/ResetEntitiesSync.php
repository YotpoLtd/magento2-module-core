<?php

namespace Yotpo\Core\Model\Sync;

use Yotpo\Core\Model\Sync\Reset\Catalog;
use Yotpo\Core\Model\Sync\Reset\Customers;
use Yotpo\Core\Model\Sync\Reset\Orders;

class ResetEntitiesSync
{
    /**
     * @var Catalog
     */
    protected $catalogReset;

    /**
     * @var Customers
     */
    protected $customersReset;

    /**
     * @var Orders
     */
    protected $ordersReset;

    /**
     * @param Catalog $catalogReset
     * @param Customers $customersReset
     * @param Orders $ordersReset
     */
    public function __construct(
        Catalog $catalogReset,
        Customers $customersReset,
        Orders $ordersReset
    ) {
        $this->catalogReset = $catalogReset;
        $this->customersReset = $customersReset;
        $this->ordersReset = $ordersReset;
    }

    /**
     * @param int $storeId
     * @return void
     */
    public function resetSync($storeId)
    {
        $this->resetCatalogSync($storeId);
        $this->resetCustomersSync($storeId);
        $this->resetOrdersSync($storeId);
    }

    /**
     * @param int $storeId
     * @return void
     */
    public function resetCatalogSync($storeId)
    {
        $this->catalogReset->resetSync($storeId);
    }

    /**
     * @param int $storeId
     * @return void
     */
    public function resetOrdersSync($storeId)
    {
        $this->ordersReset->resetSync($storeId);
    }

    /**
     * @param int $storeId
     * @return void
     */
    public function resetCustomersSync($storeId)
    {
        $this->customersReset->resetSync($storeId);
    }
}
