/**
 * Orkestapay_Checkout Magento JS component
 *
 * @category    Orkestapay
 * @package     Orkestapay_Checkout
 * @author      Federico Balderas
 * @copyright   Orkestapay (http://orkestapay.com)
 * @license     http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0
 */
/*browser:true*/
/*global define*/
define([
    "uiComponent",
    "Magento_Checkout/js/model/payment/renderer-list",
], function (Component, rendererList) {
    "use strict";
    rendererList.push({
        type: "orkestapay_checkout",
        component:
            "Orkestapay_Checkout/js/view/payment/method-renderer/orkestapay-method",
    });
    /** Add view logic here if needed */
    return Component.extend({});
});
