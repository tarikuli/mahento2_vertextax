<?php
/**
 * @vendor Born
 * @package Born_VertexTax
 */

namespace Born\VertexTax\Model;

use Born\Core\Helper\ConfigHelper as BornCoreHelper;
use Born\VertexTax\Helper\ConfigHelper;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Tax\Api\Data\AppliedTaxInterface;
use Magento\Tax\Api\Data\AppliedTaxInterfaceFactory;
use Magento\Tax\Api\Data\AppliedTaxRateInterface;
use Magento\Tax\Api\Data\AppliedTaxRateInterfaceFactory;
use Magento\Tax\Api\Data\QuoteDetailsInterface;
use Magento\Tax\Api\Data\QuoteDetailsItemInterface;
use Magento\Tax\Api\Data\TaxDetailsInterface;
use Magento\Tax\Api\Data\TaxDetailsInterfaceFactory;
use Magento\Tax\Api\Data\TaxDetailsItemInterface;
use Magento\Tax\Api\Data\TaxDetailsItemInterfaceFactory;
use Vertex\Data\LineItemInterface;
use Vertex\Data\TaxInterface;
use Vertex\Tax\Model\AddressDeterminer;
use Vertex\Tax\Model\Api\Data\QuotationRequestBuilder;
use Vertex\Tax\Model\Api\Utility\PriceForTax;
use Vertex\Tax\Model\Calculator as VertexCalculator;
use Vertex\Tax\Model\Config;
use Vertex\Tax\Model\Config\Source\SummarizeTax;
use Vertex\Tax\Model\ExceptionLogger;
use Vertex\Tax\Model\IncompleteAddressDeterminer;
use Vertex\Tax\Model\QuoteIsVirtualDeterminer;
use Vertex\Tax\Model\TaxQuote\TaxQuoteRequest;

/**
 * Class Calculator.
 *
 * Introduces Vertex fallback tax percentage in case API fails.
 * Full override of the class because of the method private accesses.
 *
 * @package Born\VertexTax\Model
 */
class Calculator extends VertexCalculator
{
    /**
     * @var bool
     */
    private $addMessageToVertexGroup;

    /**
     * @var AppliedTaxInterfaceFactory
     */
    private $appliedTaxFactory;

    /**
     * @var AppliedTaxRateInterfaceFactory
     */
    private $appliedTaxRateFactory;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var ExceptionLogger
     */
    private $logger;

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @var PriceCurrencyInterface
     */
    private $priceCurrency;

    /**
     * @var TaxQuoteRequest
     */
    private $quoteRequest;

    /**
     * @var QuotationRequestBuilder
     */
    private $requestFactory;

    /**
     * @var TaxDetailsInterfaceFactory
     */
    private $taxDetailsFactory;

    /**
     * @var TaxDetailsItemInterfaceFactory
     */
    private $taxDetailsItemFactory;

    /**
     * @var ConfigHelper
     */
    private $configHelper;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var BornCoreHelper
     */
    private $bornHelper;

    /**
     * Calculator constructor.
     *
     * @param TaxDetailsInterfaceFactory $taxDetailsFactory
     * @param TaxDetailsItemInterfaceFactory $taxDetailsItemFactory
     * @param QuotationRequestBuilder $requestFactory
     * @param TaxQuoteRequest $quoteRequest
     * @param AppliedTaxInterfaceFactory $appliedTaxFactory
     * @param AppliedTaxRateInterfaceFactory $appliedTaxRateFactory
     * @param PriceCurrencyInterface $priceCurrency
     * @param ExceptionLogger $logger
     * @param Config $config
     * @param ManagerInterface $messageManager
     * @param ConfigHelper $configHelper
     * @param SerializerInterface $serializer
     * @param BornCoreHelper $bornHelper
     * @param QuoteIsVirtualDeterminer $isVirtualDeterminer
     * @param AddressDeterminer $addressDeterminer
     * @param IncompleteAddressDeterminer $incompleteAddressDeterminer
     * @param PriceForTax $priceForTaxCalculation
     * @param bool $addMessageToVertexGroup
     */
    public function __construct(
        TaxDetailsInterfaceFactory $taxDetailsFactory,
        TaxDetailsItemInterfaceFactory $taxDetailsItemFactory,
        QuotationRequestBuilder $requestFactory,
        TaxQuoteRequest $quoteRequest,
        AppliedTaxInterfaceFactory $appliedTaxFactory,
        AppliedTaxRateInterfaceFactory $appliedTaxRateFactory,
        PriceCurrencyInterface $priceCurrency,
        ExceptionLogger $logger,
        Config $config,
        ManagerInterface $messageManager,
        ConfigHelper $configHelper,
        SerializerInterface $serializer,
        BornCoreHelper $bornHelper,
        QuoteIsVirtualDeterminer $isVirtualDeterminer,
        AddressDeterminer $addressDeterminer,
        IncompleteAddressDeterminer $incompleteAddressDeterminer,
        PriceForTax $priceForTaxCalculation,
        $addMessageToVertexGroup = true
    ) {
        parent::__construct(
            $taxDetailsFactory,
            $taxDetailsItemFactory,
            $requestFactory,
            $quoteRequest,
            $appliedTaxFactory,
            $appliedTaxRateFactory,
            $priceCurrency,
            $logger,
            $config,
            $messageManager,
            $priceForTaxCalculation,
            $addressDeterminer,
            $addMessageToVertexGroup
        );

        $this->taxDetailsFactory = $taxDetailsFactory;
        $this->requestFactory = $requestFactory;
        $this->quoteRequest = $quoteRequest;
        $this->taxDetailsItemFactory = $taxDetailsItemFactory;
        $this->appliedTaxFactory = $appliedTaxFactory;
        $this->appliedTaxRateFactory = $appliedTaxRateFactory;
        $this->priceCurrency = $priceCurrency;
        $this->logger = $logger;
        $this->config = $config;
        $this->messageManager = $messageManager;
        $this->addMessageToVertexGroup = $addMessageToVertexGroup;
        $this->configHelper = $configHelper;
        $this->serializer = $serializer;
        $this->bornHelper = $bornHelper;
    }

    /**
     * Calculate Taxes
     *
     * @param QuoteDetailsInterface $quoteDetails
     * @param string|null $scopeCode
     * @param bool $round
     *
     * @return TaxDetailsInterface
     */
    public function calculateTax(QuoteDetailsInterface $quoteDetails, $scopeCode, bool $round = true): TaxDetailsInterface
    {
        if ($this->bornHelper->isModuleEnabled(ConfigHelper::MODULE_NAME)) {
            $items = $quoteDetails->getItems();
            if (empty($items)
                || ($quoteDetails->getBillingAddress() === null && $quoteDetails->getShippingAddress() === null)
                || $this->onlyShipping($items)
                || $quoteDetails->getSkipTaxCalculation()
            ) {
                /*
                 * Don't perform calculation when:
                 * - There are no items
                 * - There is no address
                 * - The only item is shipping
                 */
                return $this->createEmptyDetails($quoteDetails);
            }

            if ($this->configHelper->isVertexDevFailEnabled()) {
                return $this->createEmptyDetails($quoteDetails, true);
            }

            try {
                $request = $this->requestFactory->buildFromQuoteDetails($quoteDetails, $scopeCode);
                // Send to Vertex!
                $result = $this->quoteRequest->taxQuote($request, $scopeCode);
            } catch (\Exception $e) {
                $this->logger->critical($e);
                $group = $this->addMessageToVertexGroup ? self::MESSAGE_KEY : null;
                // Clear previous Vertex error messages
                $this->messageManager->getMessages(true, $group);

                if (!$this->configHelper->isVertexTaxFallbackEnabled()) {
                    $this->messageManager->addErrorMessage(
                        __('Unable to calculate taxes. This could be caused by an invalid address provided in checkout.'),
                        $group
                    );
                }

                return $this->createEmptyDetails($quoteDetails, true);
            }

            /** @var LineItemInterface[] $resultItems */
            $resultItems = [];
            foreach ($result->getLineItems() as $lineItem) {
                $resultItems[$lineItem->getLineItemId()] = $lineItem;
            }

            /** @var TaxDetailsInterface $taxDetails */
            $taxDetails = $this->taxDetailsFactory->create();
            $taxDetails->setSubtotal(0)
                ->setTaxAmount(0)
                ->setAppliedTaxes([]);

            /** @var QuoteDetailsItemInterface[] $processItems Line items we need to process taxes for */
            $processItems = [];
            /** @var QuoteDetailsItemInterface[] $childrenByParent Child line items indexed by parent code */
            $childrenByParent = [];
            /** @var TaxDetailsItemInterface[] $processedItems Processed Line items */
            $processedItems = [];

            /*
             * Here we separate items into top-level and child items.  The children will be processed separately and then
             * added together for the parent item
             */
            foreach ($quoteDetails->getItems() as $item) {
                if ($item->getParentCode()) {
                    $childrenByParent[$item->getParentCode()][] = $item;
                } else {
                    $processItems[$item->getCode()] = $item;
                }
            }

            foreach ($processItems as $item) {
                if (isset($childrenByParent[$item->getCode()])) { // If this top-level item has child products
                    /** @var TaxDetailsItemInterface[] $processedChildren To be used to figure out our top-level details */
                    $processedChildren = [];

                    // Process the children first, our top-level product will be the combination of them
                    foreach ($childrenByParent[$item->getCode()] as $child) {
                        /** @var QuoteDetailsItemInterface $child */

                        $resultItem = $resultItems[$child->getCode()];
                        $processedItem = $resultItem
                            ? $this->createTaxDetailsItem($child, $resultItem, $round)
                            : $this->createEmptyDetailsTaxItem($child);

                        // Add this item's tax information to the quote aggregate
                        $this->aggregateTaxData($taxDetails, $processedItem);

                        $processedItems[$processedItem->getCode()] = $processedItem;
                        $processedChildren[] = $processedItem;
                    }
                    /** @var TaxDetailsItemInterface $processedItem */
                    $processedItem = $this->taxDetailsItemFactory->create();
                    $processedItem->setCode($item->getCode())
                        ->setType($item->getType());

                    $rowTotal = 0.0;
                    $rowTotalInclTax = 0.0;
                    $rowTax = 0.0;
                    // Combine the totals from the children
                    foreach ($processedChildren as $child) {
                        $rowTotal += $child->getRowTotal();
                        $rowTotalInclTax += $child->getRowTotalInclTax();
                        $rowTax += $child->getRowTax();
                    }

                    $price = $rowTotal / $item->getQuantity();
                    $priceInclTax = $rowTotalInclTax / $item->getQuantity();

                    $processedItem->setPrice($this->optionalRound($price, $round))
                        ->setPriceInclTax($this->optionalRound($priceInclTax, $round))
                        ->setRowTotal($this->optionalRound($rowTotal, $round))
                        ->setRowTotalInclTax($this->optionalRound($rowTotalInclTax, $round))
                        ->setRowTax($this->optionalRound($rowTax, $round));
                // Aggregation to $taxDetails takes place on the child level
                } else {
                    if (isset($resultItems[$item->getCode()])) {
                        $resultItem = $resultItems[$item->getCode()];
                        $processedItem = $resultItem
                            ? $this->createTaxDetailsItem($item, $resultItem, $round)
                            : $this->createEmptyDetailsTaxItem($item);

                        $this->aggregateTaxData($taxDetails, $processedItem);
                    }
                }

                $processedItems[$item->getCode()] = $processedItem;
            }
            $taxDetails->setItems($processedItems);

            return $taxDetails;
        }

        return parent::calculateTax($quoteDetails, $scopeCode, $round);
    }

    /**
     * Add tax details from an item to the overall tax details
     *
     * @param TaxDetailsInterface $taxDetails
     * @param TaxDetailsItemInterface $taxItemDetails
     *
     * @return void
     */
    private function aggregateTaxData(TaxDetailsInterface $taxDetails, TaxDetailsItemInterface $taxItemDetails)
    {
        $taxDetails->setSubtotal($taxDetails->getSubtotal() + $taxItemDetails->getRowTotal());
        $taxDetails->setTaxAmount($taxDetails->getTaxAmount() + $taxItemDetails->getRowTax());

        $itemAppliedTaxes = $taxItemDetails->getAppliedTaxes();
        if (empty($itemAppliedTaxes)) {
            return;
        }

        $appliedTaxes = $taxDetails->getAppliedTaxes();
        foreach ($itemAppliedTaxes as $taxId => $itemAppliedTax) {
            if (!isset($appliedTaxes[$taxId])) {
                $rates = [];
                $itemRates = $itemAppliedTax->getRates();
                foreach ($itemRates as $rate) {
                    /** @var AppliedTaxRateInterface $newRate */
                    $newRate = $this->appliedTaxRateFactory->create();
                    $newRate->setPercent($rate->getPercent())
                        ->setTitle($rate->getTitle())
                        ->setCode($rate->getCode());
                    $rates[] = $newRate;
                }

                /** @var AppliedTaxInterface $appliedTax */
                $appliedTax = $this->appliedTaxFactory->create();
                $appliedTax->setPercent($itemAppliedTax->getPercent())
                    ->setAmount($itemAppliedTax->getAmount())
                    ->setTaxRateKey($itemAppliedTax->getTaxRateKey())
                    ->setRates($rates);
            } else {
                $appliedTaxes[$taxId]->setAmount($appliedTaxes[$taxId]->getAmount() + $itemAppliedTax->getAmount());
            }
        }
        $taxDetails->setAppliedTaxes($appliedTaxes);
    }

    /**
     * Format an array of {@see TaxInterface} into applied taxes
     *
     * @param TaxInterface[] $taxes
     * @param string $lineItemId
     *
     * @return AppliedTaxInterface[]
     */
    private function createAppliedTaxes(array $taxes, $lineItemId)
    {
        $taxDetailType = SummarizeTax::PRODUCT_AND_SHIPPING;
        if ($lineItemId === static::TAX_TYPE_SHIPPING) {
            $taxDetailType = static::TAX_TYPE_SHIPPING;
        } elseif ($lineItemId === static::TAX_TYPE_QUOTE_GW
            || $lineItemId === static::TAX_TYPE_PRINTED_CARD_GW
            || strpos($lineItemId, 'item_gw') === 0) {
            $taxDetailType = static::TAX_TYPE_QUOTE_GW;
        }

        $appliedTaxes = [];
        foreach ($taxes as $tax) {
            $jurisdiction = $tax->getJurisdiction();
            if (!$jurisdiction) {
                continue;
            }
            if ($this->config->getSummarizeTax() === SummarizeTax::JURISDICTION) {
                $taxDetailType = $jurisdiction->getName();
            }

            /** @var AppliedTaxInterface $appliedTax */
            /** @var AppliedTaxRateInterface $rate */
            if (isset($appliedTaxes[$taxDetailType])) {
                $appliedTax = $appliedTaxes[$taxDetailType];
            } else {
                $appliedTax = $this->appliedTaxFactory->create();
                $appliedTax->setAmount(0);
                $appliedTax->setPercent(0);
                $appliedTax->setTaxRateKey($taxDetailType);

                $rate = $this->appliedTaxRateFactory->create();
                $rate->setPercent(0)
                    ->setCode($taxDetailType);

                $rate->setTitle($this->getTaxLabel($taxDetailType));
                $appliedTax->setRates([$rate]);
                $appliedTaxes[$taxDetailType] = $appliedTax;
            }

            $rate = $appliedTax->getRates()[0];
            $rate->setPercent($rate->getPercent() + ($tax->getEffectiveRate() * 100));

            $appliedTax->setAmount($appliedTax->getAmount() + $tax->getAmount());
            $appliedTax->setPercent($appliedTax->getPercent() + ($tax->getEffectiveRate() * 100));
        }

        return $appliedTaxes;
    }

    /**
     * Create an empty {@see TaxDetailsInterface}
     *
     * This method is used to provide Magento the information it expects while
     * avoiding a costly tax calculation when we don't want one (or think it
     * will provide no value)
     *
     * @param QuoteDetailsInterface $quoteDetails
     * @param bool $useFallback
     *
     * @return TaxDetailsInterface
     */
    private function createEmptyDetails(QuoteDetailsInterface $quoteDetails, $useFallback = false)
    {
        /** @var TaxDetailsInterface $details */
        $details = $this->taxDetailsFactory->create();

        $subtotal = $taxAmount = 0;
        $items = [];

        foreach ($quoteDetails->getItems() as $quoteItem) {
            $taxItem = $this->createEmptyDetailsTaxItem($quoteItem, $useFallback);
            $subtotal += $taxItem->getRowTotal();
            $taxAmount += $taxItem->getRowTax();
            // Magento has an undocumented assumption that tax detail items are indexed by code
            $items[$taxItem->getCode()] = $taxItem;
        }

        $details->setSubtotal($subtotal)
            ->setTaxAmount($taxAmount)
            ->setDiscountTaxCompensationAmount(0)
            ->setAppliedTaxes([])
            ->setItems($items);

        return $details;
    }

    /**
     * Create an empty {@see TaxDetailsItemInterface}
     *
     * This is used by {@see self::createEmptyDetails()}
     *
     * @param QuoteDetailsItemInterface $quoteDetailsItem
     * @param bool $useFallback
     *
     * @return TaxDetailsItemInterface
     */
    private function createEmptyDetailsTaxItem(QuoteDetailsItemInterface $quoteDetailsItem, $useFallback = false)
    {
        /** @var TaxDetailsItemInterface $taxDetailsItem */
        $taxDetailsItem = $this->taxDetailsItemFactory->create();

        $taxPercent = $rowTax = 0;
        $rowTotal = ($quoteDetailsItem->getUnitPrice() * $quoteDetailsItem->getQuantity());

        if ($useFallback && $this->configHelper->isVertexTaxFallbackEnabled()) {
            $taxPercent = $this->configHelper->getDefaultPercentage();
            $rowTax = $this->optionalRound($rowTotal * ($taxPercent / 100));
        }

        $taxDetailsItem->setCode($quoteDetailsItem->getCode())
            ->setType($quoteDetailsItem->getType())
            ->setRowTax($rowTax)
            ->setPrice($quoteDetailsItem->getUnitPrice())
            ->setPriceInclTax($quoteDetailsItem->getUnitPrice())
            ->setRowTotal($rowTotal)
            ->setRowTotalInclTax($this->optionalRound($rowTotal + $rowTax))
            ->setDiscountTaxCompensationAmount(0)
            ->setDiscountAmount($quoteDetailsItem->getDiscountAmount())
            ->setAssociatedItemCode($quoteDetailsItem->getAssociatedItemCode())
            ->setTaxPercent($taxPercent)
            ->setAppliedTaxes([]);

        return $taxDetailsItem;
    }

    /**
     * Create a {@see TaxDetailsItemInterface}
     *
     * Combines information from the {@see QuoteDetailsItemInterface} and resulting {@see LineItemInterface} to assemble
     * a complete {@see TaxDetailsItemInterface}
     *
     * @param QuoteDetailsItemInterface $quoteDetailsItem
     * @param LineItemInterface $vertexLineItem
     * @param bool $round
     *
     * @return TaxDetailsItemInterface
     */
    private function createTaxDetailsItem(
        QuoteDetailsItemInterface $quoteDetailsItem,
        LineItemInterface $vertexLineItem,
        $round = true
    ) {
        /** @var TaxDetailsItemInterface $taxDetailsItem */
        $taxDetailsItem = $this->taxDetailsItemFactory->create();

        // Combine the rates of all taxes applicable to the Line Item
        $effectiveRate = array_reduce(
            $vertexLineItem->getTaxes(),
            function ($result, TaxInterface $tax) {
                return $result + $tax->getEffectiveRate();
            },
            0
        );

        $this->doNotLetVertexRoundLineItemTotalTax($vertexLineItem);

        $perItemTax = $vertexLineItem->getTotalTax() / $vertexLineItem->getQuantity();

        $unitPrice = $vertexLineItem->getUnitPrice();

        // Vertex extended price is less discount - so add it back
        $extendedPrice = $vertexLineItem->getExtendedPrice() + $quoteDetailsItem->getDiscountAmount();

        $taxDetailsItem->setCode($vertexLineItem->getLineItemId())
            ->setType($quoteDetailsItem->getType())
            ->setRowTax($this->optionalRound($vertexLineItem->getTotalTax(), $round))
            ->setPrice($this->optionalRound($unitPrice, $round))
            ->setPriceInclTax($this->optionalRound($unitPrice + $perItemTax, $round))
            ->setRowTotal($this->optionalRound($extendedPrice, $round))
            ->setRowTotalInclTax($this->optionalRound($extendedPrice + $vertexLineItem->getTotalTax(), $round))
            ->setDiscountTaxCompensationAmount(0)
            ->setAssociatedItemCode($quoteDetailsItem->getAssociatedItemCode())
            ->setTaxPercent($effectiveRate * 100)
            ->setAppliedTaxes(
                $this->createAppliedTaxes(
                    $vertexLineItem->getTaxes(),
                    $vertexLineItem->getLineItemId()
                )
            );

        return $taxDetailsItem;
    }

    /**
     * Determine if an array of QuoteDetailsItemInterface contains only shipping entries
     *
     * @param QuoteDetailsItemInterface[] $items
     *
     * @return bool
     */
    private function onlyShipping(array $items)
    {
        foreach ($items as $item) {
            if ($item->getCode() !== 'shipping') {
                return false;
            }
        }

        return true;
    }

    /**
     * Round a number
     *
     * @param number $number
     * @param bool $round
     *
     * @return float
     */
    private function optionalRound($number, $round = true)
    {
        return $round ? $this->priceCurrency->round($number) : $number;
    }

    /**
     * Retrieve tax label
     *
     * @param string $code
     *
     * @return string
     */
    private function getTaxLabel($code)
    {
        switch ($code) {
            case SummarizeTax::PRODUCT_AND_SHIPPING:
                $title = __('Sales and Use')->render();
                break;
            case static::TAX_TYPE_QUOTE_GW:
            case static::TAX_TYPE_PRINTED_CARD_GW:
                $title = __('Gift Options')->render();
                break;
            case static::TAX_TYPE_SHIPPING:
                $title = __('Shipping')->render();
                break;
            default:
                $title = $code;
                break;
        }

        return $title;
    }

    /**
     * Adjust the {@see LineItemInterface} to prevent premature rounding
     *
     * @param LineItemInterface $vertexLineItem
     */
    private function doNotLetVertexRoundLineItemTotalTax($vertexLineItem)
    {
        $totalTax = 0;

        /** @var \Vertex\Data\Tax $tax */
        foreach ($vertexLineItem->getTaxes() as $tax) {
            if ($tax->getImposition() !== 'Retail Delivery Fee') {
                $calculatedTax = $vertexLineItem->getExtendedPrice() * $tax->getEffectiveRate();
                $tax->setAmount($calculatedTax);
            } else {
                $calculatedTax = $tax->getAmount();
            }

            $totalTax += $calculatedTax;
        }

        $vertexLineItem->setTotalTax($totalTax);
    }
}
