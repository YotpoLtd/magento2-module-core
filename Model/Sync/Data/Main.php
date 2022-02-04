<?php

namespace Yotpo\Core\Model\Sync\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;

/**
 * Class Main - Base class to retrieve attribute data
 */
class Main
{
    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var array<mixed>
     */
    protected $attributeIds = [];

    /**
     * Main constructor.
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ResourceConnection $resourceConnection
    ) {
        $this->resourceConnection   =   $resourceConnection;
    }

    /**
     * return attributeID
     * @param string $code
     * @return mixed|string
     */
    public function getAttributeId(string $code)
    {
        if (!array_key_exists($code, $this->attributeIds)) {
            $connection = $this->resourceConnection->getConnection();
            $query = $connection->select()->from(
                ['e' => $this->resourceConnection->getTableName('eav_attribute')],
                'e.attribute_id'
            )->where(
                $connection->quoteIdentifier('e.attribute_code') . ' = ?',
                $code
            );
            $this->attributeIds[$code] = $connection->fetchOne($query);
        }
        return $this->attributeIds[$code];
    }

    /**
     * Gets the count of synced orders from custom table
     * @return string
     */
    public function getTotalSyncedOrders()
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->
                    select()->from(['e' => $this->resourceConnection->getTableName('yotpo_orders_sync')], 'COUNT(*)');
        return $connection->fetchOne($select);
    }

    /**
     * Get the productIds od the products that are not synced
     *
     * @param array <mixed> $productIds
     * @param Order $order
     * @return mixed
     */
    public function getUnSyncedProductIds($productIds, $order)
    {
        $orderItems = [];
        foreach ($order->getAllVisibleItems() as $orderItem) {
            $product = $orderItem->getProduct();
            if (!$product) {
                continue;
            }
            $orderItems[$product->getId()] = $product;
        }
        return $this->getProductIds($productIds, $order->getStoreId(), $orderItems);
    }

    /**
     * Get product ids
     *
     * @param array <mixed> $productIds
     * @param int|null $storeId
     * @param array <mixed> $items
     * @return mixed
     */
    public function getProductIds($productIds, $storeId, $items)
    {
        $productIds = array_unique($productIds);
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('yotpo_product_sync');
        $products = $connection->select()
            ->from($table, ['product_id', 'yotpo_id', 'yotpo_id_parent', 'visible_variant_yotpo_id'])
            ->where('product_id IN(?) ', $productIds)
            ->where('store_id=(?)', $storeId);
        $products = $connection->fetchAssoc($products, []);
        foreach ($products as $product) {
            $orderItemProduct = $items[$product['product_id']] ?? null;
            $yotpoIdKey = 'yotpo_id';
            if ($orderItemProduct
                && $orderItemProduct->isVisibleInSiteVisibility()
                && $orderItemProduct->getTypeId() == 'simple'
                && $product['yotpo_id_parent']
            ) {
                $yotpoIdKey = 'visible_variant_yotpo_id';
            }

            if ($product[$yotpoIdKey]) {
                $position = array_search($product['product_id'], $productIds);
                if ($position !== false) {
                    array_splice($productIds, (int)$position, 1);
                }
            }
        }
        return $productIds;
    }

    /**
     * Get parent product ids
     *
     * @param array <mixed> $productIds
     * @param int|null $storeId
     * @return mixed
     */
    public function getParentProductIds($productIds, $storeId)
    {
        $productIds = array_unique($productIds);
        $parentProductIdsYotpo = [];
        $returnParentIds = [];

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('yotpo_product_sync');
        $products = $connection->select()
            ->from($table, ['product_id', 'yotpo_id_parent'])
            ->where('product_id IN(?) ', $productIds)
            ->where('yotpo_id_parent != ?', 0)
            ->where('store_id=(?)', $storeId);
        $products = $connection->fetchAssoc($products, []);
        $yotpoIds = [];
        foreach ($products as $product) {
            if ($product['yotpo_id_parent']) {
                $yotpoIds[] = $product['yotpo_id_parent'];
                $parentProductIdsYotpo[$product['product_id']] = $product['yotpo_id_parent'];
            }
        }
        if ($parentProductIdsYotpo) {
            $products = $connection->select()
                ->from($table, ['product_id', 'yotpo_id'])
                ->where('yotpo_id IN(?)', $yotpoIds)
                ->where('store_id=(?)', $storeId);
            $products = $connection->fetchAll($products);
            foreach ($parentProductIdsYotpo as $productId => $yotpoId) {
                if ($productIdParent = $this->findProductIdUsingYotpoId($yotpoId, $products)) {
                    $returnParentIds[$productId] = $productIdParent;
                }
            }

        }
        return $returnParentIds;
    }

    /**
     * @param int $yotpoId
     * @param array <mixed> $products
     * @return int
     */
    public function findProductIdUsingYotpoId($yotpoId, $products)
    {
        foreach ($products as $product) {
            if ($product['yotpo_id'] == $yotpoId) {
                return $product['product_id'];
            }
        }
        return 0;
    }
}
