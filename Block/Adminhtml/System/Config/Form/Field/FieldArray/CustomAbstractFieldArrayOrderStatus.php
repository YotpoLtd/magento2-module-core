<?php
namespace Yotpo\Core\Block\Adminhtml\System\Config\Form\Field\FieldArray;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Yotpo\Core\Model\Config;

/**
 * Class CustomAbstractFieldArrayOrderStatus
 *
 * Modify template for order status mapping
 */
class CustomAbstractFieldArrayOrderStatus extends AbstractFieldArray
{

    /**
     * @var Config
     */
    protected $yotpoConfig;

    /**
     * @var string
     */
    protected $_template = 'Yotpo_Core::system/config/form/field/array-order-status.phtml';

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
            $data['template'] = 'Yotpo_Core::system/config/form/field/array-order-status-old-versions.phtml';
        }
        parent::__construct($context, $data);
    }
}
