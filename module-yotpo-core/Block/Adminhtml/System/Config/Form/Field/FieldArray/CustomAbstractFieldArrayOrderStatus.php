<?php
namespace Yotpo\Core\Block\Adminhtml\System\Config\Form\Field\FieldArray;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/**
 * Class CustomAbstractFieldArrayOrderStatus
 *
 * Modify template for order status mapping
 */
class CustomAbstractFieldArrayOrderStatus extends AbstractFieldArray
{

    /**
     * @var string
     */
    protected $_template = 'Yotpo_Core::system/config/form/field/array-order-status.phtml';
}
