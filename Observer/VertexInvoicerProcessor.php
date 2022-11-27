<?php
/**
 * Copyright © Hanesbrands, Inc. All rights reserved.
 */
declare(strict_types=1);

namespace Born\VertexTax\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Born\VertexTax\Helper\ConfigHelper;
use Born\VertexTax\Helper\InvoiceCreateHelper;
use Magento\Backend\Model\Session;
use Magento\Sales\Api\OrderRepositoryInterface;
use Vertex\Services\Invoice\ResponseInterface;
use Vertex\Tax\Model\Api\Data\InvoiceRequestBuilder;
use Vertex\Tax\Model\Loader\ShippingAssignmentExtensionLoader;
use Vertex\Tax\Model\Loader\GiftwrapExtensionLoader;
use Vertex\Tax\Model\TaxInvoice;


/**
 * Class VertexInvoicerProcessor
 */
class VertexInvoicerProcessor implements ObserverInterface
{
    /**
     * @var ConfigHelper
     */
    private $configHelper;

    /**
     * @var \Magento\Backend\Model\Session
     */
    protected $session;

    /**
     * @var InvoiceCreateHelper
     */
    private $invoiceCreateHelper;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /** @var ShippingAssignmentExtensionLoader */
    private $shipmentExtensionLoader;

    /** @var GiftwrapExtensionLoader */
    private $giftwrapExtensionLoader;

    /** @var InvoiceRequestBuilder */
    private $invoiceRequestBuilder;

    /** @var TaxInvoice */
    private $taxInvoice;

    /**
     * @param ConfigHelper $configHelper
     * @param InvoiceCreateHelper $invoiceCreateHelper
     * @param Session $session
     */
    public function __construct(
        ConfigHelper $configHelper,
        InvoiceCreateHelper $invoiceCreateHelper,
        OrderRepositoryInterface $orderRepository,
        GiftwrapExtensionLoader $giftwrapExtensionLoader,
        ShippingAssignmentExtensionLoader $shipmentExtensionLoader,
        InvoiceRequestBuilder $invoiceRequestBuilder,
        TaxInvoice $taxInvoice,
        Session $session
    ) {
        $this->configHelper = $configHelper;
        $this->invoiceCreateHelper = $invoiceCreateHelper;
        $this->orderRepository = $orderRepository;
        $this->giftwrapExtensionLoader = $giftwrapExtensionLoader;
        $this->shipmentExtensionLoader = $shipmentExtensionLoader;
        $this->invoiceRequestBuilder = $invoiceRequestBuilder;
        $this->taxInvoice = $taxInvoice;
        $this->session = $session;
    }

    /**
     * @param EventObserver $observer
     * @return void
     */
    public function execute(EventObserver $observer)
    {
        $trackItem = $this->session->getData('shipment_track_data', false);
        if ($this->configHelper->isVertexTaxRecalculationEnabled()
            && $this->configHelper->isInvoiceCreateEnabled() && !empty($trackItem)) {
            /** @var \Magento\Sales\Model\Order\Shipment $shipment */
            $shipment = $observer->getEvent()->getShipment();
            #####################################################
            $order = $this->orderRepository->get($shipment->getOrderId());
            $order = clone $order;
            if ($order->getExtensionAttributes()) {
                $order->setExtensionAttributes(clone $order->getExtensionAttributes());
            }

            $order = $this->shipmentExtensionLoader->loadOnOrder($order);
            $order = $this->giftwrapExtensionLoader->loadOnOrder($order);
            $request = $this->invoiceRequestBuilder->buildFromOrder($order);

            /** @var ResponseInterface */
            $response = $this->taxInvoice->sendInvoiceRequest($request, $order);


            #####################################################
            if ($shipment->getOrigData('entity_id')) {
                return;
            }
            $items = [];
            foreach ($shipment->getItems() as $item) {
                if ($item->getPrice() > 0) {
                    $items[] = [
                        'order_item_id' => (int)$item->getOrderItemId(),
                        'qty' => (int)$item->getQty(),
                    ];
                }
            }
            $inputParams = [
                'order_id' => $shipment->getOrderId(),
                'capture' => true,
                'items' => $items,
                'notify' => true,
                'appendComment' => false,
            ];
            $invoiceId = $this->invoiceCreateHelper->createInvoice($inputParams);
        }
    }


    public function jdbg($label, $obj)
    {
        $fileName = strtolower(str_replace('\\', '_', get_class($this))) . '.log';
        $filePath = BP . '/var/log/debug_' . $fileName;
        $writer = new \Zend_Log_Writer_Stream($filePath);
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logStr = "{$label}:";
        switch (gettype($obj)) {
            case 'boolean':
                if ($obj) {
                    $logStr .= "(bool) -> TRUE";
                } else {
                    $logStr .= "(bool) -> FALSE";
                }
                break;
            case 'integer':
            case 'double':
            case 'string':
                $logStr .= "(" . gettype($obj) . ") -> {$obj}";
                break;
            case 'array':
                $logStr .= "(array) -> " . print_r($obj, true);
                break;
            case 'object':
                try {
                    if (method_exists($obj, 'debug')) {
                        $logStr .= "(" . get_class($obj) . ") -> " . print_r($obj->debug(), true);
                    } else {
                        $this->jdbg($label,print_r($obj,true));
                        $logStr .= "NULL";
                        break;
//                        $logStr .= "Don't know how to log object of class " . get_class($obj);
                    }
                } catch (Exception $e) {
                    $logStr .= "Don't know how to log object of class " . get_class($obj);
                }
                break;
            case 'NULL':
                $logStr .= "NULL";
                break;
            default:
                $logStr .= "Don't know how to log type " . gettype($obj);
        }

        $logger->info($logStr);
    }

}
