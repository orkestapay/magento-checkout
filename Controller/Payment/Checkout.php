<?php

/**
 * @category    Payments
 * @package     Orkestapay_Checkout
 * @author      Federico Balderas
 * @license     http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0
 */

namespace Orkestapay\Checkout\Controller\Payment;

use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Checkout\Model\Cart;
use Orkestapay\Checkout\Model\Payment as OrkestapayPayment;
use Magento\Framework\UrlInterface;

class Checkout extends \Magento\Framework\App\Action\Action
{
    protected $payment;
    protected $logger;
    protected $cart;
    protected $urlBuilder;


    /**
     * @var \Magento\Quote\Model\QuoteManagement
     */
    protected $quoteManagement;

    /**
     *
     * @param Context $context
     * @param OrkestapayPayment $payment
     * @param \Psr\Log\LoggerInterface $logger_interface
     */
    public function __construct(Context $context, OrkestapayPayment $payment, \Psr\Log\LoggerInterface $logger_interface, Cart $cart, \Magento\Quote\Model\QuoteManagement $quoteManagement, UrlInterface $urlBuilder)
    {
        parent::__construct($context);
        $this->payment = $payment;
        $this->logger = $logger_interface;
        $this->cart = $cart;
        $this->quoteManagement = $quoteManagement;
        $this->urlBuilder = $urlBuilder;
    }

    public function execute()
    {
        $data = null;

        try {
            // Create Orkestapay Checkout
            $checkout_data = $this->getOrkestaCheckoutData();
            $this->logger->debug('checkout_data ====> ' . json_encode($checkout_data));
            $orkestapay_checkout = $this->payment->createOrkestapayCheckout($checkout_data);
            $this->logger->debug('orkestapay_checkout ====> ' . json_encode($orkestapay_checkout));

            // Datos que se van a devolver al front
            $data = $orkestapay_checkout;
        } catch (\Exception $e) {
            $this->logger->error('#order', ['msg' => $e->getMessage()]);
            $data = [
                'error' => true,
                'message' => $e->getMessage(),
            ];
        }

        $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $resultJson->setData($data);
        return $resultJson;
    }

    public function getOrkestaCheckoutData()
    {
        $base_url = $this->payment->getBaseUrlStore();
        $quote = $this->cart->getQuote();
        $billing_address = $quote->getBillingAddress();
        $shipping_address = $quote->getShippingAddress();
        $customerEmail = $quote->getCustomerEmail() ?: $quote->getBillingAddress()->getEmail();

        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $items[] = [
                'product_id' => $item->getProductId(),
                'name' => $item->getName(),
                'quantity' => $item->getQty(),
                'unit_price' => round($item->getPrice(), 2),
            ];
        }

        $totalItemsAmount = $quote->getSubtotal();
        $discount = $totalItemsAmount - $quote->getSubtotalWithDiscount();
        $totalDiscount = abs(round($discount, 2));
        $shippingAmount = round($quote->getShippingAddress()->getShippingAmount(), 2);


        $data = [
            'completed_redirect_url' => $base_url . 'orkesta/payment/success',
            'canceled_redirect_url' => $this->urlBuilder->getUrl('checkout'),
            'locale' => 'ES_LATAM',
            'allow_save_payment_methods' => false,
            'order' => [
                'merchant_order_id' => $quote->getId() . '-' . time(),
                'currency' => $quote->getBaseCurrencyCode(),
                'country_code' => $billing_address->getCountryId(),
                'products' => $items,
                'subtotal_amount' => round($totalItemsAmount, 2),
                'total_amount' => round($quote->getBaseGrandTotal(), 2),
                'customer' => [
                    'first_name' => $billing_address->getFirstname(),
                    'last_name' => $billing_address->getLastname(),
                    'email' => $customerEmail,
                ],
                'shipping_address' => [
                    'first_name' => $shipping_address->getFirstname(),
                    'last_name' => $shipping_address->getLastname(),
                    'email' => $customerEmail,
                    'line_1' => $shipping_address->getStreetLine(1),
                    'line_2' => $shipping_address->getStreetLine(2),
                    'city' => $shipping_address->getCity(),
                    'state' => $shipping_address->getRegion(),
                    'country' => $shipping_address->getCountryId(),
                    'zip_code' => $shipping_address->getPostcode(),
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
