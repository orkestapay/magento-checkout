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
    "Magento_Checkout/js/view/payment/default",
    "jquery",
    "Magento_Checkout/js/model/full-screen-loader",
], function (Component, $, fullScreenLoader) {
    "use strict";

    var create_checkout_url = window.checkoutConfig.payment.create_checkout_url;

    return Component.extend({
        defaults: {
            template: "Orkestapay_Checkout/payment/orkestapay-checkout",
        },
        /**
         * Prepare and process payment information
         */
        preparePayment: async function () {
            var self = this;

            fullScreenLoader.startLoader();

            $.post(create_checkout_url, {})
                .done((response) => {
                    if (response.hasOwnProperty("error")) {
                        fullScreenLoader.stopLoader();

                        self.messageContainer.addErrorMessage({
                            message: response.message,
                        });
                        return;
                    }

                    window.location.href = response.checkout_redirect_url;
                    return;
                })
                .fail(function (jqXHR, textStatus, errorThrown) {
                    console.log("Error: " + textStatus); // Acción cuando la solicitud falla
                    fullScreenLoader.stopLoader();
                });
        },
    });
});
