<?php
namespace Yotpo\Core\Block\Adminhtml\System\Config\Form\Field\FieldArray;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Yotpo\Core\Model\Config;

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

    /**
     * @var Config
     */
    protected $yotpoConfig;

    /**
     * CustomAbstractFieldArrayOrderStatus constructor.
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
            $data['template'] = 'Yotpo_Core::system/config/form/field/array-old-versions.phtml';
        }
        parent::__construct($context, $data);
    }
}
