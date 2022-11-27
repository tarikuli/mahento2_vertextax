<?php

declare(strict_types=1);

/**
 * @copyright Copyright © Hanesbrands, Inc. All rights reserved.
 */

namespace Born\VertexTax\Plugin\Webapi;

use Born\VertexTax\Helper\ConfigHelper;
use Magento\Backend\Model\Session;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote\Item\ToOrderItem;
use Magento\Quote\Api\Data\AddressInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentCommentCreationInterface;
use Magento\Sales\Api\Data\ShipmentCreationArgumentsInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ShipOrder;

/**
 * Class ShipOrderPlugin
 *
 * @package Born\Sales\Plugin\Webapi
 */
class ShipOrderPlugin
{
    /***
     * @var ToOrderItem
     */
    private $toOrderItem;

    /**
     * @var CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var ConfigHelper
     */
    private $configHelper;

    /**
     * @var \Magento\Quote\Api\Data\AddressInterfaceFactory
     */
    private $addressInterfaceFactory;

    /**
     * @var \Magento\Backend\Model\Session
     */
    protected $session;

    /**
     * ShipOrderPlugin constructor.
     *
     * @param ToOrderItem $toOrderItem
     * @param CartRepositoryInterface $quoteRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param ConfigHelper $configHelper
     * @param AddressInterfaceFactory $addressInterfaceFactory
     */
    public function __construct(
        ToOrderItem $toOrderItem,
        CartRepositoryInterface $quoteRepository,
        OrderRepositoryInterface $orderRepository,
        ConfigHelper $configHelper,
        AddressInterfaceFactory $addressInterfaceFactory,
        Session $session
    ) {
        $this->toOrderItem = $toOrderItem;
        $this->quoteRepository = $quoteRepository;
        $this->orderRepository = $orderRepository;
        $this->configHelper = $configHelper;
        $this->addressInterfaceFactory = $addressInterfaceFactory;
        $this->session = $session;
    }

    /**
     * Sets Erp_invoice value on order
     *
     * @param ShipOrder $subject
     * @param int $orderId
     * @param array $items
     * @param bool $notify
     * @param bool $appendComment
     * @param ShipmentCommentCreationInterface|null $comment
     * @param array $tracks
     * @param array $packages
     * @param ShipmentCreationArgumentsInterface|null $arguments
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     */
    public function beforeExecute(
        ShipOrder $subject,
        $orderId,
        array $items = [],
        $notify = false,
        $appendComment = false,
        ShipmentCommentCreationInterface $comment = null,
        array $tracks = [],
        array $packages = [],
        ShipmentCreationArgumentsInterface $arguments = null
    ) {
        if ($this->configHelper->isVertexTaxRecalculationEnabled()) {
            $this->session->setData('shipment_track_data', $tracks);
            $order = $this->orderRepository->get($orderId);
            $quote = $this->quoteRepository->get($order->getQuoteId());
            $orderItemIdsQuoteItemIds = $this->getOrderItemIdsQuoteItemIds($items, $order);
            $this->reCalTaxUpdateOrder($orderItemIdsQuoteItemIds, $quote, $order);
        }
    }

    /**
     * Map shipping item_id and order item_id
     * @param $items
     * @param $order
     * @return array
     */
    private function getOrderItemIdsQuoteItemIds($items, $order)
    {
        $shipOrderItemIds = [];
        foreach ($items as $key => $item) {
            $shipOrderItemIds[] = $item->getOrderItemId();
        }
        $orderItemIdsQuoteItemIds = [];
        foreach ($order->getAllVisibleItems() as $_item) {
            if (in_array($_item->getItemId(), $shipOrderItemIds)) {
                $orderItemIdsQuoteItemIds[$_item->getItemId()] = $_item->getQuoteItemId();
            }
        }
        return $orderItemIdsQuoteItemIds;
    }

    /**
     * Recalculate tax.
     * @param $orderItemIdsQuoteItemIds
     * @param $quote
     * @param $order
     */
    private function reCalTaxUpdateOrder($orderItemIdsQuoteItemIds, $quote, $order)
    {
        if (!empty($orderItemIdsQuoteItemIds)) {
            $taxAmount = 0;
            $baseTaxAmount = 0;
            $discountTaxCompensationAmount = 0;
            $baseDiscountTaxCompensationAmount = 0;
            $baseDiscountAmount = 0;
            $discountAmount = 0;
            $quote = $this->updateQuoteShippAddress($order, $quote);
            $items = $quote->getAllVisibleItems();

            foreach ($items as $quoteItem) {
                /** Update quore Item to Order Item */
                $orderItem = $this->toOrderItem->convert($quoteItem);
                $origOrderItemNew = $order->getItemByQuoteItemId($quoteItem->getId());
                $origOrderItemNew->addData($orderItem->getData());
                /** Update Order Total */
                $taxAmount += ($quoteItem->getTaxAmount());
                $baseTaxAmount += ($quoteItem->getBaseTaxAmount());

                $discountTaxCompensationAmount += $quoteItem->getDiscountTaxCompensationAmount();
                $baseDiscountTaxCompensationAmount += $quoteItem->getBaseDiscountTaxCompensationAmount();
                $baseDiscountAmount += $quoteItem->getBaseDiscountAmount();
                $discountAmount += $quoteItem->getDiscountAmount();
            }

            $order->setSubtotal($quote->getSubtotal())
                ->setBaseSubtotal($quote->getBaseSubtotal())
                ->setDiscountAmount("-" . $discountAmount)
                ->setTaxAmount($taxAmount + $order->getShippingTaxAmount())
                ->setBaseTaxAmount($baseTaxAmount + $order->getShippingTaxAmount())
                ->setDiscountTaxCompensationAmount($discountTaxCompensationAmount)
                ->setBaseDiscountTaxCompensationAmount($baseDiscountTaxCompensationAmount)
                ->setBaseDiscountAmount($baseDiscountAmount)
                ->setGrandTotal(($quote->getSubtotal() - $discountAmount) + $order->getBaseShippingAmount() + $baseTaxAmount + $order->getShippingTaxAmount())
                ->setBaseGrandTotal(($quote->getSubtotal() - $discountAmount) + $order->getBaseShippingAmount() + $baseTaxAmount + $order->getShippingTaxAmount());
            $this->orderRepository->save($order);
        }
    }

    /**
     * Update quote shipping address and recalculate tax.
     * @param $order
     * @param $quote
     * @return \Magento\Quote\Model\Quote
     */
    private function updateQuoteShippAddress($order, $quote)
    {
        /** @var \Magento\Quote\Model\Quote $quote */
        $quote->setShippingAddress($this->convertAddress($order->getShippingAddress()));
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        $this->quoteRepository->save($quote);
        return $quote;
    }

    /**
     * Convert order shipping address to quote shipping address.
     * @param $orderAddressData
     * @return \Magento\Quote\Api\Data\AddressInterface
     */
    private function convertAddress($orderAddressData)
    {
        /** @var \Magento\Quote\Api\Data\AddressInterface $address */
        $address = $this->addressInterfaceFactory->create();
        $address
            ->setFirstname($orderAddressData->getFirstName())
            ->setLastname($orderAddressData->getLasttName())
            ->setPostcode($orderAddressData->getPostcode())
            ->setStreet($orderAddressData->getStreet())
            ->setCity($orderAddressData->getCity())
            ->setEmail($orderAddressData->getEmail())
            ->setTelephone($orderAddressData->getTelephone())
            ->setRegionId($orderAddressData->getRegionId())
            ->setRegion($orderAddressData->getRegion())
            ->setCountryId($orderAddressData->getCountryId());
        return $address;
    }
}
