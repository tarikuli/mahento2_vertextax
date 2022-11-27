/**
 * Copyright © Hanesbrands, Inc. All rights reserved.
 */

var config = {
    config: {
        mixins: {
            'Magento_Checkout/js/checkout-data': {
                'Vertex_AddressValidation/js/shipping-invalidate-mixin': false,
                'Born_VertexTax/js/overwritten-shipping-invalidate-mixin': true
            }
        }
    }
};
