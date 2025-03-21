<?php

/**
 * @category    Payments
 * @package     Orkestapay_Checkout
 * @author      Federico Balderas
 * @copyright   Orkestapay (http://orkestapay.com)
 * @license     http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0
 */

namespace Orkestapay\Checkout\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Orkestapay\Checkout\Model\Payment as Config;

/**
 * Class MerchantInfo
 */
class MerchantInfo implements ObserverInterface
{

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var ManagerInterface
     */
    protected $messageManager;

    /**
     * @param Config $config
     * @param ManagerInterface $messageManager
     */
    public function __construct(
        Config $config,
        ManagerInterface $messageManager
    ) {
        $this->config = $config;
        $this->messageManager = $messageManager;
    }

    /**
     * Create Webhook
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        return $this->config->validateSettings();
    }
}
