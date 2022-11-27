<?php
/**
 * Created by PhpStorm.
 * User: scandiweb
 * Date: 04/08/2019
 * Time: 22:30
 */

namespace Born\VertexTax\Plugin\Quote\Model;

use Born\VertexTax\Helper\ConfigHelper as VertexHelper;
use Closure;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Quote\Model\Quote\Item\ToOrderItem as MageToOrderItem;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Born\Core\Helper\ConfigHelper;
use Psr\Log\LoggerInterface;

/**
 * Class ToOrderItem
 *
 * @package Born\VertexTax\Plugin\Quote\Model
 */
class ToOrderItem
{
    /**
     * @var RuleRepositoryInterface
     */
    private $repo;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var SerializerInterface
     */
    private $serializerInterface;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ConfigHelper
     */
    private $configHelper;

    /**
     * ToOrderItem constructor.
     *
     * @param RuleRepositoryInterface $couponRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SerializerInterface $serializer
     * @param LoggerInterface $logger
     * @param ConfigHelper $configHelper
     */
    public function __construct(
        RuleRepositoryInterface $couponRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SerializerInterface $serializer,
        LoggerInterface $logger,
        ConfigHelper $configHelper
    ) {
        $this->logger = $logger;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->repo = $couponRepository;
        $this->serializerInterface = $serializer;
        $this->configHelper = $configHelper;
    }

    /**
     * Adds needed attribute data to order item
     *
     * @param MageToOrderItem $subject
     * @param Closure $proceed
     * @param AbstractItem $item
     * @param array $additional
     * @return Item
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundConvert(
        MageToOrderItem $subject,
        Closure $proceed,
        AbstractItem $item,
        $additional = []
    ) {
        try {
            if ($this->configHelper->isModuleEnabled(VertexHelper::MODULE_NAME)) {
                /** @var $orderItem Item */
                $orderItem = $proceed($item, $additional);
                $orderItem->setData('item_shipping_costs', $item->getData('item_shipping_costs'));
                $orderItem->setData('vertex_item_tax_data', $item->getData('vertex_item_tax_data'));

                if ($ids = $item->getAppliedRuleIds()) {
                    $criteria = $this->searchCriteriaBuilder
                        ->addFilter('code', '', 'neq')
                        ->addFilter('rule_id', $ids, 'in')
                        ->setPageSize(1)
                        ->setCurrentPage(1)
                        ->create();
                    $results = $this->repo->getList($criteria)->getItems();

                    $couponData = [];

                    foreach ($results as $result) {
                        $orderStoreId = $orderItem->getStoreId();
                        $ruleDescription = $result->getDescription();

                        foreach ($result->getStoreLabels() as $storeLabel) {
                            if ($storeLabel->getStoreId() === 0) {
                                $ruleDescription = $storeLabel->getStoreLabel();
                                continue;
                            }

                            if ($orderStoreId !== $storeLabel->getStoreId()) {
                                continue;
                            }

                            $ruleDescription = $storeLabel->getStoreLabel();
                            break;
                        }

                        if ($result->getCode()) {
                            $couponData = [
                                'amount' => $result->getDiscountAmount(),
                                'type' => $result->getSimpleAction(),
                                'code' => $result->getCode(),
                                'description' => $ruleDescription
                            ];
                        }
                    }

                    $orderItem->setData('coupon_detail', $this->serializerInterface->serialize($couponData));
                }
            } else {
                return $proceed($item, $additional);
            }
        } catch (LocalizedException $exception) {
            $this->logger->critical($exception);
        }

        return $orderItem;
    }
}
