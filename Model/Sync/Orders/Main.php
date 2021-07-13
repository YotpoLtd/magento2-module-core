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
     * @param array<mixed> $magentoOrders
     * @return array<mixed>
     */
    public function getYotpoSyncedOrders($magentoOrders)
    {
        $return     =   [];
        $connection =   $this->resourceConnection->getConnection();
        $table      =   $connection->getTableName('yotpo_orders_sync');
        $orders     =   $connection->select()
                            ->from($table)
                            ->where('order_id IN(?) ', array_keys($magentoOrders));
        $orders =   $connection->fetchAssoc($orders, []);
        foreach ($orders as $order) {
            $return[$order['order_id']]  =   $order;
        }
        return $return;
    }

    /**
     * @param array<mixed>|DataObject $response
     * @return array<mixed>
     */
    public function prepareYotpoTableData($response)
    {
        $data = [
            /** @phpstan-ignore-next-line */
            'response_code' =>  $response->getData('status'),
        ];
        /** @phpstan-ignore-next-line */
        $responseData   =   $response->getData('response');
        if ($responseData && isset($responseData['orders'])) {
            $data['yotpo_id']   =   $responseData['orders'][0]['yotpo_id'];
        } elseif ($responseData && isset($responseData['order'])) {
            $data['yotpo_id'] = $responseData['order']['yotpo_id'];
        } else {
            $data['yotpo_id']   =   null;
        }
        return $data;
    }

    /**
     * Inserts or updates custom table data
     *
     * @param array<mixed> $yotpoTableFinalData
     * @return void
     */
    public function insertOrUpdateYotpoTableData($yotpoTableFinalData)
    {
        $finalData = [];
        foreach ($yotpoTableFinalData as $data) {
            $finalData[] = [
                'order_id'        =>  $data['order_id'],
                'yotpo_id'        =>  $data['yotpo_id'],
                'synced_to_yotpo' =>  $data['synced_to_yotpo'],
                'response_code'   =>  $data['response_code']
            ];
        }
        $this->insertOnDuplicate('yotpo_orders_sync', $finalData);
    }
}
