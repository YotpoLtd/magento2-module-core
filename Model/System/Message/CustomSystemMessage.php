<?php

namespace Yotpo\Core\Model\System\Message;

use Magento\Eav\Model\Attribute as EAVAttribute;
use Magento\Framework\DataObject\IdentityService;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Notification\MessageInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Phrase;
use Yotpo\Core\Setup\Patch\Data\CreateCategoryAttribute;
use Yotpo\Core\Setup\Patch\Data\CreateProductAttribute;
use Yotpo\Core\Model\CustomCustomerAttribute;
use Yotpo\Core\Model\CustomCustomerAttributeSmsMarketing;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Eav\Api\Data\AttributeInterface;

/**
 * Class CustomSystemMessage - Show custom message to admin user
 */
class CustomSystemMessage implements MessageInterface
{
    /**
     * @var AttributeRepositoryInterface
     */
    protected $attributeRepositoryInterface;

    /**
     * @var IdentityService
     */
    protected $identityService;

    /**
     * @var ModuleListInterface
     */
    protected $moduleList;

    /**
     * @var CreateCategoryAttribute
     */
    protected $createCategoryAttribute;

    /**
     * @var CreateProductAttribute
     */
    protected $createProductAttribute;

    /**
     * @var CustomCustomerAttribute
     */
    protected $customCustomerAttribute;

    /**
     * @var CustomCustomerAttributeSmsMarketing
     */
    protected $customCustomerAttributeSmsMarketing;

    /**
     * @var EAVAttribute
     */
    protected $eavAttribute;

    /**
     * @var AttributeCollectionFactory
     */
    private $attributeFactory;

    /**
     * @var mixed[]
     */
    protected $attributeCodes = ['synced_to_yotpo_product', 'synced_to_yotpo_collection'];

    /**
     * @var array <mixed>
     */
    protected $missingAttributes = ['synced_to_yotpo_customer','yotpo_accepts_sms_marketing',
        'synced_to_yotpo_product', 'synced_to_yotpo_collection'];

    /**
     * @var array <mixed>
     */
    protected $attributeCodesFromCollection = [];

    /**
     * CustomSystemMessage constructor.
     * @param AttributeRepositoryInterface $attributeRepositoryInterface
     * @param IdentityService $identityService
     * @param ModuleListInterface $moduleList
     * @param CreateCategoryAttribute $createCategoryAttribute
     * @param CreateProductAttribute $createProductAttribute
     * @param CustomCustomerAttribute $customCustomerAttribute
     * @param CustomCustomerAttributeSmsMarketing $customCustomerAttributeSmsMarketing
     * @param AttributeCollectionFactory $attributeFactory
     */
    public function __construct(
        AttributeRepositoryInterface $attributeRepositoryInterface,
        IdentityService $identityService,
        ModuleListInterface $moduleList,
        CreateCategoryAttribute $createCategoryAttribute,
        CreateProductAttribute $createProductAttribute,
        CustomCustomerAttribute $customCustomerAttribute,
        CustomCustomerAttributeSmsMarketing $customCustomerAttributeSmsMarketing,
        AttributeCollectionFactory $attributeFactory
    ) {
        $this->attributeRepositoryInterface = $attributeRepositoryInterface;
        $this->identityService = $identityService;
        $this->moduleList = $moduleList;
        $this->createCategoryAttribute = $createCategoryAttribute;
        $this->createProductAttribute = $createProductAttribute;
        $this->customCustomerAttribute = $customCustomerAttribute;
        $this->customCustomerAttributeSmsMarketing = $customCustomerAttributeSmsMarketing;
        $this->attributeFactory = $attributeFactory;
    }

    /**
     * @return string
     */
    public function getIdentity()
    {
        return $this->identityService->generateIdForData('YOTPO_CONFIG_NOTIFICATION');
    }

    /**
     * Displays system message, if all attributes are not created.
     *
     * @return bool
     */
    public function isDisplayed()
    {
        $attributeExists = $this->checkIfAttributesExists();
        if ($attributeExists === false) {
            $this->missingAttributes = array_diff($this->missingAttributes, $this->attributeCodesFromCollection);
            foreach ($this->missingAttributes as $missingAttribute) {
                switch ($missingAttribute) {
                    case 'synced_to_yotpo_product':
                        $this->createProductAttribute->apply();
                        break;
                    case 'synced_to_yotpo_collection':
                        $this->createCategoryAttribute->apply();
                        break;
                    case 'synced_to_yotpo_customer':
                        if (method_exists($this->customCustomerAttribute, 'apply')) {
                            $this->customCustomerAttribute->apply();
                        }
                        break;
                    case 'yotpo_accepts_sms_marketing':
                        if (method_exists($this->customCustomerAttributeSmsMarketing, 'apply')) {
                            $this->customCustomerAttributeSmsMarketing->apply();
                        }
                        break;
                }
            }
        }
        $attributeExists = $this->checkIfAttributesExists();
        return $attributeExists === false;
    }

    /**
     * @return Phrase|string
     */
    public function getText()
    {
        return
            __('Yotpo EAV attributes are not created.
            Please execute \'DELETE FROM patch_list where patch_name like "Yotpo%"\' and re-run setup:upgrade. ');
    }

    /**
     * @return int
     */
    public function getSeverity()
    {
        return self::SEVERITY_MAJOR;
    }

    /**
     * Check if all attributes are created in the backend
     *
     * @return bool
     */
    public function checkIfAttributesExists()
    {
        if ($this->isEnabled('Yotpo_SmsBump')) {
            $this->attributeCodes =
                array_merge($this->attributeCodes, ['synced_to_yotpo_customer', 'yotpo_accepts_sms_marketing']);
        }
        $this->attributeCodes = array_unique($this->attributeCodes);
        $collection = $this->attributeFactory->create();
        $collection->addFieldToFilter('attribute_code', $this->attributeCodes);
        $attributeCodes = [];
        foreach ($collection as $attributes) {
            $attributeCodes[] = $attributes->getData(AttributeInterface::ATTRIBUTE_CODE);
        }
        $this->attributeCodesFromCollection = $attributeCodes;

        return count($this->attributeCodesFromCollection) == count($this->attributeCodes);
    }

    /**
     * @param string $moduleName
     * @return bool
     */
    public function isEnabled($moduleName)
    {
        return $this->moduleList->has($moduleName);
    }
}
