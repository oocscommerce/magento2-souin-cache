<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com).
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\SouinCache\Plugin\Framework\App\Response;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\Response\HttpInterface;
use Magento\Framework\App\PageCache\NotCacheableInterface;
use OOCS\SouinCache\Helper\Data;

/**
 * Turn Magento page cache headers into Souin headers right before the response is sent.
 *
 * Cacheable response: Surrogate-Key (tags), Souin-Cache-Control (shared cache TTL), no-store for the browser
 * and no Set-Cookie, so a cached page never replays a visitor's cookies.
 */
class HttpPlugin
{
    public const HEADER_SURROGATE_KEY = 'Surrogate-Key';
    public const HEADER_SOUIN_CACHE_CONTROL = 'Souin-Cache-Control';
    public const HEADER_MAGENTO_TAGS = 'X-Magento-Tags';
    public const HEADER_CACHE_ID = 'X-Magento-Cache-Id';

    /**
     * Separator of the Souin surrogate keys storage
     */
    protected const SURROGATE_KEY_SEPARATOR = ', ';

    /**
     * @var Data
     */
    protected $helper;

    /**
     * @var HttpRequest
     */
    protected $request;

    /**
     * @param Data $helper
     * @param HttpRequest $request
     */
    public function __construct(
        Data $helper,
        HttpRequest $request
    ) {
        $this->helper = $helper;
        $this->request = $request;
    }

    /**
     * Runs after Magento_PageCache's plugin, which sends the X-Magento-Vary cookie
     *
     * @param HttpResponse $subject
     * @return void
     */
    public function beforeSendResponse(HttpResponse $subject)
    {
        if (!$this->helper->isEnabled() || $subject->headersSent()) {
            return;
        }

        if (!$this->isCacheable($subject)) {
            $subject->setHeader(self::HEADER_SOUIN_CACHE_CONTROL, 'no-store', true);
            $subject->clearHeader(self::HEADER_MAGENTO_TAGS);
            return;
        }

        $ttl = $this->getTtl($subject);
        $tags = $this->getTags($subject);
        $subject->clearHeader(self::HEADER_MAGENTO_TAGS);
        if ($tags) {
            $subject->setHeader(self::HEADER_SURROGATE_KEY, implode(self::SURROGATE_KEY_SEPARATOR, $tags), true);
        }

        if ($this->isVaryCookieMissing() || $this->isCacheIdMismatch($subject)) {
            // Rendered for a context the cache key does not know yet: do not store it
            $subject->setHeader(self::HEADER_SOUIN_CACHE_CONTROL, 'no-store', true);
            $subject->setNoCacheHeaders();
            return;
        }

        $subject->setHeader(self::HEADER_SOUIN_CACHE_CONTROL, 'public, s-maxage=' . $ttl, true);
        $this->removeCookies($subject);

        // Browsers must always come back to the shared cache. No "no-cache": Souin reads the stored
        // Cache-Control on a hit and would revalidate every request against the backend.
        $subject->setHeader('Cache-Control', 'max-age=0, must-revalidate', true);
        $subject->clearHeader('Pragma');
        $subject->clearHeader('Expires');
    }

    /**
     * Response was marked public by Magento (page cache or GraphQL cache plugins)
     *
     * @param HttpResponse $response
     * @return bool
     */
    protected function isCacheable(HttpResponse $response): bool
    {
        if ($response instanceof NotCacheableInterface || $response->getMetadata('NotCacheable')) {
            return false;
        }
        if (!in_array((int)$response->getStatusCode(), [200, 404], true)) {
            return false;
        }

        return stripos($this->getHeaderValue($response, 'Cache-Control'), 'public') !== false;
    }

    /**
     * TTL from s-maxage / max-age set by Magento, else the configured public content TTL
     *
     * @param HttpResponse $response
     * @return int
     */
    protected function getTtl(HttpResponse $response): int
    {
        $cacheControl = $this->getHeaderValue($response, 'Cache-Control');
        if (preg_match('/s-maxage=(\d+)/i', $cacheControl, $matches)
            || preg_match('/max-age=(\d+)/i', $cacheControl, $matches)
        ) {
            return (int)$matches[1];
        }

        return $this->helper->getTtl();
    }

    /**
     * @param HttpResponse $response
     * @return string[]
     */
    protected function getTags(HttpResponse $response): array
    {
        $tags = array_map('trim', explode(',', $this->getHeaderValue($response, self::HEADER_MAGENTO_TAGS)));

        return array_values(array_unique(array_filter($tags, 'strlen')));
    }

    /**
     * Request has no X-Magento-Vary cookie but the response sets one (same rule as the Magento VCL)
     *
     * @return bool
     */
    protected function isVaryCookieMissing(): bool
    {
        if ($this->request->getHeader(self::HEADER_CACHE_ID)) {
            return false;
        }
        if ($this->request->getCookie(HttpResponse::COOKIE_VARY_STRING) !== null) {
            return false;
        }

        foreach ($this->getSentCookies() as $cookie) {
            if (stripos($cookie, HttpResponse::COOKIE_VARY_STRING . '=') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * GraphQL response built for another cache id than the one requested
     *
     * @param HttpResponse $response
     * @return bool
     */
    protected function isCacheIdMismatch(HttpResponse $response): bool
    {
        $requestCacheId = (string)$this->request->getHeader(self::HEADER_CACHE_ID);

        return $requestCacheId !== '' && $requestCacheId !== $this->getHeaderValue($response, self::HEADER_CACHE_ID);
    }

    /**
     * Cookies queued with setcookie()/session_start()
     *
     * @return string[]
     */
    protected function getSentCookies(): array
    {
        $cookies = [];
        foreach (headers_list() as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) {
                $cookies[] = ltrim(substr($header, strlen('Set-Cookie:')));
            }
        }

        return $cookies;
    }

    /**
     * @param HttpResponse $response
     * @return void
     */
    protected function removeCookies(HttpResponse $response)
    {
        header_remove('Set-Cookie');
        $response->clearHeader('Set-Cookie');
    }

    /**
     * @param HttpInterface $response
     * @param string $name
     * @return string
     */
    protected function getHeaderValue(HttpInterface $response, string $name): string
    {
        $header = $response->getHeader($name);

        return $header ? (string)$header->getFieldValue() : '';
    }
}
