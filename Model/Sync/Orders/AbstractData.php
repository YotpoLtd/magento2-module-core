<?php

namespace Yotpo\Core\Model\Sync\Orders;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Data\Customer;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\GroupedProduct\Model\Product\Type\Grouped as ProductTypeGrouped;
use Magento\Sales\Api\Data\ShipmentInterface;
use Yotpo\Core\Helper\Data as CoreHelper;
use Yotpo\Core\Model\Sync\Data\Main;
use Magento\Framework\App\ResourceConnection;
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
 * Class AbstractData - Prepare data for orders sync
 */
class AbstractData extends Main
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
     * AbstractData constructor.
     * @param CoreHelper $coreHelper
     * @param ResourceConnection $resourceConnection
     * @param Config $config
     * @param JsonSerializer $serializer
     * @param Logger $yotpoOrdersLogger
     * @param ShipmentCollectionFactory $shipmentCollectionFactory
     * @param CouponCollectionFactory $couponCollectionFactory
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param CustomerRepository $customerRepository
     * @param ProductRepository $productRepository
     * @param OrderRepository $orderRepository
     */
    public function __construct(
        CoreHelper $coreHelper,
        ResourceConnection $resourceConnection,
        Config $config,
        JsonSerializer $serializer,
        YotpoOrdersLogger $yotpoOrdersLogger,
        ShipmentCollectionFactory $shipmentCollectionFactory,
        CouponCollectionFactory $couponCollectionFactory,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        CustomerRepository $customerRepository,
        ProductRepository $productRepository,
        OrderRepository $orderRepository
    ) {
        $this->coreHelper = $coreHelper;
        $this->config = $config;
        $this->serializer = $serializer;
        $this->yotpoOrdersLogger = $yotpoOrdersLogger;
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
        $this->couponCollectionFactory = $couponCollectionFactory;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->customerRepository = $customerRepository;
        $this->productRepository = $productRepository;
        $this->orderRepository = $orderRepository;
        parent::__construct($resourceConnection);
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
     * Get the shipment status of the orders
     *
     * @param array<mixed> $orderIds
     * @return void
     */
    public function prepareShipmentStatuses($orderIds)
    {
        try {
            if (!$this->shipmentsCollection) {
                $collection = $this->shipmentCollectionFactory->create();
                $collection->addFieldToFilter('order_id', ['in' => $orderIds]);
                $shipmentRecords = $collection->getItems();
                foreach ($shipmentRecords as $shipment) {
                    try {
                        $this->shipmentsCollection[$shipment->getOrderId()][] = $shipment;
                    } catch (\Exception $e) {
                        $orderId = method_exists($shipment, 'getOrderId') ? $shipment->getOrderId() : null;
                        $this->yotpoOrdersLogger->infoLog(
                            __(
                                'Exception raised within prepareShipmentStatuses - orderId: %1, Exception Message: %2',
                                $orderId,
                                $e->getMessage()
                            )
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            $this->yotpoOrdersLogger->infoLog(' Exception raised within prepareShipmentStatuses() :  ' . $e->getMessage(), []);
        }
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
            if ($this->trackingDataExists($shipments)) {
                $shipments['tracking_url'] = $this->generateTrackingUrl($shipments['tracking_company'], $shipments['tracking_number']);
            }
        }
        return $shipments;
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
                $this->yotpoOrdersLogger->infoLog('Orders sync::getYotpoShipmentStatus() - NoSuchEntityException: ' .
                    $e->getMessage(), []);
            } catch (LocalizedException $e) {
                $this->yotpoOrdersLogger->infoLog('Orders sync::getYotpoShipmentStatus() - LocalizedException: ' .
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
     * Get the rule ids of the orders
     *
     * @param array<mixed> $couponCodes
     * @return void
     */
    public function prepareCouponCodes($couponCodes)
    {
        try {
            if (!$this->couponsCollection) {
                $coupons = $this->couponCollectionFactory->create();
                $coupons->addFieldToFilter('code', ['in' => $couponCodes]);
                if ($couponsData = $coupons->getItems()) {
                    foreach ($couponsData as $coupon) {
                        $this->couponsCollection[$coupon->getRuleId()] = $coupon->getCode();
                    }
                }
            }
        } catch (\Exception $e) {
            $this->yotpoOrdersLogger->infoLog('Exception raised within prepareCouponCodes' . $e->getMessage(), []);
        }
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
            (isset($this->customersAttributeCollection[$order->getCustomerId()]) ?
                $this->customersAttributeCollection[$order->getCustomerId()] : null
            ) :
            (isset($this->guestUsersAttributeCollection[$order->getEntityId()]) ?
                $this->guestUsersAttributeCollection[$order->getEntityId()] : null
            );
        $billingAddress = $order->getBillingAddress();
        if ($billingAddress) {
            $phoneNumber = $billingAddress->getTelephone();
            $countryId = $billingAddress->getCountryId();
        }
        $shippingAddress = $order->getShippingAddress();
        $customerNameInfo = $this->getCustomerNameInfo($order, $shippingAddress, $billingAddress);
        
        return [
            'external_id' => $order->getCustomerId() ?: $order->getCustomerEmail(),
            'email' => $order->getCustomerEmail(),
            'phone_number' => $phoneNumber && $countryId ?
                $this->coreHelper->formatPhoneNumber(
                    $phoneNumber,
                    $countryId
                ) : null,
            'first_name' => $customerNameInfo['first_name'],
            'last_name' => $customerNameInfo['last_name'],
            'accepts_sms_marketing' => $customAttributeValue == 1
        ];
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
        if (!$customerIds) {
            $this->customersAttributeCollection = [];
            return;
        }
        if (!$this->customersAttributeCollection ||
            array_diff($customerIds, array_keys($this->guestUsersAttributeCollection))
        ) {
            try {
                $this->searchCriteriaBuilder->addFilter(
                    'entity_id',
                    $customerIds,
                    'in'
                );
                $searchCriteria = $this->searchCriteriaBuilder->create();
                $customers = $this->customerRepository->getList($searchCriteria);
                $customersData = $customers->getItems();
                foreach ($customersData as $customer) {
                    try {
                        /** @var Customer $customer */
                        $customAttributeValue = $this->getSmsMarketingCustomAttributeValue($customer);
                        $this->customersAttributeCollection[$customer->getId()] = $customAttributeValue;
                    } catch (\Exception $e) {
                        $customerId = method_exists($customer, 'getId') ? $customer->getId() : null;
                        $this->yotpoOrdersLogger->infoLog(
                            __(
                                'Exception raised within prepareShipmentStatuses - customerId: %1, Exception Message: %2',
                                $customerId,
                                $e->getMessage()
                            )
                        );
                    }
                }
            } catch (\Exception $e) {
                $this->yotpoOrdersLogger->infoLog(' prepareCustomAttributes() :  ' . $e->getMessage(), []);
            }
        }
    }

    /**
     * @param Customer $customer
     * @return bool
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getSmsMarketingCustomAttributeValue($customer)
    {
        $customAttributeValue = false;
        $attributeCode = $this->config->getConfig('sms_marketing_custom_attribute', $this->config->getStoreId());
        $customAttribute = $customer->getCustomAttribute($attributeCode);
        if ($customAttribute) {
            $customAttributeValue = $customAttribute->getValue();
        }
        return $customAttributeValue == 1;
    }

    /**
     * Prepare Custom Attributes data for guest users
     *
     * @param array<mixed> $orderIds
     * @return void
     */
    public function prepareGuestUsersCustomAttributes($orderIds)
    {
        try {
            if (!$orderIds) {
                $this->guestUsersAttributeCollection = [];
                return;
            }
            if (!$this->guestUsersAttributeCollection ||
                array_diff($orderIds, array_keys($this->guestUsersAttributeCollection))
            ) {
                $this->searchCriteriaBuilder->addFilter(
                    'entity_id',
                    $orderIds,
                    'in'
                );
                $searchCriteria = $this->searchCriteriaBuilder->create();
                $orders = $this->orderRepository->getList($searchCriteria);
                $ordersData = $orders->getItems();
                foreach ($ordersData as $order) {
                    try {
                        $attributeCode = $this->config->getConfig(
                            'sms_marketing_custom_attribute',
                            $order->getStoreId()
                        );
                        $this->guestUsersAttributeCollection[$order->getEntityId()] =
                            $order->getData($attributeCode) ?: false;
                        /** @phpstan-ignore-line */
                    } catch (\Exception $e) {
                        $orderId = method_exists($order, 'getEntityId') ? $order->getEntityId() : null;
                        $this->yotpoOrdersLogger->infoLog(
                            __(
                                'Exception raised within prepareGuestUsersCustomAttributes - orderId: %1, Exception Message: %2',
                                $orderId,
                                $e->getMessage()
                            )
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            $this->yotpoOrdersLogger->infoLog(' prepareGuestUsersCustomAttributes() :  ' . $e->getMessage(), []);
        }
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

    public function getCustomerNameInfo($order, $shippingAddress, $billingAddress) {
        $firstName = null;
        $lastName = null;

        if ($order->getCustomerFirstName()) {
            $firstName = $order->getCustomerFirstName();
            $lastName = $order->getCustomerLastName();
        } elseif ($shippingAddress && $shippingAddress->getFirstName()) {
            $firstName = $shippingAddress->getFirstName();
            $lastName = $shippingAddress->getLastName();
        } elseif ($billingAddress && $billingAddress->getFirstName()) {
            $firstName = $billingAddress->getFirstName();
            $lastName = $billingAddress->getLastName();
        }

        return [
            'first_name' => $firstName,
            'last_name' => $lastName
        ];
    }

    private function trackingDataExists($shipments) {
        return !is_null($shipments['tracking_company']) && !is_null($shipments['tracking_number']);
    }

    private function generateTrackingUrl($trackingCompany, $trackingNumber) {
        $trackingCompany = strtoupper($trackingCompany);
        switch ($trackingCompany) {
            case 'UPS':
                return "https://www.ups.com/WebTracking?loc=en_US&requester=ST&trackNums={$trackingNumber}";
            case 'USPS':
                return "https://tools.usps.com/go/TrackConfirmAction_input?strOrigTrackNum={$trackingNumber}";
            case 'FEDEX':
                return "https://www.fedex.com/fedextrack/?action=track&trackingnumber={$trackingNumber}";
            case 'DHL':
                return "https://www.dhl.com/us-en/home/tracking.html?tracking-id={$trackingNumber}";
            default:
                return null;
        }
    }
}
