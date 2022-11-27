<?php
/**
 * @package     Born\VertexTax
 * @author      Peter Warmenhoven <peter.warmenhoven@hanes.com>
 * @copyright   Copyright © 2020 Hanesbrands, Inc. All Rights Reserved.
 */
namespace Born\VertexTax\Plugin\Quote\Model;

use Born\VertexTax\Helper\ConfigHelper as VertexHelper;
use Magento\Quote\Model\QuoteManagement as MageQuoteManagement;
use Magento\Quote\Model\Quote as QuoteEntity;
use Born\VertexTax\Helper\Shipping;
use Magento\Framework\Serialize\SerializerInterface;
use Born\Core\Helper\ConfigHelper;

/**
 * Class QuoteManagement
 *
 * @package Born\VertexTax\Plugin\Quote\Model
 */
class QuoteManagement
{
    /**
     * @var Shipping
     */
    protected $helper;

    /**
     * @var SerializerInterface
     */
    protected $serializer;

    /**
     * @var ConfigHelper
     */
    private $configHelper;

    /**
     * TaxCalculator constructor.
     *
     * @param Shipping $helper
     * @param SerializerInterface $serializer
     * @param ConfigHelper $configHelper
     */
    public function __construct(
        Shipping $helper,
        SerializerInterface $serializer,
        ConfigHelper $configHelper
    ) {
        $this->serializer   = $serializer;
        $this->helper       = $helper;
        $this->configHelper = $configHelper;
    }

    /**
     * Adds tax data for Order API
     *
     * @param MageQuoteManagement $management
     * @param QuoteEntity $quote
     * @param array $orderData
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSubmit(MageQuoteManagement $management, QuoteEntity $quote, $orderData = [])
    {
        if ($this->configHelper->isModuleEnabled(VertexHelper::MODULE_NAME)) {
            $shipmentTotal        = $quote->getShippingAddress()->getShippingAmount();
            $remainingShippingTax = $shippingTotalTax = $quote->getShippingAddress()->getData('shipping_tax_amount');
            $quoteQty             = $quote->getItemsQty();

            foreach($quote->getAllVisibleItems() as $visibleItem) {
                if ($visibleItem->getFreeShipping()){
                    $quoteQty -= 1;
                }
            }

            foreach ($quote->getAllVisibleItems() as $quoteItem) {
                $shippingCost = $this->helper->splitShippingAmount($quoteItem, $quote->getShippingAddress());
                $shippingCost = round($shippingCost, 2);
                $itemQty      = $quoteItem->getQty();

                if (!$quoteItem->getFreeShipping()) {
                    $shippingTaxPerItemGroup = round($shippingTotalTax / $quoteQty * $itemQty, 2);
                } else {
                    $shippingTaxPerItemGroup = 0;
                }

                $taxAmount  = $quoteItem->getTaxAmount();
                $taxPercent = $quoteItem->getTaxPercent();

                if ($shippingTaxPerItemGroup <= $remainingShippingTax) {
                    $taxAmount            += $shippingTaxPerItemGroup;
                    $remainingShippingTax -= $shippingTaxPerItemGroup;
                } elseif (!$quoteItem->getFreeShipping()) {
                    $taxAmount += $remainingShippingTax;
                }

                $taxData = [
                    'taxAmount' => $taxAmount,
                    'taxName' => 'local tax',
                    'taxPercentage' => $taxPercent
                ];

                if (is_array($this->serializer->unserialize($quoteItem->getData('vertex_item_tax_data')))) {
                    $taxData = array_merge($taxData, $this->serializer->unserialize($quoteItem->getData('vertex_item_tax_data')));
                }

                $quoteItem->setData('vertex_item_tax_data', $this->serializer->serialize($taxData));

                if ($shippingCost <= $shipmentTotal) {
                    $amountPerUnit = round($shippingCost / $quoteItem->getQty(), 2);
                    $shipmentData = [
                        'amountPerLine'     => $shippingCost,
                        'amountPerUnit'     => $amountPerUnit,
                        'shippingName'      => '',
                        'amountPerLastUnit' => $amountPerUnit - abs($shippingCost - $amountPerUnit * $itemQty),
                    ];
                    $shipmentTotal -= $shippingCost;
                } else {
                    $amountPerUnit = round($shipmentTotal / $quoteItem->getQty(), 2);
                    $shipmentData = [
                        'amountPerLine'     => $shipmentTotal,
                        'amountPerUnit'     => $amountPerUnit,
                        'shippingName'      => '',
                        'amountPerLastUnit' => $amountPerUnit - abs($shipmentTotal - $amountPerUnit * $itemQty),
                    ];
                }

                $quoteItem->setData('item_shipping_costs', $this->serializer->serialize($shipmentData));
            }
        }

        return [$quote, $orderData];
    }
}