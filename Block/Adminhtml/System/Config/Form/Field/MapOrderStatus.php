<?php

namespace Yotpo\Core\Block\Adminhtml\System\Config\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\BlockInterface;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory as StatusCollectionFactory;
use Yotpo\Core\Block\Adminhtml\System\Config\Form\Field\FieldArray\CustomAbstractFieldArrayOrderStatus;
use Yotpo\Core\Model\Config;

/**
 * Maps Store order status to Yotpo order status
 */
class MapOrderStatus extends CustomAbstractFieldArrayOrderStatus
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
     * @var StatusCollectionFactory
     */
    protected $statusCollectionFactory;

    /**
     * MapOrderStatus constructor.
     * @param Context $context
     * @param StatusCollectionFactory $statusCollectionFactory
     * @param Config $yotpoConfig
     */
    public function __construct(
        Context $context,
        StatusCollectionFactory $statusCollectionFactory,
        Config $yotpoConfig
    ) {
        parent::__construct($yotpoConfig, $context);
        $this->statusCollectionFactory = $statusCollectionFactory;
    }

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

    /**
     * Obtain existing data from form element
     *
     * Each row will be instance of \Magento\Framework\DataObject
     *
     * @return array<mixed>
     */
    public function getArrayRows()
    {
        $newValues = [];
        $orderStatuses = $this->statusCollectionFactory->create()->toOptionArray();
        foreach ($orderStatuses as $statuses) {
            $newValueItem = [
                'store_order_status' => $statuses['value'],
                'yotpo_order_status' => 0
            ];
            $newValues[] = $newValueItem;
        }

        $element = $this->getElement();
        $existingValue = $element->getValue();
        $existingValue = $this->removeDuplicates($existingValue);
        $index = 1;
        foreach ($newValues as $newValue) {
            if ($existingValue) {
                $duplicated = $this->checkDuplication($newValue['store_order_status'], $existingValue);
            } else {
                $duplicated = false;
            }
            if (!$duplicated) {
                $time = time();
                $existingValue['_'.($index++).'_'.$time.uniqid()] = $newValue;
            }
        }
        $element->setValue($existingValue);
        return parent::getArrayRows();
    }

    /**
     * @param string $newValue
     * @param array<mixed> $existingValue
     * @return bool
     */
    public function checkDuplication($newValue, $existingValue)
    {
        if (!$existingValue || !is_array($existingValue)) {
            return false;
        }
        foreach (array_values($existingValue) as $existVal) {
            if ($existVal['store_order_status'] == $newValue) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<mixed> $existingValue
     * @return array<mixed>
     */
    public function removeDuplicates($existingValue)
    {
        if (!$existingValue || !is_array($existingValue)) {
            return [];
        }
        $uniqueArray = [];

        foreach ($existingValue as $id => $existVal) {
            if (in_array($existVal['store_order_status'], $uniqueArray)) {
                unset($existingValue[$id]);
                continue;
            }
            $uniqueArray[] = $existVal['store_order_status'];
        }
        return $existingValue;
    }
}
