<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com).
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\SouinCache\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\PageCache\Model\Config as PageCacheConfig;

/**
 * Souin full page cache configuration.
 */
class Data extends AbstractHelper
{
    /**
     * Value of system/full_page_cache/caching_application for Souin
     */
    public const SOUIN = 148;

    public const XML_PATH_API_PATH = 'system/full_page_cache/souin/api_path';

    /**
     * @var PageCacheConfig
     */
    protected $pageCacheConfig;

    /**
     * @param Context $context
     * @param PageCacheConfig $pageCacheConfig
     */
    public function __construct(
        Context $context,
        PageCacheConfig $pageCacheConfig
    ) {
        parent::__construct($context);
        $this->pageCacheConfig = $pageCacheConfig;
    }

    /**
     * Souin is the selected caching application and the full_page cache type is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->pageCacheConfig->getType() === self::SOUIN && $this->pageCacheConfig->isEnabled();
    }

    /**
     * Public content TTL in seconds
     *
     * @return int
     */
    public function getTtl(): int
    {
        return (int)$this->pageCacheConfig->getTtl();
    }

    /**
     * Souin API path, e.g. /souin-api/souin
     *
     * @return string
     */
    public function getApiPath(): string
    {
        return '/' . trim((string)$this->scopeConfig->getValue(self::XML_PATH_API_PATH), '/');
    }
}
