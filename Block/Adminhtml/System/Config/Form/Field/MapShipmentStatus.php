<?php

namespace Yotpo\Core\Block\Adminhtml\System\Config\Form\Field;

use Yotpo\Core\Block\Adminhtml\System\Config\Form\Field\FieldArray\CustomAbstractFieldArray;

/**
 * Maps Yotpo shipment status to Store shipment status
 */
class MapShipmentStatus extends CustomAbstractFieldArray
{
    /**
     * Prepare rendering the new field by adding all the needed columns
     */
    protected function _prepareToRender()
    {
        $this->addColumn('yotpo_shipment_status', [
            'label' => __('Yotpo Shipment Status'),
            'class' => 'required-entry',
            'style' => 'background:transparent;border:none'
        ]);
        $this->addColumn('store_shipment_status', [
            'label' => __('Store Shipment Status')
        ]);

        $this->_addAfter = false;
    }
}
