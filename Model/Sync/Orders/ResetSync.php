<?php

namespace Yotpo\Core\Model\Sync\Orders;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Yotpo\Core\Model\Config;

/**
 * Class ResetSync - Reset orders sync
 */
class ResetSync
{
    const SCOPE_STORES = 'stores';

    /**
     * @var ManagerInterface
     */
    protected $messageManager;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var array <mixed>
     */
    protected $messages = ['success' => [], 'error' => []];

    /**
     * ResetSync constructor.
     * @param Context $context
     * @param ResourceConnection $resourceConnection
     * @param Config $config
     */
    public function __construct(
        Context $context,
        ResourceConnection $resourceConnection,
        Config $config
    ) {
        $this->messageManager = $context->getMessageManager();
        $this->resourceConnection = $resourceConnection;
        $this->config = $config;
    }

    /**
     * @param mixed $storeId
     * @return void
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function resetOrderStatusSync($storeId)
    {
        if (!$this->config->isEnabled($storeId)) {
            $this->addMessage('error', 'Yotpo is disabled for Store ID - ' . $storeId);
            return;
        }
        $connection = $this->resourceConnection->getConnection('sales');
        $tableName = $this->resourceConnection->getTableName('sales_order');
        $select = $connection->select()
            ->from($tableName, 'entity_id')
            ->where('synced_to_yotpo_order = ?', 1)
            ->where('store_id = ?', $storeId);
        $rows = $connection->fetchCol($select);
        if (!$rows) {
            return;
        }
        $updateLimit = $this->config->getUpdateSqlLimit();
        $rows = array_chunk($rows, $updateLimit);
        for ($i=0; $i<1; $i++) {
            $condition   =   [
                'entity_id IN (?) ' => $rows[$i]
            ];
            $connection->update(
                $tableName,
                ['synced_to_yotpo_order' => 0, 'updated_at' => new \Zend_Db_Expr('updated_at')],
                $condition
            );
        }
        $this->addMessage(
            'success',
            'Orders sync has been reset successfully'
        );
    }

    /**
     * Updates the last sync date to the database
     *
     * @param string $currentTime
     * @param mixed $storeId
     * @return void
     * @throws NoSuchEntityException
     */
    public function updateLastSyncDate($currentTime, $storeId)
    {
        $this->config->saveConfig('last_reset_orders_sync_time', $currentTime, $storeId, self::SCOPE_STORES);
    }

    /**
     * @param string $flag
     * @param string $message
     * @return void
     */
    public function addMessage($flag, $message = '')
    {
        if ($flag == 'success') {
            $this->messages['success'][] = $message;
        }
        if ($flag == 'error') {
            $this->messages['error'][] = $message;
        }
    }

    /**
     * @return array <mixed>
     */
    public function getMessages()
    {
        return $this->messages;
    }
}
