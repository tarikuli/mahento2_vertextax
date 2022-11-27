<?php
/**
 * Copyright © Hanesbrands, Inc. All rights reserved.
 */

declare(strict_types=1);

namespace Born\VertexTax\Plugin\Quote\Model;

use Magento\Backend\Model\Session\Quote as AdminCheckoutSession;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Vertex\Services\Quote\RequestInterface;
use Vertex\Services\Quote\ResponseInterface;
use Vertex\Tax\Service\QuoteProxy;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 */
class QuoteProxyPlugin
{
    /**
     * @var AdminCheckoutSession
     */
    private $adminCheckoutSession;

    /**
     * @var Session
     */
    private $checkoutSession;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var State
     */
    private $state;

    /**
     * @var SerializerInterface
     */
    protected $serializer;

    /**
     * QuoteProxyPlugin constructor.
     * @param AdminCheckoutSession $adminCheckoutSession
     * @param Session $checkoutSession
     * @param LoggerInterface $logger
     * @param State $state
     * @param SerializerInterface $serializer
     */
    public function __construct(
        AdminCheckoutSession $adminCheckoutSession,
        Session $checkoutSession,
        LoggerInterface $logger,
        State $state,
        SerializerInterface $serializer
    ) {
        $this->adminCheckoutSession  = $adminCheckoutSession;
        $this->checkoutSession = $checkoutSession;
        $this->logger = $logger;
        $this->state = $state;
        $this->serializer   = $serializer;
    }

    /**
     * save retail delivery fee value to quote if present in vertex response
     *
     * @param QuoteProxy $subject
     * @param $result
     * @param RequestInterface $request
     * @param string|null $scopeCode
     * @param string $scopeType
     * @return ResponseInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterRequest(QuoteProxy $subject, $result, RequestInterface $request, $scopeCode = null, $scopeType = ScopeInterface::SCOPE_STORE)
    {
        $jurisdictionArray = [];
        /** @var \Vertex\Services\Quote\ResponseInterface $result */
        foreach ($result->getLineItems() as $lineItem) {
            $i = 1;
            foreach ($lineItem->getTaxes() as $tax) {
                if (is_string($lineItem->getLineItemId()) && str_contains($lineItem->getLineItemId(), 'sequence-')) {
                    $jurisdictionArray[$lineItem->getProductCode()]["taxjurisdiction" . sprintf("%02d", $i++) ] = $tax->getAmount();
                }
            }
        }

        $quote = $this->getQuote();
        if ($quote) {
            /** @var  $quoteItem */
            foreach ($quote->getAllVisibleItems() as $quoteItem) {
                if (isset($jurisdictionArray[$quoteItem->getSku()])) {
                    $quoteItem->setData('vertex_item_tax_data', $this->serializer->serialize($jurisdictionArray[$quoteItem->getSku()]));
                }
            }
        }
        return $result;
    }

    /**
     * get quote from area-appropriate session
     *
     * @return \Magento\Quote\Api\Data\CartInterface|\Magento\Quote\Model\Quote|null
     */
    private function getQuote()
    {
        try {
            $area = $this->state->getAreaCode();
        } catch (LocalizedException $e) {
            $this->logger->error($e);
            return null;
        }

        if ($area === Area::AREA_ADMINHTML) {
            $quote = $this->adminCheckoutSession->getQuote();
        } else {
            try {
                $quote = $this->checkoutSession->getQuote();
            } catch (\Exception $e) {
                $this->logger->error($e);
                return null;
            }
        }

        return $quote;
    }
}
