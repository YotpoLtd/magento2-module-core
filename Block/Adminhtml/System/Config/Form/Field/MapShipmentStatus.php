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
            'style' => 'background:transparent;border:none;opacity:1'
        ]);
        $this->addColumn('store_shipment_status', [
            'label' => __('Store Shipment Status')
        ]);

        $this->_addAfter = false;
    }

    /**
     * Render array cell for prototypeJS template
     *
     * @param string $columnName
     * @return string
     * @throws \Exception
     */
    public function renderCellTemplate($columnName)
    {
        if (empty($this->_columns[$columnName])) {
            // phpcs:ignore
            throw new \Exception('Wrong column name specified.');
        }
        $column = $this->_columns[$columnName];
        $inputName = $this->_getCellInputElementName($columnName);

        if ($column['renderer']) {
            return parent::renderCellTemplate($columnName);
        }

        $readonly = '';
        if ('yotpo_shipment_status' == $columnName) {
            $readonly = 'readonly';
        }
        return '<input ' . $readonly .' type="text" id="' . $this->_getCellInputElementId(
            '<%- _id %>',
            $columnName
        ) .
            '"' .
            ' name="' .
            $inputName .
            '" value="<%- ' .
            $columnName .
            ' %>" ' .
            ($column['size'] ? 'size="' .
                $column['size'] .
                '"' : '') .
            ' class="' .
            (isset($column['class'])
                ? $column['class']
                : 'input-text') . '"' . (isset($column['style']) ? ' style="' . $column['style'] . '"' : '') . '/>';
    }
}
