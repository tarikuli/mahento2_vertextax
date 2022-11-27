<?php
/**
 * @vendor Born
 * @package Born_VertexTax
 */

namespace Born\VertexTax\Rewrite;

use Magento\Backend\Model\Session;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Sales\Api\Data\ShipmentTrackInterface;
use Vertex\Data\SellerInterface;
use Vertex\Data\SellerInterfaceFactory;
use Vertex\Exception\ConfigurationException;
use Vertex\Tax\Model\Api\Utility\MapperFactoryProxy;
use Vertex\Tax\Model\Config;
use Vertex\Tax\Model\Api\Data\AddressBuilder;
use Magento\Framework\EntityManager\HydratorPool;
use Hanesce\Narvar\Model\Config as NarvarConfig;
use Hanesce\Narvar\Model\ConfigForFieldArray;

class SellerBuilder extends \Vertex\Tax\Model\Api\Data\SellerBuilder
{
    /** @var AddressBuilder */
    private $addressBuilder;

    /** @var Config */
    private $config;

    /** @var SellerInterfaceFactory */
    private $sellerFactory;

    /** @var string */
    private $scopeCode;

    /** @var string */
    private $scopeType;

    /** @var MapperFactoryProxy */
    private $mapperFactory;

    /** @var StringUtils */
    private $stringUtilities;

    /**
     * @var \Magento\Backend\Model\Session
     */
    protected $session;

    /**
     * @var HydratorPool
     */
    private $hydratorPool;

    /**
     * @var \Hanesce\Narvar\Model\Config
     */
    private $narvarConfig;

    /**
    * @var ConfigForFieldArray $configForFieldArray
    */
    private $configForFieldArray;

    /**
     * @param SellerInterfaceFactory $sellerFactory
     * @param Config $config
     * @param AddressBuilder $addressBuilder
     * @param MapperFactoryProxy $mapperFactory
     * @param StringUtils $stringUtils
     */
    public function __construct(
        SellerInterfaceFactory $sellerFactory,
        Config $config,
        AddressBuilder $addressBuilder,
        MapperFactoryProxy $mapperFactory,
        StringUtils $stringUtils,
        Session $session,
        HydratorPool $hydratorPool,
        NarvarConfig $narvarConfig,
        ConfigForFieldArray $configForFieldArray,
    ) {
        $this->sellerFactory = $sellerFactory;
        $this->config = $config;
        $this->addressBuilder = $addressBuilder;
        $this->mapperFactory = $mapperFactory;
        $this->stringUtilities = $stringUtils;
        $this->session = $session;
        $this->hydratorPool = $hydratorPool;
        $this->narvarConfig = $narvarConfig;
        $this->configForFieldArray = $configForFieldArray;
    }

    /**
     * Create a {@see SellerInterface} from store configuration
     *
     * @return SellerInterface
     * @throws ConfigurationException
     */
    public function build()
    {
        /** @var SellerInterface $seller */
        $seller = $this->sellerFactory->create();
        $sellerMapper = $this->mapperFactory->getForClass(SellerInterface::class, $this->scopeCode, $this->scopeType);

        $street = [
            $this->config->getCompanyStreet1($this->scopeCode, $this->scopeType),
            $this->config->getCompanyStreet2($this->scopeCode, $this->scopeType)
        ];

        $dcPlantID = null;
        $trackItem = $this->session->getData('shipment_track_data', false);
        if (!empty($trackItem)) {
            foreach ($trackItem as $track) {
                $hydrator = $this->hydratorPool->getHydrator(
                    \Magento\Sales\Api\Data\ShipmentTrackCreationInterface::class
                );
                $tracksArray = $hydrator->extract($track);
                if (isset($tracksArray["title"]) && is_numeric($tracksArray["title"])) {
                    $dcPlantID = $tracksArray["title"];
                    break;
                }
            }
        }
        $originZip = $this->getOriginZip($dcPlantID);

        if (!empty($originZip)) {
            $address = $this->addressBuilder
                ->setScopeCode($this->scopeCode)
                ->setScopeType($this->scopeType)
                ->setStreet("")
                ->setCity("")
                ->setRegionId("")
                ->setPostalCode($originZip)
                ->setCountryCode($this->config->getCompanyCountry($this->scopeCode, $this->scopeType))
                ->build();
        } else {
            $address = $this->addressBuilder
                ->setScopeCode($this->scopeCode)
                ->setScopeType($this->scopeType)
                ->setStreet($street)
                ->setCity($this->config->getCompanyCity($this->scopeCode, $this->scopeType))
                ->setRegionId($this->config->getCompanyRegionId($this->scopeCode, $this->scopeType))
                ->setPostalCode($this->config->getCompanyPostalCode($this->scopeCode, $this->scopeType))
                ->setCountryCode($this->config->getCompanyCountry($this->scopeCode, $this->scopeType))
                ->build();
        }
        $seller->setPhysicalOrigin($address);

        $configCompanyCode = $this->config->getCompanyCode($this->scopeCode, $this->scopeType);

        if ($configCompanyCode) {
            $companyCode = $this->stringUtilities->substr(
                $configCompanyCode,
                0,
                $sellerMapper->getCompanyCodeMaxLength()
            );

            $seller->setCompanyCode($companyCode);
        }

        return $seller;
    }

    /**
     * Set the Scope Code
     *
     * @param string|null $scopeCode
     * @return SellerBuilder
     */
    public function setScopeCode($scopeCode)
    {
        $this->scopeCode = $scopeCode;
        return $this;
    }

    /**
     * Set the Scope Type
     *
     * @param string|null $scopeType
     * @return SellerBuilder
     */
    public function setScopeType($scopeType)
    {
        $this->scopeType = $scopeType;
        return $this;
    }

    /**
     * Gets the Narvar service code from map
     * @param string $trackItem
     * @return string|null
     */
    private function getOriginZip($dcPlantID): ?string
    {
        $plantZipMap = $this->narvarConfig->getPlantZips();
        return $dcPlantID && $plantZipMap ?
            $this->configForFieldArray->findSerialArrayMatch($dcPlantID, $plantZipMap) :
            null;
    }
}
