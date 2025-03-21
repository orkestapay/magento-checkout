<?php

/**
 * Copyright © 2015 Pay.nl All rights reserved.
 */

namespace Orkestapay\Checkout\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Orkestapay\Checkout\Model\Payment as OrkestapayPayment;
use Magento\Checkout\Model\Cart;

class OrkestapayConfigProvider implements ConfigProviderInterface
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var string[]
     */
    protected $methodCodes = [
        'orkestapay_checkout',
    ];

    /**
     * @var \Magento\Payment\Model\Method\AbstractMethod[]
     */
    protected $methods = [];

    /**
     * @var \Orkestapay\Checkout\Model\Payment
     */
    protected $payment;

    protected $cart;


    /**
     * @param \Psr\Log\LoggerInterface $logger
     * @param PaymentHelper $paymentHelper
     * @param OrkestapayPayment $payment
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        PaymentHelper $paymentHelper,
        OrkestapayPayment $payment,
        Cart $cart
    ) {
        foreach ($this->methodCodes as $code) {
            $this->methods[$code] = $paymentHelper->getMethodInstance($code);
        }
        $this->logger = $logger;
        $this->cart = $cart;
        $this->payment = $payment;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig()
    {
        $config = [];
        foreach ($this->methodCodes as $code) {
            if ($this->methods[$code]->isAvailable()) {
                $config['payment']['create_checkout_url'] = $this->payment->getBaseUrlStore() . 'orkesta/payment/checkout';
            }
        }

        return $config;
    }
}
