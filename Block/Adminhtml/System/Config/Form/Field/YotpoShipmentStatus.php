<?php
declare(strict_types=1);
namespace Yotpo\Core\Block\Adminhtml\System\Config\Form\Field;

use Magento\Framework\View\Element\Html\Select;

/**
 * Class YotpoShipmentStatus
 * Prepare the Yotpo Shipment Status and display in column
 */
class YotpoShipmentStatus extends Select
{
    /**
     * @var array<int, string>
     */
    protected $status = [
        'out_for_delivery',
        'label_printed',
        'label_purchased',
        'attempted_delivery',
        'delivered',
        'in_transit',
        'failure',
        'ready_for_pickup',
        'confirmed'
    ];

    /**
     * Set "name" for <select> element
     *
     * @param string $value
     * @return YotpoShipmentStatus $this
     */
    public function setInputName($value)
    {
        return $this->setName($value);
    }

    /**
     * Set "id" for <select> element
     *
     * @param string $value
     * @return $this
     */
    public function setInputId($value)
    {
        return $this->setId($value);
    }

    /**
     * Render HTML
     *
     * @return string
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */

    public function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->setOptions($this->getSourceOptions());
        }
        return parent::_toHtml();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function getSourceOptions(): array
    {
        $status_data = [];

        foreach ($this->status as $item) {
            $status_data[] = [
                'label' => $item,
                'value' => $item
            ];
        }

        return $status_data;
    }
}
