# Changelog

## 1.0.1

- Full flush purges every key (`PURGE {api path}/.+`) instead of calling `/flush`, which closed the Redis connection of Souin v1.7.9 for good: nothing was cached after a flush until Caddy restarted.
- Dockerfile: the Otter and Redis storages are patched to LZ4 blocks of 64 KB instead of 4 MB. Each cache hit allocated and cleared a 4 MB buffer: about 11 times more requests per second on HTML pages, 15 times less memory.
- Dockerfile: pinned to the tested cache-handler v0.17.0 and storages v0.0.22.

## 1.0.0

- Souin Cache (148) as a Full Page Cache caching application.
- Storefront and GraphQL responses carry `Surrogate-Key` and `Souin-Cache-Control`; cacheable pages lose their `Set-Cookie` and are never cached by browsers.
- Purge by tag through the Souin API on the Magento_CacheInvalidate events, sent once per request; full flush from Cache Management and `cache:flush`.
- Caddyfile generated from the configuration: "Export Caddyfile" button and `bin/magento souin:caddyfile:generate`, with Otter or Redis storage.
- Dockerfile building Caddy with the Souin cache handler.
