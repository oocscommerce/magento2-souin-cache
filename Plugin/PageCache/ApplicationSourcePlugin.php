<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com).
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\SouinCache\Plugin\PageCache;

use Magento\PageCache\Model\System\Config\Source\Application;
use OOCS\SouinCache\Helper\Data;

/**
 * Add Souin to the "Caching Application" options.
 */
class ApplicationSourcePlugin
{
    /**
     * @param Application $subject
     * @param array $result
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterToOptionArray(Application $subject, $result)
    {
        $result[] = [
            'value' => Data::SOUIN,
            'label' => __('Souin Cache')
        ];

        return $result;
    }

    /**
     * @param Application $subject
     * @param array $result
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterToArray(Application $subject, $result)
    {
        $result[Data::SOUIN] = __('Souin Cache');

        return $result;
    }
}
