<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com).
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\SouinCache\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use OOCS\SouinCache\Helper\Data;
use OOCS\SouinCache\Model\TagCollector;

/**
 * Purge the whole Souin cache.
 *
 * Souin counterpart of Magento\CacheInvalidate\Observer\FlushAllCacheObserver.
 */
class FlushAllObserver implements ObserverInterface
{
    /**
     * @var Data
     */
    protected $helper;

    /**
     * @var TagCollector
     */
    protected $tagCollector;

    /**
     * @param Data $helper
     * @param TagCollector $tagCollector
     */
    public function __construct(
        Data $helper,
        TagCollector $tagCollector
    ) {
        $this->helper = $helper;
        $this->tagCollector = $tagCollector;
    }

    /**
     * @param Observer $observer
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function execute(Observer $observer)
    {
        if ($this->helper->isEnabled()) {
            $this->tagCollector->purgeAll();
        }
    }
}
