<?php

/**
 * Orkestapay_Checkout payment method model
 *
 * @category    Orkestapay
 * @package     Orkestapay_Checkout
 * @author      Federico Balderas
 * @copyright   Orkestapay (http://orkestapay.com)
 * @license     http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0
 */

namespace Orkestapay\Checkout\Model;

use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Payment\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Store\Model\StoreManagerInterface;
use Orkestapay\Checkout\Model\Utils\OrkestapayRequest;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Checkout\Model\Session as CheckoutSession;

class Payment extends AbstractMethod
{
    const CODE = 'orkestapay_checkout';

    protected $_code = self::CODE;
    protected $_isGateway = true;
    protected $_canOrder = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canAuthorize = true;
    protected $_canVoid = true;
    protected $is_sandbox;
    protected $is_active;
    protected $client_id = null;
    protected $client_secret = null;
    protected $whsec = null;
    protected $scopeConfig;
    protected $logger_interface;
    protected $_storeManager;
    protected $orkestapayRequest;
    protected $_checkoutSession;

    /**
     * @var Customer
     */
    protected $customerModel;
    /**
     * @var CustomerSession
     */
    protected $customerSession;

    protected $orkestapayCustomerFactory;

    /**
     *  @var \Magento\Framework\App\Config\Storage\WriterInterface
     */
    protected $configWriter;

    /**
     *
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger $logger
     * @param \Magento\Framework\Module\ModuleListInterface $moduleList
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate
     * @param array $data
     * @param \Magento\Store\Model\StoreManagerInterface $data
     * @param WriterInterface $configWriter
     * @param OrkestapayRequest $orkestapayRequest
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        Logger $logger,
        \Psr\Log\LoggerInterface $logger_interface,
        Customer $customerModel,
        CustomerSession $customerSession,
        OrkestapayRequest $orkestapayRequest,
        CheckoutSession $checkoutSession,
        array $data = []
    ) {
        parent::__construct($context, $registry, $extensionFactory, $customAttributeFactory, $paymentData, $scopeConfig, $logger, null, null, $data);

        $this->_checkoutSession = $checkoutSession;
        $this->customerModel = $customerModel;
        $this->customerSession = $customerSession;
        $this->_storeManager = $storeManager;
        $this->logger_interface = $logger_interface;
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->_canRefund = true;
        $this->_canRefundInvoicePartial = true;

        $this->title = $this->getConfigData('title');
        $this->is_active = $this->getConfigData('active');
        $this->is_sandbox = $this->getConfigData('is_sandbox');
        $this->client_id = $this->getConfigData('client_id');
        $this->client_secret = $this->getConfigData('client_secret');
        $this->whsec = $this->getConfigData('whsec');

        $this->orkestapayRequest = $orkestapayRequest;
    }

    /**
     * Refund capture
     *
     * @param \Magento\Framework\DataObject|\Magento\Payment\Model\InfoInterface|Payment $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();
        $trx_id = $payment->getTransactionId();
        $orkestapay_payment_id = str_replace('-refund', '', $trx_id);

        $this->logger_interface->debug('#refund', ['$orkestapay_payment_id' => $orkestapay_payment_id, '$order_id' => $order->getIncrementId(), '$status' => $order->getStatus(), '$amount' => $amount]);

        if ($amount <= 0) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Invalid amount for refund.'));
        }

        try {
            $refundData = [
                'description' => 'Refund requested from Magento',
                'amount' => $amount,
            ];

            $this->createOrkestapayRefund($orkestapay_payment_id, $refundData);
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\LocalizedException(__($e->getMessage()));
        }

        return $this;
    }

    /**
     * Send capture request to gateway
     *
     * @param \Magento\Framework\DataObject|\Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function order(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();
        $this->logger_interface->debug('#order', ['$order_id' => $order->getIncrementId(), '$status' => $order->getStatus(), '$amount' => $amount]);

        if ($amount <= 0) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Invalid amount.'));
        }

        return $this;
    }

    public function createOrkestapayCheckout($data): array
    {
        $credentials = ['client_id' => $this->client_id, 'client_secret' => $this->client_secret, 'grant_type' => 'client_credentials'];
        $orkestaOrder = $this->orkestapayRequest->make('/v1/checkouts', $this->is_sandbox, $credentials, "POST", $data);
        return $orkestaOrder;
    }

    public function getOrkestapayPayment($payment_id)
    {
        $credentials = ['client_id' => $this->client_id, 'client_secret' => $this->client_secret, 'grant_type' => 'client_credentials'];
        $orkesta_payment = $this->orkestapayRequest->make("/v1/payments/" . $payment_id, $this->is_sandbox, $credentials);
        return $orkesta_payment;
    }

    public function createOrkestapayRefund($orkestapay_payment_id, $data)
    {
        $credentials = ['client_id' => $this->client_id, 'client_secret' => $this->client_secret, 'grant_type' => 'client_credentials'];
        $idempotency_key = $orkestapay_payment_id . '-' . time();
        $complete_payment = $this->orkestapayRequest->make('/v1/payments/' . $orkestapay_payment_id . '/refund', $this->is_sandbox, $credentials, "POST", $data, $idempotency_key);
        return $complete_payment;
    }

    public function getOrkestapayPaymentMethod($payment_method_id)
    {
        $credentials = ['client_id' => $this->client_id, 'client_secret' => $this->client_secret, 'grant_type' => 'client_credentials'];
        $orkestaPaymentMethod = $this->orkestapayRequest->make("/v1/payment-methods/" . $payment_method_id, $this->is_sandbox, $credentials);
        return $orkestaPaymentMethod;
    }

    public function getBaseUrlStore()
    {
        $base_url = $this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_LINK);
        return $base_url;
    }

    public function isLoggedIn()
    {
        return $this->customerSession->isLoggedIn();
    }

    /**
     * Determine method availability based on quote amount and config data
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        return parent::isAvailable($quote);
    }

    /**
     * @return boolean
     */
    public function isSandbox()
    {
        return $this->is_sandbox;
    }

    public function validateSettings()
    {
        return true;
    }

    public function getCode()
    {
        return $this->_code;
    }

    public function getOrkestaCheckoutData($order)
    {
        /** @var \Magento\Sales\Model\Order\Address $shipping */
        $shipping = $order->getShippingAddress();
        /** @var \Magento\Sales\Model\Order\Address $shipping */
        $billing = $order->getBillingAddress();

        $customerEmail = $order->getCustomerEmail() ?: $billing->getEmail();

        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                'product_id' => $item->getProductId(),
                'name' => $item->getName(),
                'quantity' => $item->getQty(),
                'unit_price' => round($item->getPrice(), 2),
            ];
        }

        $totalDiscount = abs(round($order->getDiscountAmount(), 2));
        $shippingAmount = round($order->getShippingAmount(), 2);


        $data = [
            'completed_redirect_url' => $this->getBaseUrlStore() . 'orkestapay/checkout/success',
            'canceled_redirect_url' => $this->getBaseUrlStore() . 'orkestapay/checkout/cancel',
            'locale' => 'ES_LATAM',
            'allow_save_payment_methods' => false,
            'order' => [
                'merchant_order_id' => $order->getIncrementId(),
                'currency' => $order->getBaseCurrencyCode(),
                'country_code' => $billing->getCountryId(),
                'products' => $items,
                'subtotal_amount' => round($order->getSubtotal(), 2),
                'total_amount' => round($order->getBaseGrandTotal(), 2),
                'customer' => [
                    'first_name' => $billing->getFirstname(),
                    'last_name' => $billing->getLastname(),
                    'email' => $customerEmail,
                ],
                'shipping_address' => [
                    'first_name' => $shipping->getFirstname(),
                    'last_name' => $shipping->getLastname(),
                    'email' => $customerEmail,
                    'line_1' => $shipping->getStreetLine(1),
                    'line_2' => $shipping->getStreetLine(2),
                    'city' => $shipping->getCity(),
                    'state' => $shipping->getRegion(),
                    'country' => $shipping->getCountryId(),
                    'zip_code' => $shipping->getPostcode(),
                ],
            ]
        ];

        if ($totalDiscount > 0) {
            $data['discounts'][] = ['amount' => $totalDiscount];
        }

        if ($shippingAmount > 0) {
            $data['shipping_details']['amount'] = $shippingAmount;
        }

        return $data;
    }
}
