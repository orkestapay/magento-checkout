<?php

/**
 * @category    Payments
 * @package     Orkestapay_Checkout
 * @author      Federico Balderas
 * @copyright   Orkestapay (http://orkestapay.com)
 * @license     http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0
 */

namespace Orkestapay\Checkout\Controller\Payment;

use Orkestapay\Checkout\Model\Payment as OrkestapayPayment;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\Action;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Framework\App\RequestInterface;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface as TransactionBuilder;


class Success extends Action
{
    protected $checkoutSession;
    protected $cartManagement;
    protected $order;
    protected $orkestaPayment;
    protected $logger;
    protected $cartRepository;
    protected $request;
    protected $transactionBuilder;

    /**
     * @var InvoiceService
     */
    protected $invoiceService;

    /**
     * @var Transaction
     */
    protected $transaction;

    /**
     *
     * @param Context $context
     * @param CartManagementInterface $cartManagement
     * @param CartRepositoryInterface $cartRepository
     * @param checkoutSession $checkoutSession
     * @param Order $order
     * @param RequestInterface $request
     * @param OrkestapayPayment $orkestaPayment
     * @param \Psr\Log\LoggerInterface $logger_interface
     *
     */
    public function __construct(
        Context $context,
        CartManagementInterface $cartManagement,
        CartRepositoryInterface $cartRepository,
        CheckoutSession $checkoutSession,
        Order $order,
        RequestInterface $request,
        OrkestapayPayment $payment,
        \Psr\Log\LoggerInterface $logger_interface,
        InvoiceService $invoiceService,
        Transaction $transaction,
        TransactionBuilder $transactionBuilder
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->cartManagement = $cartManagement;
        $this->cartRepository = $cartRepository;
        $this->order = $order;
        $this->request = $request;
        $this->orkestaPayment = $payment;
        $this->logger = $logger_interface;
        $this->invoiceService = $invoiceService;
        $this->transaction = $transaction;
        $this->transactionBuilder = $transactionBuilder;

        parent::__construct($context);
    }
    /**
     * Load the page defined in view/frontend/layout/orkestapay_index_webhook.xml
     * URL /orkestapay/payment/success
     *
     * @url https://magento.stackexchange.com/questions/197310/magento-2-redirect-to-final-checkout-page-checkout-success-failed?rq=1
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        try {
            // Obtener el valor del parámetro 'payment_id'
            $orkestapay_payment_id = $this->request->getParam('payment_id');
            $this->logger->debug('#SUCCESS', array('orkestapay_payment_id' => $orkestapay_payment_id));

            // Obtener la cotización del carrito actual
            $quote = $this->checkoutSession->getQuote();

            // Verificar si la cotización es válida y tiene productos
            if (!$quote->getId()) {
                throw new LocalizedException(__('The cart has no products.'));
            }

            // Obtener el pago de Orkestapay
            $orkestapay_payment = $this->orkestaPayment->getOrkestapayPayment($orkestapay_payment_id);
            $this->logger->debug('#SUCCESS', array('orkestapay_payment' => $orkestapay_payment));

            if (!$orkestapay_payment) {
                throw new LocalizedException(__('Orkestapay payment not found.'));
            }

            if ($orkestapay_payment['status'] != 'COMPLETED') {
                throw new LocalizedException(__('Payment was not completed.'));
            }

            // TODO - Definir los datos del pago cuando se resuelva el ISSUE del servicio de método de pago
            // Obtener el método de pago de Orkestapay
            $payment_method_id = $orkestapay_payment['payment_source']['payment_method_id'];
            // $orkestapay_payment_method = $this->orkestaPayment->getOrkestapayPaymentMethod($payment_method_id);
            // $this->logger->debug('#SUCCESS', array('orkestapay_payment_method' => $orkestapay_payment_method));

            // $paymentData = [
            //     'method' => $this->orkestaPayment->getCode(),
            //     'orkestapay_token' => $payment_method_id,
            //     'device_session_id' => $orkestapay_payment['device_session_id'],
            //     'payment_id' => $orkestapay_payment['payment_id'],
            //     'cc_number' => $orkestapay_payment_method['card']['last_four'],
            //     'cc_exp_month' => $orkestapay_payment_method['card']['expiration_month'],
            //     'cc_exp_year' => $orkestapay_payment_method['card']['expiration_year'],
            //     'cc_type' => $this->orkestaPayment->getCCBrandCode($orkestapay_payment_method['card']['brand'])
            // ];

            // Definir el método de pago
            $paymentData = [
                'method' => $this->orkestaPayment->getCode(),
                'orkestapay_token' => $payment_method_id,
                'device_session_id' => $orkestapay_payment['device_session_id'],
                'payment_id' => $orkestapay_payment['payment_id']
            ];

            $this->logger->debug('#SUCCESS', array('paymentData' => $paymentData));

            $quote->getPayment()->importData($paymentData);

            // Recalcular totales
            $quote->collectTotals();

            // Guardar la cotización antes de realizar la orden
            $this->cartRepository->save($quote);

            // Convertir el carrito en una orden
            $orderId = $this->cartManagement->placeOrder($quote->getId());

            // Cargar la orden creada
            $order = $this->order->load($orderId);

            // Crear la factura y marcarla como pagada
            if ($order->canInvoice()) {
                $invoice = $this->invoiceService->prepareInvoice($order);
                $invoice->setTransactionId($orkestapay_payment['payment_id']);
                $invoice->register();
                $invoice->pay();
                $invoice->save();

                $transactionSave = $this->transaction
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder());
                $transactionSave->save();
            }

            $payment = $order->getPayment();
            $transaction = $this->transactionBuilder
                ->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($orkestapay_payment['payment_id'])
                ->setFailSafe(true)
                ->build(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE);

            // Asociar y guardar la transacción
            $payment->addTransactionCommentsToOrder($transaction, __('Transaction captured by Orkestapay.'));
            $payment->setParentTransactionId(null);
            $payment->save();
            $order->save();
            $transaction->save();

            // Mensaje de éxito
            $this->messageManager->addSuccessMessage(__('Order created successfully. Order ID: %1', $order->getIncrementId()));

            // Limpiar la sesión del carrito
            $this->checkoutSession->clearQuote();

            // Redireccionar a una página de éxito o donde sea necesario
            return $this->_redirect('checkout/onepage/success');
        } catch (LocalizedException $e) {
            $this->logger->error('#SUCCESS', array('message' => $e->getMessage(), 'code' => $e->getCode(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString()));
            // Mensaje de error
            $this->messageManager->addErrorMessage(__('Error creating order: %1', $e->getMessage()));
        } catch (\Exception $e) {
            $this->logger->error('#SUCCESS', array('message' => $e->getMessage(), 'code' => $e->getCode(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString()));
            // Manejo de error genérico
            $this->messageManager->addErrorMessage(__('An error has occurred: %1', $e->getMessage()));
        }

        // Redireccionar a la página de carrito si hay un error
        return $this->_redirect('checkout/cart');
    }
}
