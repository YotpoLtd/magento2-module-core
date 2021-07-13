<?php
namespace Yotpo\Core\Observer\Product;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Yotpo\Core\Model\Sync\Data\Main;
use Yotpo\Core\Model\Config as YotpoCoreConfig;

/**
 * Class SaveImportAfter - Update yotpo attribute value when product import happens
 */
class SaveImportAfter implements ObserverInterface
{
    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var ProductCollectionFactory
     */
    protected $productCollectionFactory;

    /**
     * @var Main
     */
    protected $main;

    /**
     * SaveImportAfter constructor.
     * @param ResourceConnection $resourceConnection
     * @param ProductCollectionFactory $productCollectionFactory
     * @param Main $main
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        ProductCollectionFactory $productCollectionFactory,
        Main $main
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->main = $main;
    }

    /**
     * Execute observer - Update yotpo attribute, Manage is_deleted attr.
     *
     * @param EventObserver $observer
     * @return void
     */
    public function execute(EventObserver $observer)
    {
        $productIds = [];
        $bunch = $observer->getEvent()->getBunch();
        $sku = [];
        foreach ($bunch as $rowData) {
            $sku[] = array_key_exists('sku', $rowData) ? $rowData['sku'] : 0;
        }
        $sku = array_unique(array_filter($sku));
        if ($sku) {
            $collection = $this->productCollectionFactory->create();
            $collection->addFieldToSelect('row_id');
            $collection->addFieldToFilter('sku', ['in' => $sku]);
            $productIds = $collection->getColumnValues('row_id');
        }
        if ($productIds) {
            $this->updateProductAttribute($productIds);
        }
    }

    /**
     * Update Yotpo product attribute
     * @param array<mixed> $productIds
     * @return void
     */
    private function updateProductAttribute($productIds = [])
    {
        $connection = $this->resourceConnection->getConnection();
        $condition   =   [
            'row_id IN (?) ' => $productIds,
            'attribute_id = ?' => $this->main->getAttributeId(YotpoCoreConfig::CATALOG_SYNC_ATTR_CODE)
        ];
        $connection->update(
            $connection->getTableName('catalog_product_entity_int'),
            ['value' => 0],
            $condition
        );
    }
}
