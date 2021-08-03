<?php

namespace Yotpo\Core\Block\Adminhtml\System\Config\Form\Field\Link;

use Magento\Framework\Data\Form\Element\AbstractElement;
use Yotpo\Core\Block\Adminhtml\System\Config\Form\Field\RemoveScopes;

/**
 * Class Link - To download catalog logs
 */
class DownloadCatalogLogs extends RemoveScopes
{
    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return sprintf(
            '<a href="%s"  style="display: block;padding-top: 12px;">%s</a>',
            rtrim($this->_urlBuilder->getUrl('yotpoadmin/index/downloadlogs', ['logName'=>'catalog']), '/'),
            __('Download Logs')
        );
    }
}
