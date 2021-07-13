<?php
namespace Yotpo\Core\Block\Adminhtml\System\Config\Form\Field\FieldArray;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/**
 * Class CustomAbstractFieldArray
 *
 * Modify template for shipment status mapping
 */
class CustomAbstractFieldArray extends AbstractFieldArray
{

    /**
     * @var string
     */
    protected $_template = 'Yotpo_Core::system/config/form/field/array.phtml';
}
