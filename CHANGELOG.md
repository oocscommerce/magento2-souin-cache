# Changelog

## 1.0.0

- Souin Cache (148) as a Full Page Cache caching application.
- Storefront and GraphQL responses carry `Surrogate-Key` and `Souin-Cache-Control`; cacheable pages lose their `Set-Cookie` and are never cached by browsers.
- Purge by tag through the Souin API on the Magento_CacheInvalidate events, sent once per request; full flush from Cache Management and `cache:flush`.
- Caddyfile generated from the configuration: "Export Caddyfile" button and `bin/magento souin:caddyfile:generate`, with Otter or Redis storage.
- Dockerfile building Caddy with the Souin cache handler.
