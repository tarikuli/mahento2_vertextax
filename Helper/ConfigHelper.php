<?php
/**
 * Copyright © Hanesbrands, Inc. All rights reserved.
 */

namespace Born\VertexTax\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

/**
 * Class ConfigHelper
 *
 * @package Born\VertexTax\Helper
 */
class ConfigHelper extends AbstractHelper
{
    const XML_PATH_VERTEX_TAX_CALC_ENABLED = 'born_vertex/general/enabled';
    const XML_PATH_VERTEX_DEFAULT_PERCENTAGE = 'born_vertex/general/default_percentage';
    const XML_PATH_VERTEX_TAX_RECALCULATION_ENABLED = 'born_vertex/recalculation/enabled';
    const XML_PATH_VERTEX_TAX_RECALCULATION_INVOICE_ENABLED = 'born_vertex/recalculation/invoice_enabled';
    const XML_PATH_VERTEX_DEV_FAIL = 'born_vertex/dev/test_fail_enabled';
    const MODULE_NAME = 'Born_VertexTax';

    /**
     * ConfigHelper constructor.
     *
     * @param Context $context
     * @param BornCoreHelper $configHelper
     */
    public function __construct(
        Context $context
    ) {
        parent::__construct($context);
    }

    /**
     * Check if Vertex tax fallback is enabled.
     *
     * @return bool
     */
    public function isVertexTaxFallbackEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_VERTEX_TAX_CALC_ENABLED, ScopeInterface::SCOPE_WEBSITES);
    }

    /**
     * Check if Vertex tax Recalculation is enabled.
     *
     * @return bool
     */
    public function isVertexTaxRecalculationEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_VERTEX_TAX_RECALCULATION_ENABLED,
            ScopeInterface::SCOPE_WEBSITES);
    }

    /**
     * Check if Invoice Create after Shipment is enabled.
     *
     * @return bool
     */
    public function isInvoiceCreateEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_VERTEX_TAX_RECALCULATION_INVOICE_ENABLED,
            ScopeInterface::SCOPE_WEBSITES);
    }

    /**
     * Get Vertex fallback percentage.
     *
     * @return float
     */
    public function getDefaultPercentage()
    {
        return (float)$this->scopeConfig
            ->getValue(self::XML_PATH_VERTEX_DEFAULT_PERCENTAGE, ScopeInterface::SCOPE_WEBSITES);
    }

    /**
     * Check if Vertex API fail is enabled for testing purposes.
     *
     * @return bool
     */
    public function isVertexDevFailEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_VERTEX_DEV_FAIL, ScopeInterface::SCOPE_WEBSITES);
    }
}
