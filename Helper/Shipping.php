<?php
/**
 * Created by PhpStorm.
 * User: scandiweb
 * Date: 04/08/2019
 * Time: 23:04
 */

namespace Born\VertexTax\Helper;

use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;

class Shipping
{
    /**
     * Splits the shipping amount for the given quote item by the percentage of total cart value.
     *
     * @param Item $quoteItem
     * @param Address $address
     *
     * @return float|int
     */
    public function splitShippingAmount($quoteItem, $address)
    {
        if ($quoteItem->getFreeShipping()){
            return 0;
        }

        $subtotal = $address->getBaseSubtotal();

        foreach($address->getAllVisibleItems() as $visibleItem) {
            if ($visibleItem->getFreeShipping()) {
                $subtotal -= $visibleItem->getRowTotal();
            }
        }

        $rowTotal = $quoteItem->getRowTotal();

        if (!$subtotal) {
            $rowTotal = $subtotal = 1;
        }

        return ($rowTotal / $subtotal) * $address->getShippingAmount();
    }
}