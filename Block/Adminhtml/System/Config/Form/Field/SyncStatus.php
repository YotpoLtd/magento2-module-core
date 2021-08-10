<?php

namespace Yotpo\Core\Block\Adminhtml\System\Config\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Yotpo\Core\Model\Sync\Data\Main as Data;

/**
 * Class SyncStatus
 *
 * Frontend model to print total synced orders
 */
class SyncStatus extends Field
{
    /**
     * Template path
     *
     * @var string
     */
    protected $_template = 'Yotpo_Core::system/config/form/field/sync_status.phtml';

    /**
     * @var Data
     */
    private $coreData;

    /**
     * SyncStatus constructor
     * @param Context $context
     * @param Data $coreData
     * @param array <mixed> $data
     */
    public function __construct(
        Context $context,
        Data $coreData,
        array $data = []
    ) {
        $this->coreData = $coreData;
        parent::__construct($context, $data);
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
     * Get total synced orders
     *
     * @return string
     */
    public function getTotalSyncedOrders()
    {
        return $this->coreData->getTotalSyncedOrders();
    }
}
