<?php

namespace Yotpo\Core\Block\Adminhtml\System\Config\Form\Field;

use Magento\Backend\Block\AbstractBlock;
use Magento\Framework\Data\Form\Element\Renderer\RendererInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Class Heading
 * Renderer for sub-heading in fieldset
 */
class Heading extends AbstractBlock implements RendererInterface
{
    /**
     * Render element html
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        return sprintf(
            '<tr class="system-fieldset-sub-head" id="row_%s"><td colspan="5"><h3 id="%s">%s</h3></td></tr>',
            $element->getHtmlId(),
            $element->getHtmlId(),
            $element->getLabel()
        );
    }
}
