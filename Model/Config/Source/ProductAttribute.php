<?php
namespace Yotpo\Core\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;

/**
 * Prepare the product attributes array
 */
class ProductAttribute implements OptionSourceInterface
{
    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * ProductAttribute constructor.
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        CollectionFactory $collectionFactory
    ) {
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        $attribute_data = [];
        $attributeInfo = $this->collectionFactory->create();
        $attribute_data[] = [
            'label' => __('Select from product attributes'),
            'value' => ''
        ];
        foreach ($attributeInfo as $item) {
            $attribute_data[] = [
                'label' => $item->getData('frontend_label'),
                'value' => $item->getData('attribute_code')
            ];
        }

        return $attribute_data;
    }
}
