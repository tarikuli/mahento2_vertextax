/**
 * Copyright © Hanesbrands, Inc. All rights reserved.
 */

define([
    'uiRegistry',
    'mage/utils/wrapper'
], function (registry, wrapper) {
    'use strict';

    return function (target) {
        if (typeof window.checkoutConfig === 'undefined') {
            return target;
        }

        const config = window.checkoutConfig.vertexAddressValidationConfig || {};

        if (!config.isAddressValidationEnabled) {
            return target;
        }

        const validationMessage = registry.get(
            'checkout.steps.shipping-step.shippingAddress' +
            '.before-shipping-method-form.shippingAdditional'
        );

        target.setSelectedShippingAddress = wrapper.wrap(target.setSelectedShippingAddress, function (original, args) {
            const addressValidator = registry.get(
                'checkout.steps.shipping-step.shippingAddress' +
                '.before-shipping-method-form.shippingAdditional' +
                '.address-validation-message.validator'
            );

            addressValidator.isAddressValid = false;
            validationMessage.clear();

            return original(args);
        });

        return target;
    }
});
