<?php

namespace Yotpo\Core\Block\Adminhtml\System\Config\Form\Field\Link;

use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Config\Block\System\Config\Form\Field;

/**
 * Class Link - To download orders logs
 */
class DownloadOrdersLogs extends Field
{
    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return sprintf(
            '<a href ="%s">%s</a>',
            rtrim($this->_urlBuilder->getUrl('yotpoadmin/index/downloadlogs', ['logName'=>'orders']), '/'),
            __('Download Logs')
        );
    }
}
