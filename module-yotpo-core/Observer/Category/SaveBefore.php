<?php
namespace Yotpo\Core\Observer\Category;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\ResourceConnection;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\Data\Main;

/**
 * Class SaveBefore - Update yotpo attribute
 */
class SaveBefore implements ObserverInterface
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var Main
     */
    private $main;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * SaveBefore constructor.
     * @param ResourceConnection $resourceConnection
     * @param Main $main
     * @param RequestInterface $request
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        Main $main,
        RequestInterface $request
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->main = $main;
        $this->request = $request;
    }

    /**
     * Set synced_to_yotpo_collection = 0 in catalog_category_entity_int table
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $connection = $this->resourceConnection->getConnection();

        $vmCategories = $this->request->getParam('vm_category_products');
        $entityId = $this->request->getParam('entity_id');
        $diffProducts = [];

        if ($vmCategories && $entityId) {
            $vmCategories = json_decode($vmCategories, true);

            $select = $connection->select()->from(
                ['e' => $connection->getTableName('catalog_category_product')],
                ['product_id']
            )->where(
                'e.category_id =?',
                $entityId
            );
            $categoryProducts = $connection->fetchAssoc($select, 'product_id');

            $diffProducts = array_merge(
                array_diff(array_keys($vmCategories), array_keys($categoryProducts)),
                array_diff(array_keys($categoryProducts), array_keys($vmCategories))
            );

        } elseif ($vmCategories && !$entityId) {
            $vmCategories = json_decode($vmCategories, true);
            $diffProducts = array_keys($vmCategories);
        }

        if ($diffProducts) {
            $cond = [
                'row_id IN (?)' => $diffProducts,
                'attribute_id = ? ' => $this->main->getAttributeId(Config::CATALOG_SYNC_ATTR_CODE)
            ];

            $connection->update(
                $connection->getTableName('catalog_product_entity_int'),
                ['value' => 0],
                $cond
            );
        }
    }
}
