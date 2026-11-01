<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com).
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\SouinCache\Model;

/**
 * Collect the tags to purge during a web request and send them once at the end of it.
 *
 * Saving one entity fires many clean_cache_by_tags events; this turns them into one purge request.
 * CLI processes (cron, queue consumers, imports) can live for hours, so they purge right away.
 */
class TagCollector
{
    /**
     * @var PurgeClient
     */
    protected $purgeClient;

    /**
     * @var bool
     */
    protected $deferred;

    /**
     * @var string[] Tags as keys
     */
    protected $tags = [];

    /**
     * @var bool
     */
    protected $purgeAll = false;

    /**
     * @var bool
     */
    protected $shutdownRegistered = false;

    /**
     * @param PurgeClient $purgeClient
     * @param bool|null $deferred Defaults to true outside the CLI
     */
    public function __construct(
        PurgeClient $purgeClient,
        ?bool $deferred = null
    ) {
        $this->purgeClient = $purgeClient;
        $this->deferred = $deferred ?? PHP_SAPI !== 'cli';
    }

    /**
     * @param string[] $tags
     * @return void
     */
    public function addTags(array $tags)
    {
        if (!$tags) {
            return;
        }
        if (!$this->deferred) {
            $this->purgeClient->purgeTags($tags);
            return;
        }

        foreach ($tags as $tag) {
            $this->tags[(string)$tag] = true;
        }
        $this->registerShutdown();
    }

    /**
     * @return void
     */
    public function purgeAll()
    {
        if (!$this->deferred) {
            $this->purgeClient->purgeAll();
            return;
        }

        $this->purgeAll = true;
        $this->registerShutdown();
    }

    /**
     * Send what was collected; a full purge makes the tags useless
     *
     * @return void
     */
    public function flush()
    {
        $purgeAll = $this->purgeAll;
        $tags = array_keys($this->tags);
        $this->purgeAll = false;
        $this->tags = [];

        if ($purgeAll) {
            $this->purgeClient->purgeAll();
        } elseif ($tags) {
            $this->purgeClient->purgeTags($tags);
        }
    }

    /**
     * @return void
     */
    protected function registerShutdown()
    {
        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function([$this, 'flush']);
        }
    }
}
