<?php

namespace Yotpo\Core\Model\Sync\Orders;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\GroupedProduct\Model\Product\Type\Grouped as ProductTypeGrouped;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ProductTypeConfigurable;
use Magento\Bundle\Model\Product\Type as ProductTypeBundle;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Yotpo\Core\Helper\Data as CoreHelper;
use Yotpo\Core\Model\Sync\Data\Main;
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
class Data extends Main
{
    /**
     * Magento order status constants
     */
    const ORDER_STATUS_CANCELED = 'canceled';
    const ORDER_STATUS_CLOSED = 'closed';
    const ORDER_STATUS_COMPLETE = 'complete';
    const ORDER_STATUS_FRAUD = 'fraud';
    const ORDER_STATUS_HOLDED = 'holded';
    const ORDER_STATUS_PAYMENT_REVIEW = 'payment_review';
    const ORDER_STATUS_PAYPAL_CANCELED_REVERSAL = 'paypal_canceled_reversal';
    const ORDER_STATUS_PAYPAL_REVERSED = 'paypal_reversed';

    /**
     * Yotpo order status constants
     */
    const YOTPO_STATUS_PENDING = 'pending';
    const YOTPO_STATUS_AUTHORIZED = 'authorized';
    const YOTPO_STATUS_PARTIALLY_PAID = 'partially_paid';
    const YOTPO_STATUS_PAID = 'paid';
    const YOTPO_STATUS_PARTIALLY_REFUNDED = 'partially_refunded';
    const YOTPO_STATUS_REFUNDED = 'refunded';
    const YOTPO_STATUS_VOIDED = 'voided';

    /**
     * Custom attribute code for SMS marketing
     */
    const YOTPO_CUSTOM_ATTRIBUTE_SMS_MARKETING = 'yotpo_accepts_sms_marketing';

    /**
     * @var CoreHelper
     */
    protected $coreHelper;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var JsonSerializer
     */
    protected $serializer;

    /**
     * @var Logger
     */
    protected $yotpoOrdersLogger;

    /**
     * @var array<mixed>
     */
    protected $mappedOrderStatuses = [];

    /**
     * @var array<mixed>
     */
    protected $mappedShipmentStatuses = [];

    /**
     * @var array<mixed>
     */
    protected $shipmentsCollection = [];

    /**
     * @var array<mixed>
     */
    protected $couponsCollection = [];

    /**
     * @var array <mixed>
     */
    protected $customersAttributeCollection = [];

    /**
     * @var array <mixed>
     */
    protected $guestUsersAttributeCollection = [];

    /**
     * @var array <mixed>
     */
    protected $lineItemsProductIds = [];

    /**
     * @var array <mixed>
     */
    protected $yotpoParentProductIds = [];

    /**
     * @var array <mixed>
     */
    protected $magentoParentProductIds = [];

    /**
     * @var array <mixed>
     */
    protected $parentProductIds = [];

    /**
     * @var ShipmentCollectionFactory
     */
    protected $shipmentCollectionFactory;

    /**
     * @var CouponCollectionFactory
     */
    protected $couponCollectionFactory;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * @var CustomerRepository
     */
    protected $customerRepository;

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
    protected $loadedProducts;

    /**
     * @var string[]
     */
    protected $validPaymentStatuses = [
        'pending',
        'authorized',
        'partially_paid',
        'paid',
        'partially_refunded',
        'refunded',
        'voided'
    ];

    /**
     * @var ProductRepository
     */
    protected $productRepository;

    /**
     * @var OrderRepository
     */
    protected $orderRepository;

    /**
     * @var array <mixed>
     */
    protected $groupProductsParents = [];

    /**
     * @var array <mixed>
     */
    protected $productOptions = [];

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
     * @param Logger $yotpoOrdersLogger
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
        $this->coreHelper = $coreHelper;
        $this->storeManager = $storeManager;
        $this->config = $config;
        $this->serializer = $serializer;
        $this->yotpoOrdersLogger = $yotpoOrdersLogger;
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
        $this->couponCollectionFactory = $couponCollectionFactory;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->customerRepository = $customerRepository;
        $this->productRepository = $productRepository;
        $this->orderRepository = $orderRepository;
        $this->configurableType = $configurableType;
        $this->groupedType = $groupedType;
        $this->bundleType = $bundleType;
        parent::__construct($resourceConnection);
    }

    /**
     * Prepare order data
     *
     * @param Order $order
     * @param string $syncType
     * @return array|array[]
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function prepareData($order, $syncType)
    {
        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();
        $orderStatus = $order->getStatus();
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
                    ? null
                    : $this->prepareFulfillments($order)
            ]
        ];
        if ($syncType === 'create') {
            $data['order']['external_id'] = $order->getIncrementId();
        }
        if ($orderStatus === self::ORDER_STATUS_CANCELED || $orderStatus === self::ORDER_STATUS_CLOSED) {
            $data['order']['cancellation'] =
                [
                    'cancellation_date' => $this->coreHelper->formatDate($order->getUpdatedAt())
                ];
        }
        return $data;
    }

    /**
     * Prepare customer data
     *
     * @param Order $order
     * @return array<mixed>
     */
    public function prepareCustomerData($order)
    {
        $phoneNumber = null;
        $countryId = null;
        $customAttributeValue = $order->getCustomerId() ?
            $this->customersAttributeCollection[$order->getCustomerId()] :
            $this->guestUsersAttributeCollection[$order->getEntityId()];
        $billingAddress = $order->getBillingAddress();
        if ($billingAddress) {
            $phoneNumber = $billingAddress->getTelephone();
            $countryId = $billingAddress->getCountryId();
        }
        return [
            'external_id' => $order->getCustomerId() ?: $order->getCustomerEmail(),
            'email' => $order->getCustomerEmail(),
            'phone_number' => $phoneNumber && $countryId ?
                $this->coreHelper->formatPhoneNumber(
                    $phoneNumber,
                    $countryId
                ) : null,
            'first_name' => $order->getCustomerFirstname(),
            'last_name' => $order->getCustomerLastname(),
            'accepts_sms_marketing' => $customAttributeValue == 1
        ];
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
     * @param Order $order
     * @return array<mixed>
     */
    public function prepareLineItems($order)
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
                    if ($orderItem->getData('amount_refunded') >= $orderItem->getData('row_total_incl_tax') ||
                        $orderItem->getData('qty_ordered') <= ($orderItem->getData('qty_refunded')
                            + $orderItem->getData('qty_canceled'))
                    ) {
                        //Skip if item is fully canceled or refunded
                        continue;
                    }
                    $productId = $this->parentProductIds[$product->getId()];
                    if (isset($lineItems[$productId])) {
                        $lineItems[$productId]['total_price'] +=
                            $orderItem->getData('row_total_incl_tax') - $orderItem->getData('amount_refunded');
                        $lineItems[$productId]['subtotal_price'] += $orderItem->getRowTotal();
                        $lineItems[$productId]['quantity'] += $orderItem->getQtyOrdered() * 1;
                    } else {
                        $this->lineItemsProductIds[] = $productId;
                        $lineItems[$productId] = [
                            'external_product_id' => $productId,
                            'quantity' => $orderItem->getQtyOrdered() * 1,
                            'total_price' => $orderItem->getData('row_total_incl_tax') -
                                $orderItem->getData('amount_refunded'),
                            'subtotal_price' => $orderItem->getRowTotal(),
                            'coupon_code' => $couponCode
                        ];
                    }
                } catch (\Exception $e) {
                    $this->yotpoOrdersLogger->info('Orders sync::prepareLineItems() - exception: ' .
                        $e->getMessage(), []);
                }
            }
        } catch (\Exception $e) {
            $this->yotpoOrdersLogger->info('Orders sync::prepareLineItems() - exception: ' .
                $e->getMessage(), []);
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
                $orderItemProductId = $orderItem->getProductId();
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
                $missingProductIds = array_diff($orderItemProductIds, array_keys($yotpoParentIds));
                if ($missingProductIds) {
                    $this->getMagentoParentIds($missingProductIds);
                } else {
                    $this->parentProductIds = array_replace($this->parentProductIds, $this->yotpoParentProductIds);
                }
            } else {
                $this->getMagentoParentIds($orderItemProductIds);
                $this->parentProductIds = array_replace($this->parentProductIds, $this->yotpoParentProductIds);
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
        $yotpoProducts = $this->getParentProductIds($orderItemProductIds, $this->config->getStoreId());
        foreach ($yotpoProducts as $productId => $parentId) {
            $this->yotpoParentProductIds[$productId] = $parentId;
        }
        return $this->yotpoParentProductIds;
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
     * @return array <mixed>
     */
    public function prepareFulfillments($order)
    {
        try {
            $fulfillments = [];
            if ($this->shipmentsCollection) {
                if (isset($this->shipmentsCollection[$order->getEntityId()])) {
                    $shipments = $this->shipmentsCollection[$order->getEntityId()];
                    foreach ($shipments as $orderShipment) {
                        $shipmentItems = $orderShipment->getItems();
                        $items = $this->prepareFulFillmentItemsArray($shipmentItems);
                        $fulfillment = [
                            'fulfillment_date' => $this->coreHelper->formatDate($orderShipment->getCreatedAt()),
                            'status' => $this->getYotpoOrderStatus($order->getStatus()),
                            'shipment_info' => $this->getShipment($orderShipment),
                            'fulfilled_items' => array_values($items),
                            'external_id' => $orderShipment->getEntityId()
                        ];
                        $fulfillments[] = $fulfillment;
                    }
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
     * Get shipments of the order
     *
     * @param ShipmentInterface $shipment
     * @return array<mixed>|null
     */
    public function getShipment($shipment)
    {
        $shipments = [
            'shipment_status' => $this->getYotpoShipmentStatus($shipment->getShipmentStatus())
        ];
        foreach ($shipment->getTracks() as $track) {
            $shipments['tracking_company'] = $track->getCarrierCode();
            $shipments['tracking_number'] =  $track->getTrackNumber();
        }
        return $shipments;
    }

    /**
     * Get the mapped Yotpo order status
     *
     * @param string|null $magentoOrderStatus
     * @return string|null
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getYotpoOrderStatus($magentoOrderStatus)
    {
        $yotpoOrderStatus = null;
        if ($magentoOrderStatus) {
            $orderStatusArray = $this->getMappedOrderStatuses();
            if (array_key_exists($magentoOrderStatus, $orderStatusArray)) {
                $yotpoOrderStatus = $orderStatusArray[$magentoOrderStatus];
            }

        }
        return $yotpoOrderStatus;
    }

    /**
     * Get mapped magento and yotpo order statuses
     *
     * @return array<mixed>
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getMappedOrderStatuses()
    {
        if (!$this->mappedOrderStatuses) {
            $orderStatusConfig = $this->config->getConfig('orders_mapped_status');
            $orderStatuses = [];
            $unSerializedData = $this->serializer->unserialize($orderStatusConfig);
            /** @phpstan-ignore-next-line */
            foreach ($unSerializedData as $row) {
                $orderStatuses[$row['store_order_status']] = $row['yotpo_order_status'];
            }
            $this->mappedOrderStatuses = $orderStatuses;
        }
        return $this->mappedOrderStatuses;
    }

    /**
     * Get the mapped Yotpo shipment status
     *
     * @param int|string|null $magentoShipmentStatus
     * @return string|null
     */
    public function getYotpoShipmentStatus($magentoShipmentStatus)
    {
        $yotpoShipmentStatus = null;
        if ($magentoShipmentStatus) {
            try {
                $shipmentStatusArray = $this->getMappedShipmentStatuses();
                if (array_key_exists($magentoShipmentStatus, $shipmentStatusArray)) {
                    $yotpoShipmentStatus = $shipmentStatusArray[$magentoShipmentStatus];
                } else {
                    $arrayKeys = array_keys($shipmentStatusArray);
                    foreach ($arrayKeys as $arraykey) {
                        $values = explode(',', $arraykey);
                        if (count($values) > 1 && in_array($magentoShipmentStatus, $values)) {
                            $yotpoShipmentStatus = $shipmentStatusArray[$arraykey];
                        }
                    }
                }
            } catch (NoSuchEntityException $e) {
                $this->yotpoOrdersLogger->info('Orders sync::getYotpoShipmentStatus() - NoSuchEntityException: ' .
                    $e->getMessage(), []);
            } catch (LocalizedException $e) {
                $this->yotpoOrdersLogger->info('Orders sync::getYotpoShipmentStatus() - LocalizedException: ' .
                    $e->getMessage(), []);
            }
        }
        return $yotpoShipmentStatus;
    }

    /**
     * Get mapped magento and yotpo shipment statuses
     *
     * @return array<mixed>
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getMappedShipmentStatuses()
    {
        if (!$this->mappedShipmentStatuses) {
            $shipmentStatusConfig = $this->config->getConfig('orders_shipment_status');
            $shipmentStatuses = [];
            $unserializedData = $this->serializer->unserialize($shipmentStatusConfig);
            /** @phpstan-ignore-next-line */
            foreach ($unserializedData as $row) {
                $shipmentStatuses[$row['yotpo_shipment_status']] = $row['store_shipment_status'];
            }
            $this->mappedShipmentStatuses = array_flip($shipmentStatuses);
        }
        return $this->mappedShipmentStatuses;
    }

    /**
     * Get the shipment status of the orders
     *
     * @param array<mixed> $orderIds
     * @return void
     */
    public function prepareShipmentStatuses($orderIds)
    {
        if (!$this->shipmentsCollection) {
            $collection = $this->shipmentCollectionFactory->create();
            $collection->addFieldToFilter('order_id', ['in' => $orderIds]);
            $shipmentRecords = $collection->getItems();
            foreach ($shipmentRecords as $shipment) {
                $this->shipmentsCollection[$shipment->getOrderId()][] = $shipment;
            }
        }
    }

    /**
     * Get the rule ids of the orders
     *
     * @param array<mixed> $couponCodes
     * @return void
     */
    public function prepareCouponCodes($couponCodes)
    {
        if (!$this->couponsCollection) {
            $coupons = $this->couponCollectionFactory->create();
            $coupons->addFieldToFilter('code', ['in' => $couponCodes]);
            if ($couponsData = $coupons->getItems()) {
                foreach ($couponsData as $coupon) {
                    $this->couponsCollection[$coupon->getRuleId()] = $coupon->getCode();
                }
            }
        }
    }

    /**
     * Prepare Custom Attributes data
     *
     * @param array<mixed> $customerIds
     * @return void
     * @throws LocalizedException
     */
    public function prepareCustomAttributes($customerIds)
    {
        $customAttributeValue = false;
        if (!$this->customersAttributeCollection) {
            $this->searchCriteriaBuilder->addFilter(
                'entity_id',
                $customerIds,
                'in'
            );
            $searchCriteria = $this->searchCriteriaBuilder->create();
            $customers = $this->customerRepository->getList($searchCriteria);
            $customersData = $customers->getItems();
            foreach ($customersData as $customer) {
                $customAttribute =
                    $customer->getCustomAttribute(self::YOTPO_CUSTOM_ATTRIBUTE_SMS_MARKETING);
                if ($customAttribute) {
                    $customAttributeValue = $customAttribute->getValue();
                }
                $this->customersAttributeCollection[$customer->getId()] = $customAttributeValue;
            }
        }
    }

    /**
     * Prepare Custom Attributes data for guest users
     *
     * @param array<mixed> $orderIds
     * @return void
     */
    public function prepareGuestUsersCustomAttributes($orderIds)
    {
        if (!$this->guestUsersAttributeCollection) {
            $this->searchCriteriaBuilder->addFilter(
                'entity_id',
                $orderIds,
                'in'
            );
            $searchCriteria = $this->searchCriteriaBuilder->create();
            $orders = $this->orderRepository->getList($searchCriteria);
            $ordersData = $orders->getItems();
            foreach ($ordersData as $order) {
                $this->guestUsersAttributeCollection[$order->getEntityId()] =
                    $order->getData(self::YOTPO_CUSTOM_ATTRIBUTE_SMS_MARKETING);/** @phpstan-ignore-line */
            }
        }
    }

    /**
     * Get Yotpo payment status
     *
     * @param Order $order
     * @return float|null|string
     */
    public function getYotpoPaymentStatus($order)
    {
        $orderStatus = $order->getStatus();
        if ($orderStatus && in_array(strtolower($orderStatus), $this->validPaymentStatuses)) {
            return $orderStatus;
        }
        switch ($orderStatus) {
            case self::ORDER_STATUS_CANCELED:
            case self::ORDER_STATUS_FRAUD:
            case self::ORDER_STATUS_PAYPAL_CANCELED_REVERSAL:
                $orderStatus = self::YOTPO_STATUS_VOIDED;
                break;
            case self::ORDER_STATUS_HOLDED:
            case self::ORDER_STATUS_PAYMENT_REVIEW:
            case self::ORDER_STATUS_PAYPAL_REVERSED:
                $orderStatus = self::YOTPO_STATUS_PENDING;
                break;
            case self::ORDER_STATUS_CLOSED:
            case self::ORDER_STATUS_COMPLETE:
                $orderStatus = self::YOTPO_STATUS_PAID;
                break;
            default:
                $orderStatus = $this->getOrderStatus($order);
                break;
        }
        return $orderStatus;
    }

    /**
     * Get order status
     *
     * @param Order $order
     * @return float|null|string
     */
    public function getOrderStatus($order)
    {
        $yotpoOrderStatus = null;
        $isFullyRefunded = false;
        $isPartiallyRefunded = false;
        $magentoOrderStatus = $order->getStatus();
        $orderPayment = $order->getPayment();
        $authorizedAmount = $orderPayment !== null ? $orderPayment->getAmountAuthorized() : null;
        if (($order->getGrandTotal() == 0 || ($order->getTotalPaid() == $order->getTotalInvoiced()))
            && $order->getTotalDue() == 0) {
            $yotpoOrderStatus = self::YOTPO_STATUS_PAID;
        } elseif ($order->getTotalDue() > 0 && $order->getTotalPaid() > 0) {
            $yotpoOrderStatus = self::YOTPO_STATUS_PARTIALLY_PAID;
        } elseif (($magentoOrderStatus && stripos($magentoOrderStatus, self::YOTPO_STATUS_PENDING) !== false)
            || ($order->getGrandTotal() == $order->getTotalDue())) {
            $yotpoOrderStatus = self::YOTPO_STATUS_PENDING;
        } elseif ($authorizedAmount > 0) {
            $yotpoOrderStatus = self::YOTPO_STATUS_AUTHORIZED;
        } else {
            /** @var OrderItem $item */
            foreach ($order->getAllVisibleItems() as $item) {
                if ($item->getQtyOrdered() - $item->getQtyCanceled() == $item->getQtyRefunded()) {
                    $isFullyRefunded = true;
                } elseif ($item->getQtyRefunded() > 0 &&
                    ($item->getQtyRefunded() < ($item->getQtyOrdered() - $item->getQtyCanceled()))) {
                    $isPartiallyRefunded = true;
                    $isFullyRefunded = false;
                    break;
                }
            }
            $yotpoOrderStatus = $isFullyRefunded ?
                self::YOTPO_STATUS_REFUNDED : ($isPartiallyRefunded ? self::YOTPO_STATUS_PARTIALLY_REFUNDED : null);
        }
        return $yotpoOrderStatus;
    }

    /**
     * @param OrderItem $orderItem
     * @return ProductInterface|Product|mixed|null
     * @throws NoSuchEntityException
     */
    public function prepareProductObject(OrderItem $orderItem)
    {
        $product = null;
        if ($orderItem->getProductType() === ProductTypeGrouped::TYPE_CODE) {
            $this->productOptions = $orderItem->getProductOptions();
            $productId = (isset($this->productOptions['super_product_config']) &&
                isset($this->productOptions['super_product_config']['product_id'])) ?
                $this->productOptions['super_product_config']['product_id'] : null;
            if ($productId && isset($this->groupProductsParents[$productId])) {
                $product = $this->groupProductsParents[$productId];
            } elseif ($productId && !isset($this->groupProductsParents[$productId])) {
                $product = $this->groupProductsParents[$productId] =
                    $this->productRepository->getById($productId);
            }
        } else {
            $product = $orderItem->getProduct();
        }
        return $product;
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
