<?php

namespace Yotpo\Core\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Exception\LocalizedException;

/**
 * Class ResetSyncButton
 *
 * Reset Orders sync.
 */
class ResetOrdersSyncButton extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Yotpo_Core::system/config/reset_orders_sync_button.phtml';

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
    protected function _getElementHtml(AbstractElement $element) // @codingStandardsIgnoreLine - required by parent class
    {
        return $this->_toHtml();
    }

    /**
     * Return ajax url for sync-forms button
     *
     * @return string
     */
    public function getAjaxUrl()
    {
        return $this->getUrl('yotpoadmin/resetorderssync/index');
    }

    /**
     * Generate Sync Forms button HTML
     *
     * @return mixed
     * @throws LocalizedException
     */
    public function getButtonHtml()
    {
        $button = $this->getLayout()->createBlock(/** @phpstan-ignore-line */
            \Magento\Backend\Block\Widget\Button::class
        )->setData([
                'id'    => 'yotpo_reset_orders_sync_btn',
                'label' => __('Reset Sync'),
            ]);

        return $button->toHtml();
    }

    /**
     * Get current store scope
     *
     * @return int|mixed
     */
    public function getStoreScope()
    {
        return $this->getRequest()->getParam('store') ? : 0;
    }
}
