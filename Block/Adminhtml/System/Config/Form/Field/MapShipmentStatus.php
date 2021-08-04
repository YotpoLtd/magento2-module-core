<?php

namespace Yotpo\Core\Block\Adminhtml\System\Config\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Yotpo\Core\Model\Config;

/**
 * Class MapShipmentStatus
 * Maps Yotpo shipment status to Store shipment status
 */
class MapShipmentStatus extends AbstractFieldArray
{
    /**
     * @var string
     */
    protected $_template = 'Yotpo_Core::system/config/form/field/array-shipment-status.phtml';

    /**
     * @var YotpoShipmentStatus
     */
    protected $shipmentStatus;

    /**
     * @var Config
     */
    protected $yotpoConfig;

    /**
     * @param Config $yotpoConfig
     * @param Context $context
     * @param array <mixed> $data
     */
    public function __construct(
        Config $yotpoConfig,
        Context $context,
        array $data = []
    ) {
        $this->yotpoConfig = $yotpoConfig;
        $magentoVersion = $this->yotpoConfig->getMagentoVersion();
        if (stripos($magentoVersion, '2.1') !== false ||
            stripos($magentoVersion, '2.2') !== false ||
            stripos($magentoVersion, '2.3') !== false
        ) {
            $data['template'] = 'Yotpo_Core::system/config/form/field/array-shipment-status-old-versions.phtml';
        }
        parent::__construct($context, $data);
    }

    /**
     * Prepare the dynamic columns
     *
     * @throws LocalizedException
     */
    protected function _prepareToRender()
    {
        $this->addColumn('yotpo_shipment_status', [
            'label' => __('Yotpo Shipment Status'),
            'class' => 'required-entry',
            'renderer' => $this->getShipmentStatus()
        ]);
        $this->addColumn('store_shipment_status', [
            'label' => __('Store Shipment Status')
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add');
    }

    /**
     * Prepare existing row data object
     *
     * @param DataObject $row
     * @throws LocalizedException
     */
    protected function _prepareArrayRow(DataObject $row): void
    {
        $yotpoStatus = $row->getData('yotpo_shipment_status');
        $options = [];
        if ($yotpoStatus) {
            $name = 'option_'.$this->getShipmentStatus()->calcOptionHash($yotpoStatus);
            $options[$name] = 'selected="selected"';
        }
        $row->setData('option_extra_attrs', $options);
    }

    /**
     * Prepare the column with dynamic data
     *
     * @return YotpoShipmentStatus
     * @throws LocalizedException
     */
    protected function getShipmentStatus()
    {
        if (!$this->shipmentStatus instanceof YotpoShipmentStatus) {
            /** @phpstan-ignore-next-line */
            $this->shipmentStatus = $this->getLayout()->createBlock(
                YotpoShipmentStatus::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }

        return $this->shipmentStatus;
    }
}
