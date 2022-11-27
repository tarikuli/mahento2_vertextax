<?php
/**
 * Copyright © Hanesbrands, Inc. All rights reserved.
 */

namespace Born\VertexTax\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Sales\Model\InvoiceOrder;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Framework\Webapi\ServiceInputProcessor;

class InvoiceCreateHelper extends AbstractHelper
{
    /**
     * @var InvoiceOrderInterface
     */
    protected $invoiceOrder;

    /**
     * @var ServiceInputProcessor
     */
    protected $serviceInputProcessor;

    /**
     * @param InvoiceOrderInterface $invoiceOrder
     * @param ServiceInputProcessor $serviceInputProcessor
     * @param Context $context
     */
    public function __construct(
        InvoiceOrderInterface $invoiceOrder,
        ServiceInputProcessor $serviceInputProcessor,
        Context $context
    ) {
        parent::__construct($context);
        $this->invoiceOrder = $invoiceOrder;
        $this->serviceInputProcessor = $serviceInputProcessor;
    }


    public function createInvoice($inputParams)
    {
        try {
            $inputParams = $this->serviceInputProcessor->process(
                \Magento\Sales\Api\InvoiceOrderInterface::class,
                'execute',
                $inputParams
            );
            $invoiceId = call_user_func_array(
                [
                    $this->invoiceOrder,
                    'execute'
                ],
                $inputParams
            );
            return $invoiceId;

        } catch (\Exception $e) {
            // Error logging made by main class
            return false;
        }
    }
}
