<?php
declare(strict_types=1);

namespace Yotpo\Core\Model;

use Yotpo\Core\Api\Data\OrdersSyncInterface;
use Yotpo\Core\Model\ResourceModel\OrdersSync as YotpoOrdersSyncResourceModel;
use Magento\Framework\Model\AbstractModel;

/**
 * Class OrdersSync - Manage orders sync resource
 */
class OrdersSync extends AbstractModel implements OrdersSyncInterface
{
    const CACHE_TAG = 'yotpo_orders_sync';

    protected function _construct()
    {
        $this->_init(YotpoOrdersSyncResourceModel::class);
    }

    /**
     * Get Id
     * @return int|null
     */
    public function getEntityId()
    {
        return $this->getData(self::ENTITY_ID);
    }

    /**
     * @param int $id
     * @return void|OrdersSync
     */
    public function setEntityId($id)
    {
        $this->setData(self::ENTITY_ID, $id);
    }

    public function getOrderId()
    {
        return $this->getData(self::ORDER_ID);
    }

    public function setOrderId($orderId)
    {
        $this->setData(self::ORDER_ID, $orderId);
    }

    public function getYotpoId()
    {
        return $this->getData(self::YOTPO_ID);
    }

    public function setYotpoId($yotpoId)
    {
        $this->setData(self::YOTPO_ID, $yotpoId);
    }

    public function getSyncedToYotpo()
    {
        return $this->getData(self::SYNCED_TO_YOTPO);
    }

    public function setSyncedToYotpo($date)
    {
        $this->setData(self::SYNCED_TO_YOTPO, $date);
    }

    public function getIsFulfillmentBasedOnShipment()
    {
        return $this->getData(self::IS_FULFILLMENT_BASED_ON_SHIPMENT);
    }

    public function setIsFulfillmentBasedOnShipment($flag)
    {
        $this->setData(self::IS_FULFILLMENT_BASED_ON_SHIPMENT, $flag);
    }

    public function getResponseCode()
    {
        return $this->getData(self::RESPONSE_CODE);
    }

    public function setResponseCode($responseCode)
    {
        $this->setData(self::RESPONSE_CODE, $responseCode);
    }
}
