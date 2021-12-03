<?php

namespace Yotpo\Core\Model\Sync\Orders;

use Magento\Framework\DataObject;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\AbstractJobs;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\App\Emulation as AppEmulation;

/**
 * Class Main - Manage Orders sync
 */
class Main extends AbstractJobs
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Data
     */
    protected $data;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var string
     */
    protected $entity = 'orders';

    /**
     * Main constructor.
     * @param AppEmulation $appEmulation
     * @param ResourceConnection $resourceConnection
     * @param Config $config
     * @param Data $data
     */
    public function __construct(
        AppEmulation $appEmulation,
        ResourceConnection $resourceConnection,
        Config $config,
        Data $data
    ) {
        $this->config =  $config;
        $this->data   =  $data;
        $this->resourceConnection = $resourceConnection;
        parent::__construct($appEmulation, $resourceConnection);
    }

    /**
     * Get synced orders
     *
     * @param array <mixed> $magentoOrders
     * @return array <mixed>
     */
    public function getYotpoSyncedOrders($magentoOrders)
    {
        $return     =   [];
        $connection =   $this->resourceConnection->getConnection();
        $table      =   $this->resourceConnection->getTableName('yotpo_orders_sync');
        $orders     =   $connection->select()
                            ->from($table)
                            ->where('order_id IN (?) ', array_keys($magentoOrders))
                            ->where('yotpo_id > (?) ', 0);
        $orders =   $connection->fetchAssoc($orders, []);
        foreach ($orders as $order) {
            $return[$order['order_id']]  =   $order;
        }
        return $return;
    }

    /**
     * @param array <mixed>|DataObject $response
     * @return int|string|null
     */
    public function getYotpoIdFromResponse($response)
    {
        /** @phpstan-ignore-next-line */
        $responseData = $response->getData('response');
        $yotpoId = null;
        /** @phpstan-ignore-next-line */
        if ($response->getData('yotpo_id')) {
            /** @phpstan-ignore-next-line */
            $yotpoId = $response->getData('yotpo_id');
        }
        if ($responseData && is_array($responseData)) {
            if (isset($responseData['orders']) && $responseData['orders']) {
                $yotpoId = $responseData['orders'][0]['yotpo_id'];
            } elseif (isset($responseData['order']) && $responseData['order']) {
                $yotpoId = $responseData['order']['yotpo_id'];
            }
        }
        return $yotpoId;
    }
    /**
     * @param array <mixed>|DataObject $response
     * @param bool $isYotpoSyncedOrder
     * @param array <mixed> $yotpoSyncedOrders
     * @param int|null $magentoOrderId
     * @return array <mixed>
     */
    public function prepareYotpoTableData($response, $isYotpoSyncedOrder, $yotpoSyncedOrders, $magentoOrderId)
    {
        $data = [
            /** @phpstan-ignore-next-line */
            'response_code' =>  $response->getData('status'),
        ];

        $data['yotpo_id'] = $this->getYotpoIdFromResponse($response);

        if (!$isYotpoSyncedOrder) {
            $data['is_fulfillment_based_on_shipment'] =
                $this->config->getConfig('is_fulfillment_based_on_shipment');
        } else {
            $shipmentFlag = $yotpoSyncedOrders[$magentoOrderId]['is_fulfillment_based_on_shipment'];
            if ($shipmentFlag == null) {
                $data['is_fulfillment_based_on_shipment'] =
                    $this->config->getConfig('is_fulfillment_based_on_shipment');
            } else {
                $data['is_fulfillment_based_on_shipment'] = $shipmentFlag;
            }
        }
        return $data;
    }

    /**
     * @param int $orderId
     * @param string $currentTime
     * @return array <mixed>
     */
    public function prepareYotpoTableDataForMissingProducts($orderId, $currentTime = '')
    {
        return [
            'order_id' => $orderId,
            'yotpo_id' => null,
            'synced_to_yotpo' => $currentTime,
            'response_code' =>  $this->config->getCustRespCodeMissingProd(),
            'is_fulfillment_based_on_shipment'  =>  null
        ];
    }

    /**
     * Inserts or updates custom table data
     *
     * @param array<mixed> $data
     * @return void
     */
    public function insertOrUpdateYotpoTableData($data)
    {
        $finalData = [];
        $finalData[] = [
            'order_id'                          =>  $data['order_id'],
            'yotpo_id'                          =>  $data['yotpo_id'],
            'synced_to_yotpo'                   =>  $data['synced_to_yotpo'],
            'response_code'                     =>  $data['response_code'],
            'is_fulfillment_based_on_shipment'  =>  $data['is_fulfillment_based_on_shipment']
        ];
        $this->insertOnDuplicate('yotpo_orders_sync', $finalData);
    }
}
