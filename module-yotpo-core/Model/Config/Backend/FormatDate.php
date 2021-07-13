<?php

namespace Yotpo\Core\Model\Config\Backend;

use Magento\Framework\App\Config\Value as ConfigValue;
use Yotpo\Core\Helper\Data as HelperData;

/**
 * Class FormatDate - Format date for admin fields
 */
class FormatDate extends ConfigValue
{
    /**
     * @var HelperData
     */
    protected $helperData;

    /**
     * FormatDate constructor.
     * @param HelperData $helperData
     */
    public function __construct(
        HelperData $helperData
    ) {
        $this->helperData = $helperData;
    }
    /**
     * Process data after load
     *
     * @return $this
     */
    protected function _afterLoad()
    {
        $value = $this->getValue();
        $formattedDate = $this->helperData->formatAdminConfigDate($value);
        $this->setValue($formattedDate);

        return $this;
    }
}
