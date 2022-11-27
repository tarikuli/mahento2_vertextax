<?php
/**
 * @vendor Born
 * @package Born_Sales
 */

declare(strict_types=1);

namespace Born\VertexTax\Plugin;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Eav\Api\AttributeOptionManagementInterface;
use Vertex\Data\LineItemInterface;

class LineItemBuilder
{
    private $attributeOptionManagement;
    private $productRepository;

    public function __construct(
        AttributeOptionManagementInterface $attributeOptionManagement,
        ProductRepositoryInterface $productRepository
    )
    {
        $this->attributeOptionManagement = $attributeOptionManagement;
        $this->productRepository = $productRepository;
    }

    /**
     * set the vertex line item ProductClass to the product's sap_material_number value
     *
     * @param \Vertex\Tax\Model\Api\Data\LineItemBuilder $subject
     * @param $result
     * @return LineItemInterface
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\StateException
     */
    public function afterBuildFromQuoteDetailsItem(
        \Vertex\Tax\Model\Api\Data\LineItemBuilder $subject,
        $result
    ) {
        try {
            $product = $this->productRepository->get($result->getProductCode());
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            return $result;
        }

        $attributeOptions = $this->attributeOptionManagement->getItems(\Magento\Catalog\Api\Data\ProductAttributeInterface::ENTITY_TYPE_CODE, 'tax_material_group');
        $productAttributeOption = $product->getTaxMaterialGroup();

        foreach ($attributeOptions as $attributeOption) {
            if ($attributeOption->getValue() == $productAttributeOption) {
                $result->setProductClass($attributeOption->getLabel());
            }
        }

        return $result;
    }
}
