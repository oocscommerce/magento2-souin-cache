<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com).
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\SouinCache\Model;

use Exception;
use Laminas\Http\Client\Adapter\Socket;
use Laminas\Uri\Uri;
use Magento\CacheInvalidate\Model\SocketFactory;
use Magento\Framework\Cache\InvalidateLogger;
use Magento\PageCache\Model\Cache\Server;
use OOCS\SouinCache\Helper\Data;

/**
 * Send purge requests to the Souin API of every host in http_cache_hosts.
 *
 * By tags: PURGE {api path} with "Surrogate-Key: tag1, tag2"; everything: PURGE {api path}/flush
 */
class PurgeClient
{
    public const HEADER_SURROGATE_KEY = 'Surrogate-Key';

    /**
     * Separator of the Souin surrogate keys storage
     */
    protected const SURROGATE_KEY_SEPARATOR = ', ';

    /**
     * @var Server
     */
    protected $cacheServer;

    /**
     * @var SocketFactory
     */
    protected $socketAdapterFactory;

    /**
     * @var InvalidateLogger
     */
    protected $logger;

    /**
     * @var Data
     */
    protected $helper;

    /**
     * @var int
     */
    protected $maxHeaderSize;

    /**
     * @param Server $cacheServer
     * @param SocketFactory $socketAdapterFactory
     * @param InvalidateLogger $logger
     * @param Data $helper
     * @param int $maxHeaderSize
     */
    public function __construct(
        Server $cacheServer,
        SocketFactory $socketAdapterFactory,
        InvalidateLogger $logger,
        Data $helper,
        int $maxHeaderSize = 7680
    ) {
        $this->cacheServer = $cacheServer;
        $this->socketAdapterFactory = $socketAdapterFactory;
        $this->logger = $logger;
        $this->helper = $helper;
        $this->maxHeaderSize = $maxHeaderSize;
    }

    /**
     * Purge the pages tagged with any of the given tags
     *
     * @param string[] $tags
     * @return bool True if every chunk reached at least one cache host
     */
    public function purgeTags(array $tags): bool
    {
        $tags = array_values(array_unique(array_filter(array_map('trim', $tags), 'strlen')));
        $successful = true;
        foreach ($this->chunkTags($tags) as $chunk) {
            $headers = [self::HEADER_SURROGATE_KEY => implode(self::SURROGATE_KEY_SEPARATOR, $chunk)];
            if (!$this->send($this->helper->getApiPath(), $headers)) {
                $successful = false;
            }
        }

        return $successful;
    }

    /**
     * Purge the whole cache and its surrogate keys storage
     *
     * @return bool
     */
    public function purgeAll(): bool
    {
        return $this->send(rtrim($this->helper->getApiPath(), '/') . '/flush', []);
    }

    /**
     * Split tags into batches that fit in one Surrogate-Key header
     *
     * @param string[] $tags
     * @return array
     */
    protected function chunkTags(array $tags): array
    {
        $chunks = [];
        $chunk = [];
        $size = 0;
        $separatorSize = strlen(self::SURROGATE_KEY_SEPARATOR);
        foreach ($tags as $tag) {
            $tagSize = strlen($tag) + ($chunk ? $separatorSize : 0);
            if ($chunk && $size + $tagSize > $this->maxHeaderSize) {
                $chunks[] = $chunk;
                $chunk = [];
                $size = 0;
                $tagSize = strlen($tag);
            }
            $chunk[] = $tag;
            $size += $tagSize;
        }
        if ($chunk) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * Send one PURGE request to every cache host
     *
     * @param string $path
     * @param array $headers
     * @return bool True if at least one host accepted it
     */
    protected function send(string $path, array $headers): bool
    {
        $servers = $this->cacheServer->getUris();
        $errors = [];
        foreach ($servers as $server) {
            /** @var Uri $uri */
            $uri = clone $server;
            $uri->setPath($path);
            $headers['Host'] = $uri->getHost();
            /** @var Socket $socketAdapter */
            $socketAdapter = $this->socketAdapterFactory->create();
            $socketAdapter->setOptions(['timeout' => 10]);
            try {
                $socketAdapter->connect($uri->getHost(), $uri->getPort());
                $socketAdapter->write('PURGE', $uri, '1.1', $headers);
                $status = $this->getStatusCode((string)$socketAdapter->read());
                $socketAdapter->close();
                if ($status < 200 || $status >= 300) {
                    $errors[] = sprintf('Cache host %s:%s answered HTTP %d', $uri->getHost(), $uri->getPort(), $status);
                }
            } catch (Exception $e) {
                $errors[] = sprintf(
                    'Cache host %s:%s resulted in error message: %s',
                    $uri->getHost(),
                    $uri->getPort(),
                    $e->getMessage()
                );
            }
        }

        $context = ['servers' => $servers, 'path' => $path, 'headers' => $headers];
        if ($errors && count($errors) === count($servers)) {
            $this->logger->critical('No Souin cache host could be purged: ' . implode(' ', $errors), $context);
            return false;
        }
        if ($errors) {
            $this->logger->warning('Unresponsive Souin cache host(s): ' . implode(' ', $errors), $context);
        }
        $this->logger->execute($context);

        return true;
    }

    /**
     * @param string $response
     * @return int
     */
    protected function getStatusCode(string $response): int
    {
        return preg_match('#^HTTP/\S+\s+(\d{3})#', $response, $matches) ? (int)$matches[1] : 0;
    }
}
