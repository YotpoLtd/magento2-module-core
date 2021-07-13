<?php

namespace Yotpo\Core\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Yotpo\Core\Model\Config as YotpoConfig;

/**
 * Class ModuleVersion - Returns Yotpo module version
 */
class ModuleVersion extends Field
{
    /**
     * Template path
     *
     * @var string
     */
    protected $_template = 'Yotpo_Core::system/config/module_version.phtml';

    /**
     * @var YotpoConfig
     */
    protected $yotpoConfig;

    /**
     * @param  Context     $context
     * @param  YotpoConfig $yotpoConfig
     * @param  array       <mixed> $data
     */
    public function __construct(
        Context $context,
        YotpoConfig $yotpoConfig,
        array $data = []
    ) {
        $this->yotpoConfig = $yotpoConfig;
        parent::__construct($context, $data);
    }

    /**
     * Remove scope label
     * @param  AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * Return element html
     * @param  AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    /**
     * Generate collect button html
     * @return string
     */
    public function getModuleVersion(): string
    {
        return $this->yotpoConfig->getModuleVersion();
    }
}
