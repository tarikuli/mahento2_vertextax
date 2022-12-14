<?php
/**
 * @vendor Born
 * @package Born_VertexTax
 */

declare(strict_types=1);

namespace Born\VertexTax\Rewrite;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\Data\CreditmemoItemInterface;
use Magento\Sales\Api\Data\InvoiceItemInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Tax\Api\Data\QuoteDetailsItemInterface;
use Vertex\Tax\Model\FlexField\Extractor\AttributeExtractor;
use Vertex\Tax\Model\FlexField\Extractor\ValueExtractor;
use Vertex\Tax\Model\FlexField\FlexFieldProcessableAttribute;
use Vertex\Tax\Model\FlexField\FlexFieldProcessableAttributeFactory;

/**
 * @inheritDoc
 */
class OrderGetterProcessor extends \Vertex\Tax\Model\FlexField\Processor\OrderGetterProcessor implements
    \Vertex\Tax\Model\FlexField\Processor\InvoiceFlexFieldProcessorInterface,
    \Vertex\Tax\Model\FlexField\Processor\TaxCalculationFlexFieldProcessorInterface
{
    /** @var string[] */
    const DATE_FIELDS = [
        'getCreatedAt'
    ];

    /** @var string[] */
    const ALLOW_LIST = [
        'getCouponCode'
    ];

    /** @var string */
    const PREFIX = 'order';

    /** @var AttributeExtractor */
    private $attributeExtractor;

    /** @var OrderItemRepositoryInterface */
    private $orderItemRepository;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var ValueExtractor */
    private $valueExtractor;

    /** @var CartRepositoryInterface */
    private $cartRepository;

    /** @var string[] List of non-date block listed methods */
    private $blockListMethods;

    /**
     * @param AttributeExtractor $attributeExtractor
     * @param ValueExtractor $valueExtractor
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderItemRepositoryInterface $orderItemRepository
     * @param CartRepositoryInterface $cartRepository
     */
    public function __construct(
        AttributeExtractor $attributeExtractor,
        ValueExtractor $valueExtractor,
        OrderRepositoryInterface $orderRepository,
        OrderItemRepositoryInterface $orderItemRepository,
        CartRepositoryInterface $cartRepository
    ) {
        $this->attributeExtractor = $attributeExtractor;
        $this->orderRepository = $orderRepository;
        $this->orderItemRepository = $orderItemRepository;
        $this->valueExtractor = $valueExtractor;
        $this->cartRepository = $cartRepository;
    }

    /**
     * Returns an array of all attribute methods that cannot be used in this processor
     *
     * @return string[]
     */
    private function getBlockListMethods()
    {
        if (empty($this->blockListMethods)) {
            $allowlist = static::ALLOW_LIST;

            $this->blockListMethods = array_filter(
                get_class_methods(OrderInterface::class),
                static function ($methodName) use ($allowlist) {
                    return !in_array($methodName, $allowlist, true) && strpos($methodName, 'get') === 0;
                }
            );
        }
        return $this->blockListMethods;
    }

    /**
     * Retrieve all available attributes
     *
     * @return FlexFieldProcessableAttribute[]
     */
    public function getAttributes()
    {
        $blocklistMethods = $this->getBlockListMethods();

        return array_merge(
            $this->attributeExtractor->extract(
                OrderInterface::class,
                static::PREFIX,
                'Order',
                static::class,
                array_merge(static::DATE_FIELDS, $blocklistMethods)
            ),
            $this->attributeExtractor->extractDateFields(
                static::PREFIX,
                static::DATE_FIELDS,
                'Order',
                static::class
            )
        );
    }

    /**
     * @inheritdoc
     */
    public function getValueFromCreditmemo(
        CreditmemoItemInterface $item,
        $attributeCode,
        $fieldType = null,
        $fieldId = null
    ) {
        $orderItem = $this->orderItemRepository->get($item->getOrderItemId());

        return $this->getValueFromOrder($orderItem, $attributeCode);
    }

    /**
     * @inheritdoc
     */
    public function getValueFromInvoice(InvoiceItemInterface $item, $attributeCode, $fieldType = null, $fieldId = null)
    {
        $orderItem = $this->orderItemRepository->get($item->getOrderItemId());

        return $this->getValueFromOrder($orderItem, $attributeCode);
    }

    /**
     * @inheritdoc
     */
    public function getValueFromOrder(OrderItemInterface $item, $attributeCode, $fieldType = null, $fieldId = null)
    {
        $order = $this->orderRepository->get($item->getOrderId());

        return $this->valueExtractor->extract($order, $attributeCode, static::PREFIX, static::DATE_FIELDS);
    }

    /**
     * @inheritDoc
     */
    public function getValueFromQuote(
        QuoteDetailsItemInterface $item,
        $attributeCode,
        $fieldType = null,
        $fieldId = null
    ) {
        $quoteId = $item->getExtensionAttributes() && $item->getExtensionAttributes()->getQuoteId()
            ? $item->getExtensionAttributes()->getQuoteId()
            : null;

        if ($quoteId === null) {
            return null;
        }
        return $this->getValueFromQuoteId($quoteId, $attributeCode);
    }

    /**
     * Extract the value of a getter given a Quote ID and attribute code
     *
     * @param string $quoteId
     * @param string $attributeCode
     * @return mixed
     */
    private function getValueFromQuoteId($quoteId, $attributeCode)
    {
        try {
            $cart = $this->cartRepository->get($quoteId);
        } catch (NoSuchEntityException $e) {
            return null;
        }

        return $this->valueExtractor->extract($cart, $attributeCode, static::PREFIX, static::DATE_FIELDS);
    }
}
