<?php

namespace Yotpo\Core\Model\Sync\Orders;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\GroupedProduct\Model\Product\Type\Grouped as ProductTypeGrouped;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ProductTypeConfigurable;
use Magento\Bundle\Model\Product\Type as ProductTypeBundle;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Yotpo\Core\Helper\Data as CoreHelper;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Sales\Model\Order;
use Yotpo\Core\Model\Config;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Yotpo\Core\Model\Sync\Orders\Logger as YotpoOrdersLogger;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory as CouponCollectionFactory;
use Magento\Customer\Model\ResourceModel\CustomerRepository;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Catalog\Model\ProductRepository;
use Magento\Sales\Model\OrderRepository;

/**
 * Class Data - Prepare data for orders sync
 */
class Data extends AbstractData
{
    /**
     * Yotpo order status constants
     */
    const YOTPO_STATUS_SUCCESS = 'success';
    const YOTPO_STATUS_CANCELLED = 'cancelled';

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var array <mixed>
     */
    protected $lineItemsProductIds = [];

    /**
     * @var array <mixed>
     */
    protected $magentoParentProductIds = [];

    /**
     * @var array <mixed>
     */
    protected $parentProductIds = [];

    /**
     * @var ProductTypeConfigurable
     */
    protected $configurableType;

    /**
     * @var ProductTypeGrouped
     */
    protected $groupedType;

    /**
     * @var ProductTypeBundle
     */
    protected $bundleType;

    /**
     * @var array <mixed>
     */
    protected $shipOrderItems = [];

    /**
     * Data constructor.
     * @param CoreHelper $coreHelper
     * @param ResourceConnection $resourceConnection
     * @param StoreManagerInterface $storeManager
     * @param Config $config
     * @param JsonSerializer $serializer
     * @param YotpoOrdersLogger $yotpoOrdersLogger
     * @param ShipmentCollectionFactory $shipmentCollectionFactory
     * @param CouponCollectionFactory $couponCollectionFactory
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param CustomerRepository $customerRepository
     * @param ProductRepository $productRepository
     * @param OrderRepository $orderRepository
     * @param ProductTypeConfigurable $configurableType
     * @param ProductTypeGrouped $groupedType
     * @param ProductTypeBundle $bundleType
     */
    public function __construct(
        CoreHelper $coreHelper,
        ResourceConnection $resourceConnection,
        StoreManagerInterface $storeManager,
        Config $config,
        JsonSerializer $serializer,
        YotpoOrdersLogger $yotpoOrdersLogger,
        ShipmentCollectionFactory $shipmentCollectionFactory,
        CouponCollectionFactory $couponCollectionFactory,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        CustomerRepository $customerRepository,
        ProductRepository $productRepository,
        OrderRepository $orderRepository,
        ProductTypeConfigurable $configurableType,
        ProductTypeGrouped $groupedType,
        ProductTypeBundle $bundleType
    ) {
        parent::__construct(
            $coreHelper,
            $resourceConnection,
            $config,
            $serializer,
            $yotpoOrdersLogger,
            $shipmentCollectionFactory,
            $couponCollectionFactory,
            $searchCriteriaBuilder,
            $customerRepository,
            $productRepository,
            $orderRepository
        );
        $this->storeManager = $storeManager;
        $this->configurableType = $configurableType;
        $this->groupedType = $groupedType;
        $this->bundleType = $bundleType;
    }

    /**
     * Prepare order data
     *
     * @param Order $order
     * @param string $syncType
     * @param array <mixed> $yotpoOrderObject
     * @return array|array[]
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function prepareData($order, $syncType, $yotpoOrderObject)
    {
        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();
        $orderStatus = $order->getStatus();
        $mappedYotpoOrderStatus = $this->getYotpoOrderStatus($orderStatus);
        $data = [
            'order' => [
                'order_date' => $this->coreHelper->formatDate($order->getCreatedAt()),
                'checkout_token' => $order->getQuoteId(),
                /** @phpstan-ignore-next-line */
                'payment_method' => $order->getPayment()->getMethodInstance()->getTitle(),
                'total_price' => $order->getGrandTotal(),
                'subtotal_price' => $order->getSubtotal() + $order->getDiscountAmount(),
                'currency' => $order->getOrderCurrencyCode(),
                'landing_site_url' => $this->storeManager->getStore()->getBaseUrl(),
                'payment_status' => $this->getYotpoPaymentStatus($order),
                'customer' => $this->prepareCustomerData($order),
                'billing_address' => $billingAddress ?
                    $this->prepareAddress($billingAddress) :
                    null,
                'shipping_address' => $shippingAddress ?
                    $this->prepareAddress($shippingAddress) :
                    null,
                'line_items' => array_values($this->prepareLineItems($order)),
                'fulfillments' =>
                    $orderStatus === self::ORDER_STATUS_CLOSED || $orderStatus === self::ORDER_STATUS_CANCELED
                    || $mappedYotpoOrderStatus == self::YOTPO_STATUS_CANCELLED
                    ? null
                    : $this->prepareFulfillments($order, $syncType, $yotpoOrderObject)
            ]
        ];
        if ($syncType === 'create') {
            $data['order']['external_id'] = $order->getIncrementId();
        }
        if ($orderStatus === self::ORDER_STATUS_CANCELED || $orderStatus === self::ORDER_STATUS_CLOSED
            || $mappedYotpoOrderStatus == self::YOTPO_STATUS_CANCELLED) {
            $data['order']['cancellation'] =
                [
                    'cancellation_date' => $this->coreHelper->formatDate($order->getUpdatedAt())
                ];
        }
        return $data;
    }

    /**
     * Prepare address data
     *
     * @param OrderAddressInterface $address
     * @return array<mixed>
     */
    public function prepareAddress($address)
    {
        $street = $address->getStreet();
        return [
            'address1' => is_array($street) && count($street) >= 1 ? $street[0] : $street,
            'address2' => is_array($street) && count($street) > 1 ? $street[1] : null,
            'city' => $address->getCity(),
            'company' => $address->getCompany(),
            'state' => $address->getRegion(),
            'zip' => $address->getPostcode(),
            'province_code' => $address->getRegionCode(),
            'country_code' => $address->getCountryId(),
            'phone_number' => $address->getTelephone() ? $this->coreHelper->formatPhoneNumber(
                $address->getTelephone(),
                $address->getCountryId()
            ) : null
        ];
    }

    /**
     * Prepare lineitems data
     *
     * @param bool $isFulfillmentObject
     * @param Order $order
     * @return array<mixed>
     */
    public function prepareLineItems($order, $isFulfillmentObject = false)
    {
        $lineItems = [];
        try {
            foreach ($order->getAllVisibleItems() as $orderItem) {
                try {
                    $couponCode = null;
                    $itemRuleIds = explode(',', (string)$orderItem->getAppliedRuleIds());
                    foreach ($itemRuleIds as $itemRuleId) {
                        $couponCode = $this->couponsCollection[$itemRuleId] ?? null;
                        if ($couponCode) {
                            break;
                        }
                    }

                    $product = $this->prepareProductObject($orderItem);
                    $this->shipOrderItems[$orderItem->getId()] = $orderItem;

                    if (!($product && $product->getId())) {
                        $this->yotpoOrdersLogger->info('Orders sync::prepareLineItems()
                        - Product not found for order: ' . $order->getEntityId(), []);
                        continue;
                    }
                    $productId = $this->parentProductIds[$product->getId()];

                    if (isset($lineItems[$productId])) {
                        if (!$isFulfillmentObject) {
                            $lineItems[$productId]['total_price'] =
                                isset($lineItems[$productId]['total_price']) ?
                                $lineItems[$productId]['total_price'] +
                                $orderItem->getData('row_total_incl_tax') - $orderItem->getData('amount_refunded') :
                                $orderItem->getData('row_total_incl_tax') - $orderItem->getData('amount_refunded');
                            $lineItems[$productId]['subtotal_price'] =
                                isset($lineItems[$productId]['subtotal_price']) ?
                                $lineItems[$productId]['subtotal_price'] + $orderItem->getRowTotal() :
                                $orderItem->getRowTotal();
                        }
                        $lineItems[$productId]['quantity'] += $orderItem->getQtyOrdered() * 1;
                    } else {
                        $this->lineItemsProductIds[] = $productId;
                        $lineItems[$productId] = [
                            'external_product_id' => $productId,
                            'quantity' => $orderItem->getQtyOrdered() * 1
                        ];
                        if (!$isFulfillmentObject) {
                            $lineItems[$productId]['total_price'] = $orderItem->getData('row_total_incl_tax') -
                                $orderItem->getData('amount_refunded');
                            $lineItems[$productId]['subtotal_price'] = $orderItem->getRowTotal();
                            $lineItems[$productId]['coupon_code'] = $couponCode;
                        }
                    }
                } catch (\Exception $e) {
                    $this->yotpoOrdersLogger->info('Orders sync::prepareLineItems() - exception for orderId: '.
                        $order->getId() . ' ' . $e->getMessage(), []);
                }
            }
        } catch (\Exception $e) {
            $this->yotpoOrdersLogger->info('Orders sync::prepareLineItems() - exception: for orderId: ' .
                $order->getId(). ' ' .$e->getMessage(), []);
        }
        return $lineItems;
    }

    /**
     * Prepare parent data of orderitems
     *
     * @param array <mixed> $orders
     * @return void
     * @throws NoSuchEntityException
     */
    public function prepareParentData($orders)
    {
        $orderItemProductIds = [];
        foreach ($orders as $order) {
            foreach ($order->getAllVisibleItems() as $orderItem) {
                if (!$orderItem->getProduct()) {
                    continue;
                }
                $orderItemProduct = $this->prepareProductObject($orderItem);
                $orderItemProductId = $orderItemProduct->getId();
                /** @var OrderItem $orderItem */
                if ($orderItem->getProductType() == 'simple' && !$orderItem->getParentItemId()
                && !$orderItem->getProduct()->isVisibleInSiteVisibility()) {/** @phpstan-ignore-line */
                    $orderItemProductIds[] = $orderItemProductId;
                } else {
                    $this->parentProductIds[$orderItemProductId] = $orderItemProductId;
                }
            }
        }
        if ($orderItemProductIds) {
            $yotpoParentIds = $this->getYotpoParentIds($orderItemProductIds);
            if ($yotpoParentIds) {
                $this->parentProductIds = array_replace($this->parentProductIds, $yotpoParentIds);
                $missingProductIds = array_diff($orderItemProductIds, array_keys($yotpoParentIds));
                if ($missingProductIds) {
                    $this->getMagentoParentIds($missingProductIds);
                }
            } else {
                $this->getMagentoParentIds($orderItemProductIds);
            }
            foreach ($orderItemProductIds as $oIProductId) {
                if (!isset($this->parentProductIds[$oIProductId])) {
                    $this->parentProductIds[$oIProductId] = $oIProductId;
                }
            }
        }
    }

    /**
     * @param array <mixed> $orderItemProductIds
     * @return array <mixed>
     * @throws NoSuchEntityException
     */
    public function getYotpoParentIds($orderItemProductIds)
    {
        $yotpoParentIds = [];
        $yotpoProducts = $this->getParentProductIds($orderItemProductIds, $this->config->getStoreId());
        foreach ($yotpoProducts as $productId => $parentId) {
            $yotpoParentIds[$productId] = $parentId;
        }
        return $yotpoParentIds;
    }

    /**
     * @param array <mixed> $missingProductIds
     * @return void
     */
    public function getMagentoParentIds($missingProductIds)
    {
        $productTypes = ['configurable','grouped', 'bundle'];
        foreach ($productTypes as $productType) {
            $missingMagentoParentIds = array_diff($missingProductIds, array_keys($this->magentoParentProductIds));
            if ($missingMagentoParentIds) {
                $this->getParentIds($missingProductIds, $productType);
                $this->parentProductIds = array_replace($this->parentProductIds, $this->magentoParentProductIds);
            } else {
                $this->parentProductIds = array_replace($this->parentProductIds, $this->magentoParentProductIds);
                break;
            }
        }
    }

    /**
     * @param array <mixed> $missingProductIds
     * @param string $producType
     * @return array <mixed>
     */
    public function getParentIds($missingProductIds, $producType)
    {
        foreach ($missingProductIds as $missingProductId) {
            switch ($producType) {
                case $this->configurableType::TYPE_CODE:
                    $typeInstance = $this->configurableType;
                    break;
                case $this->groupedType::TYPE_CODE:
                    $typeInstance = $this->groupedType;
                    break;
                case $this->bundleType::TYPE_CODE:
                    $typeInstance = $this->bundleType;
                    break;
                default:
                    $typeInstance = '';
                    break;
            }
            if ($typeInstance) {
                $parentIds = $typeInstance->getParentIdsByChild($missingProductId);
                $parentId = array_shift($parentIds);
                if ($parentId) {
                    $this->magentoParentProductIds[$missingProductId] = $parentId;
                }
            }
        }
        return $this->magentoParentProductIds;
    }

    /**
     * Get the product ids
     *
     * @return array<mixed>
     */
    public function getLineItemsIds()
    {
        return $this->lineItemsProductIds;
    }

    /**
     * Prepare fulfillment data
     *
     * @param Order $order
     * @param string $syncType
     * @param array <mixed> $yotpoOrderObject
     * @return array <mixed>
     */
    public function prepareFulfillments($order, $syncType, $yotpoOrderObject)
    {
        try {
            $fulfillments = [];
            $mappedOrderStatus = $this->getYotpoOrderStatus($order->getStatus());

            if ($syncType == 'update' && $yotpoOrderObject[$order->getId()]['yotpo_id']) {
                $isFulfillmentBasedOnShipping = $yotpoOrderObject[$order->getId()]['is_fulfillment_based_on_shipment'];
            } else {
                $isFulfillmentBasedOnShipping = $this->config->getConfig('is_fulfillment_based_on_shipment');
            }

            if ($isFulfillmentBasedOnShipping) {
                if ($this->shipmentsCollection && isset($this->shipmentsCollection[$order->getEntityId()])) {
                    $shipments = $this->shipmentsCollection[$order->getEntityId()];
                    foreach ($shipments as $orderShipment) {
                        $shipmentItems = $orderShipment->getItems();
                        $items = $this->prepareFulFillmentItemsArray($shipmentItems);
                        $fulfillment = [
                            'fulfillment_date' => $this->coreHelper->formatDate($orderShipment->getCreatedAt()),
                            'status' => self::YOTPO_STATUS_SUCCESS,
                            'shipment_info' => $this->getShipment($orderShipment),
                            'fulfilled_items' => array_values($items),
                            'external_id' => $orderShipment->getIncrementId()
                        ];
                        $fulfillments[] = $fulfillment;
                    }
                } else {
                    $fulfillments = [];
                }
            } else {
                if ($mappedOrderStatus == self::YOTPO_STATUS_PENDING) {
                    $fulfillments = [];
                } elseif ($mappedOrderStatus == self::YOTPO_STATUS_SUCCESS) {
                    $fulfillments[] = [
                        'fulfillment_date' => $this->coreHelper->formatDate($order->getUpdatedAt()),
                        'status' => self::YOTPO_STATUS_SUCCESS,
                        'fulfilled_items' => array_values($this->prepareLineItems($order, true)),
                        'external_id' => $order->getIncrementId()
                    ];
                }
            }
        } catch (NoSuchEntityException $e) {
            $this->yotpoOrdersLogger->info('Orders sync::prepareFulfillments() - NoSuchEntityException: ' .
                $e->getMessage(), []);
        } catch (LocalizedException $e) {
            $this->yotpoOrdersLogger->info('Orders sync::prepareFulfillments() - LocalizedException: ' .
                $e->getMessage(), []);
        }
        return $fulfillments;
    }

    /**
     * @param array <mixed> $shipmentItems
     * @return array <mixed>
     * @throws NoSuchEntityException
     */
    public function prepareFulFillmentItemsArray($shipmentItems = []): array
    {
        $items = [];
        foreach ($shipmentItems as $shipmentItem) {
            $orderItem = $this->shipOrderItems[$shipmentItem->getOrderItemId()];
            $product = $this->prepareProductObject($orderItem);
            $productId = $this->parentProductIds[$product->getId()];
            if (isset($items[$productId])) {
                $items[$productId]['quantity'] += $shipmentItem->getQty() * 1;
            } else {
                $items[$productId] = [
                    'external_product_id' => $productId,
                    'quantity' => $shipmentItem->getQty() * 1
                ];
            }
        }
        return $items;
    }
}
