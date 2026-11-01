<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com).
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\SouinCache\Observer;

use Magento\Framework\App\Cache\Tag\Resolver;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use OOCS\SouinCache\Helper\Data;
use OOCS\SouinCache\Model\TagCollector;

/**
 * Purge the pages tagged with the cache tags of the saved/deleted object.
 *
 * Souin counterpart of Magento\CacheInvalidate\Observer\InvalidateVarnishObserver.
 */
class InvalidateObserver implements ObserverInterface
{
    /**
     * @var Data
     */
    protected $helper;

    /**
     * @var Resolver
     */
    protected $tagResolver;

    /**
     * @var TagCollector
     */
    protected $tagCollector;

    /**
     * @param Data $helper
     * @param Resolver $tagResolver
     * @param TagCollector $tagCollector
     */
    public function __construct(
        Data $helper,
        Resolver $tagResolver,
        TagCollector $tagCollector
    ) {
        $this->helper = $helper;
        $this->tagResolver = $tagResolver;
        $this->tagCollector = $tagCollector;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $object = $observer->getEvent()->getObject();
        if (!is_object($object) || !$this->helper->isEnabled()) {
            return;
        }

        $this->tagCollector->addTags($this->tagResolver->getTags($object));
    }
}
