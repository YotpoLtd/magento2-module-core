<?php
declare(strict_types=1);

namespace Yotpo\Core\Api\Data;

interface OrdersSyncInterface
{
    /**
     * Constants for keys of data array. Identical to the name of the getter in snake case.
     */
    const ENTITY_ID = 'entity_id';
    const ORDER_ID = 'order_id';
    const YOTPO_ID = 'yotpo_id';
    const SYNCED_TO_YOTPO = 'synced_to_yotpo';
    const RESPONSE_CODE = 'response_code';
    const IS_FULFILLMENT_BASED_ON_SHIPMENT = 'is_fulfillment_based_on_shipment';

    /**
     * Get Id.
     *
     * @return int
     */
    public function getEntityId();

    /**
     * Set Id.
     * @param int $id
     * @return void
     */
    public function setEntityId($id);

    /**
     * @return int
     */
    public function getOrderId();

    /**
     * Set OrderId.
     * @param int $orderId.
     * @return void
     */
    public function setOrderId($orderId);

    /**
     * @return mixed
     */
    public function getYotpoId();

    /**
     * @param mixed $yotpoId
     * @return mixed
     */
    public function setYotpoId($yotpoId);

    /**
     * @return mixed
     */
    public function getSyncedToYotpo();

    /**
     * @param mixed $date
     * @return mixed
     */
    public function setSyncedToYotpo($date);

    /**
     * @return mixed
     */
    public function getResponseCode();

    /**
     * @param string $responseCode
     * @return mixed
     */
    public function setResponseCode($responseCode);

    /**
     * @return mixed
     */
    public function getIsFulfillmentBasedOnShipment();

    /**
     * @param bool $flag
     * @return mixed
     */
    public function setIsFulfillmentBasedOnShipment($flag);
}
