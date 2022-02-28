<?php

namespace Yotpo\Core\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Yotpo\Core\Model\Config as YotpoConfig;

/**
 * Class LaunchYotpoButton
 *
 * Displays setup instructions
 */
class LaunchYotpoButton extends Field
{
    /**
     * Template path
     *
     * @var string
     */
    protected $_template = 'Yotpo_Core::system/config/launch_yotpo_button.phtml';

    /**
     * @var YotpoConfig
     */
    private $yotpoConfig;

    /**
     * @var Http
     */
    protected $_request;

    /**
     * @var mixed|null
     */
    protected $_websiteId;

    /**
     * @var mixed
     */
    protected $_storeId;

    /**
     * @param  Context      $context
     * @param  YotpoConfig  $yotpoConfig
     * @param  Http         $request
     * @param  array<mixed> $data
     */
    public function __construct(
        Context $context,
        YotpoConfig $yotpoConfig,
        Http $request,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->yotpoConfig = $yotpoConfig;
        $this->_request = $request;
        $this->_websiteId = $request->getParam('website');
        $this->_storeId = $this->getRequest()->getParam('store');
    }

    /**
     * Remove scope label
     *
     * @param  AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * Return element html
     *
     * @param  AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    /**
     * @return mixed
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getAppKey()
    {
        if ($this->_storeId !== null) {
            return $this->yotpoConfig->getConfig('app_key', $this->_storeId, ScopeInterface::SCOPE_STORE);
        } elseif ($this->_websiteId !== null) {
            return $this->yotpoConfig->getConfig('app_key', $this->_websiteId, ScopeInterface::SCOPE_WEBSITE);
        } else {
            return $this->yotpoConfig->getConfig('app_key');
        }
    }

    /**
     * Generate yotpo button html
     *
     * @return string
     * @throws LocalizedException
     */
    public function getButtonHtml()
    {
        $button = $this->getLayout()->createBlock(/** @phpstan-ignore-line */
            \Magento\Backend\Block\Widget\Button::class
        )->setData(
            [
            'id' => 'launch_yotpo_button',
            'class' => 'launch-yotpo-button yotpo-cta-add-arrow',
            'label' => __('Launch Yotpo'),
            ]
        );
        if (!($appKey = $this->getAppKey())) {
            $button->setDisabled(true);
        } else {
            $button->setOnClick("window.open('https://yap.yotpo.com/#/preferredAppKey={$appKey}','_blank');");
        }

        return $button->toHtml();
    }

    /**
     * @return bool
     */
    public function isStoreScope()
    {
        return $this->getRequest()->getParam('store') || $this->yotpoConfig->isSingleStoreMode();
    }
}
