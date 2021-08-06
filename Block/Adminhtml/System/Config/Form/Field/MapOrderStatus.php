<?php

namespace Yotpo\Core\Block\Adminhtml\System\Config\Form\Field;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\BlockInterface;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/**
 * Maps Store order status to Yotpo order status
 */
class MapOrderStatus extends AbstractFieldArray
{

    /**
     * @var YotpoOrderStatusColumn
     */
    private $displayRenderer;

    /**
     * @var MagentoOrderStatusColumn
     */
    private $displayMagentoRenderer;

    /**
     * Prepare rendering the new field by adding all the needed columns
     */
    protected function _prepareToRender()
    {
        $this->addColumn('store_order_status', [
            'label' => __('Store Order Status'),
            'class' => 'required-entry',
            'renderer' => $this->getMagentoDisplayRenderer()
        ]);
        $this->addColumn('yotpo_order_status', [
            'label' => __('Yotpo Order Status'),
            'class' => 'required-entry',
            'renderer' => $this->getDisplayRenderer()
        ]);
        $this->_addAfter = false;
    }

    /**
     * Prepare existing row data object
     *
     * @param DataObject $row
     * @throws LocalizedException
     */
    protected function _prepareArrayRow(DataObject $row): void
    {
        $options = [];
        $display = $row->getDisplay();
        if ($display !== null) {
            $options['option_' .
            $this->getDisplayRenderer()->calcOptionHash($display)] = 'selected="selected"';/** @phpstan-ignore-line */
        }

        $row->setData('option_extra_attrs', $options);
    }

    /**
     * @return BlockInterface|YotpoOrderStatusColumn
     * @throws LocalizedException
     */
    private function getDisplayRenderer()
    {
        /** @phpstan-ignore-next-line */
        if (!$this->displayRenderer) {
            /** @phpstan-ignore-next-line */
            $this->displayRenderer = $this->getLayout()->createBlock(
                YotpoOrderStatusColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->displayRenderer;
    }

    /**
     * @return BlockInterface|MagentoOrderStatusColumn
     * @throws LocalizedException
     */
    private function getMagentoDisplayRenderer()
    {
        /** @phpstan-ignore-next-line */
        if (!$this->displayMagentoRenderer) {
            /** @phpstan-ignore-next-line */
            $this->displayMagentoRenderer = $this->getLayout()->createBlock(
                MagentoOrderStatusColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->displayMagentoRenderer;
    }
}
