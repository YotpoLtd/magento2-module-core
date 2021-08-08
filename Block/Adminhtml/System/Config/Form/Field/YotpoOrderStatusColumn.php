<?php
declare(strict_types=1);
namespace Yotpo\Core\Block\Adminhtml\System\Config\Form\Field;

use Magento\Framework\View\Element\Html\Select;

/**
 * Class for the column 'Yotpo Order Status'
 */
class YotpoOrderStatusColumn extends Select
{
    /**
     * Set "name" for <select> element
     *
     * @param string    $value
     * @return mixed
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
     * Gets the options
     *
     * @return array<mixed>
     */
    private function getSourceOptions(): array
    {
        return [
            ['label' => 'Fulfillment pending', 'value' => 'pending'],
            ['label' => 'Fulfillment open', 'value' => 'open'],
            ['label' => 'Fulfillment success', 'value' => 'success'],
            ['label' => 'Order cancelled', 'value' => 'cancelled']
        ];
    }
}
