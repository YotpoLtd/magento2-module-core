<?php
declare(strict_types=1);
namespace Yotpo\Core\Block\Adminhtml\System\Config\Form\Field;

use Magento\Framework\View\Element\Html\Select;
use Magento\Framework\View\Element\Context;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory as StatusCollectionFactory;

/**
 * Class for the column 'Store Order Status'
 */
class MagentoOrderStatusColumn extends Select
{
    /**
     * @var StatusCollectionFactory
     */
    protected $statusCollectionFactory;

    /**
     * MagentoStatusColumn constructor.
     * @param Context $context
     * @param StatusCollectionFactory $statusCollectionFactory
     * @param array<mixed> $data
     */
    public function __construct(
        Context $context,
        StatusCollectionFactory $statusCollectionFactory,
        array $data = []
    ) {
        $this->statusCollectionFactory = $statusCollectionFactory;
        parent::__construct($context, $data);
    }

    /**
     * Set "name" for <select> element
     *
     * @param string $value
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
        return $this->statusCollectionFactory->create()->toOptionArray();
    }
}
