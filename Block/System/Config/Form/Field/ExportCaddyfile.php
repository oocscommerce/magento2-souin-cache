<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com).
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\SouinCache\Block\System\Config\Form\Field;

use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Export Caddyfile" button, like Magento's "Export VCL for Varnish" one.
 */
class ExportCaddyfile extends Field
{
    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        /** @var Button $button */
        $button = $this->getForm()->getLayout()->createBlock(Button::class);
        $url = $this->getUrl('*/souin/exportCaddyfile', ['website' => $button->getRequest()->getParam('website')]);

        return $button->setData([
            'id' => 'system_full_page_cache_souin_export_caddyfile',
            'label' => __('Export Caddyfile'),
            'onclick' => "setLocation('" . $this->escapeJs($url) . "')",
        ])->toHtml();
    }
}
