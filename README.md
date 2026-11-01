# Souin Cache

`oocscommerce/magento2-souin-cache`

Full page cache for Magento 2 with [Souin](https://github.com/darkweak/souin), the HTTP cache of
[Caddy](https://caddyserver.com). Souin is a new **Caching Application** next to
Built-in and Varnish: Magento tags its pages, Souin stores them and Magento purges them by tag when
data changes.

```
client -> (TLS proxy / load balancer) -> Caddy + Souin -> nginx/Apache -> PHP-FPM
```

## Features

- Same behaviour as the Magento VCL: cache key with the `X-Magento-Vary` cookie and `X-Forwarded-Proto`,
  GraphQL key with `X-Magento-Cache-Id`, `Store` and `Content-Currency`, pass rules (`/customer`,
  `/checkout`, POST, static/media, GraphQL with a bearer token...), marketing parameters (`utm_*`,
  `gclid`, `fbclid`...) removed, sorted query string, `X-Magento-Cache-Debug: HIT/MISS/UNCACHEABLE`
- Purge by tag through the Souin API (`Surrogate-Key`) on the same events as Varnish, collected during
  a request and sent once; full flush from Cache Management and `bin/magento cache:flush`
- Cached pages never replay a visitor's `Set-Cookie`; browsers never cache HTML
- Gzip handled like Varnish: one gzip copy per page served as is, one plain copy for clients without gzip
- Purge API restricted to an access list of Magento servers
- Backend health check on `/health_check.php`
- In-memory (Otter) or Redis storage, both compressed with LZ4
- Caddyfile generated from the configuration: admin button or `bin/magento souin:caddyfile:generate`

## Benchmark

Magento 2.4 with Luma sample data, one category page, local Docker environment:

| | Time |
| --- | ---: |
| Magento, no cache | ~160 ms |
| Souin hit, from Caddy | 3-4 ms |

## Installation

Requirements: Caddy 2 built with the Souin cache handler (see below).

```bash
composer require oocscommerce/magento2-souin-cache
bin/magento module:enable OOCS_SouinCache
bin/magento setup:upgrade
```

1. **Caching Application**: Stores > Configuration > Advanced > System > Full Page Cache >
   Caching Application = **Souin Cache**, or
   `bin/magento config:set system/full_page_cache/caching_application 148`.
2. **Cache hosts** in `app/etc/env.php`, the Caddy servers to purge (like Varnish):
   ```php
   'http_cache_hosts' => [
       ['host' => '127.0.0.1', 'port' => '80'],
   ],
   ```
3. **Caddy**: build it, generate its Caddyfile, run it (next sections).

## Caddy

### Build

With Docker, from this module directory:

```bash
docker build -t souin-caddy .
```

Without Docker, with [xcaddy](https://github.com/caddyserver/xcaddy):

```bash
xcaddy build \
    --with github.com/caddyserver/cache-handler \
    --with github.com/darkweak/storages/otter/caddy \
    --with github.com/darkweak/storages/redis/caddy
```

Tested with Caddy 2.11.7, cache-handler v0.17.0 (Souin v1.7.9) and storages v0.0.22. The generated
Caddyfile works around two bugs of these versions (commented in it): check them again after an upgrade.

### Caddyfile

Fill the **Souin Configuration** group (Full Page Cache section, shown when Souin is selected), then
click **Export Caddyfile**, or:

```bash
bin/magento souin:caddyfile:generate --output-file=/etc/caddy/Caddyfile
```

| Setting | Default | |
| --- | --- | --- |
| Purge API path | `/souin-api/souin` | Two segments; Magento sends its purge requests there |
| Listen address | `:80` | Caddy site address |
| Backend host / port | `localhost` / `8080` | Web server Caddy forwards to |
| Purge access list | `127.0.0.1/8, ::1` | IPs/CIDRs of the Magento servers allowed to purge |
| Trusted proxies | `private_ranges` | Proxies in front of Caddy whose `X-Forwarded-For`/`X-Forwarded-Proto` are trusted |
| Grace period | `300` | Seconds an expired page may still be served |
| In-memory storage size | `100000` | Maximum entries of the Otter storage (pages and tags) |
| Redis server / DB / password | empty / `4` / empty | `host:port`: use Redis instead of Otter, e.g. shared by several Caddy nodes |

The TTL is **TTL for public content** of the Full Page Cache section. Every command option overrides
its setting: `--listen`, `--backend-host`, `--backend-port`, `--access-list` (repeatable),
`--trusted-proxies` (repeatable), `--grace-period`, `--otter-size`, `--redis-url`, `--redis-db`,
`--redis-password`, `--api-path`, `--ttl`.

### Run

```bash
caddy run --config /etc/caddy/Caddyfile
# or
docker run -d -p 80:80 -v /etc/caddy/Caddyfile:/etc/caddy/Caddyfile:ro souin-caddy
```

Put the web server on the backend port (e.g. nginx on `8080`) and Caddy where the TLS proxy or load
balancer sends the traffic, as with Varnish. Caddy can also terminate TLS itself, with automatic
certificates: use the domain as listen address, e.g. `www.example.com:443`.

## How it works

Before a storefront or GraphQL response is sent, a plugin turns the Magento page cache headers into
Souin ones:

- `X-Magento-Tags` -> `Surrogate-Key`
- cacheable page -> `Souin-Cache-Control: public, s-maxage=<ttl>`, no `Set-Cookie`, and
  `Cache-Control: max-age=0, must-revalidate` for the browser
- anything else, or a page rendered for a context the cache key does not know yet -> `Souin-Cache-Control: no-store`

Saving an entity fires the same events as Varnish purging; its cache tags are sent to every cache host:

```
PURGE /souin-api/souin
Surrogate-Key: cat_p_1, cat_c_3
```

A full flush is `PURGE /souin-api/souin/.+` (every key; `/flush` would close the Redis connection of Souin v1.7.9). Purge errors are logged to `var/log/system.log`,
successful purges to `var/log/debug.log` (`cache_invalidate`).

## Notes

- **Grace period**: Souin serves an expired page during the grace period without asking the backend,
  healthy or not. Varnish keeps 300 s when the backend is healthy and up to 3 days when it is down;
  Souin has one value for both.
- **Storage size**: Otter evicts entries when full, tag entries included; a page whose tags were evicted
  is no longer purged by tag. Size it for your catalog or use Redis.
- **Top menu tags**: every page carries the `cat_c_<id>` tags of the top menu categories, and saving a
  product purges the tags of its categories, so it purges every page with that menu entry. Varnish does
  the same.
- **ESI** is not used: blocks with a `ttl` are rendered in the page.
- Debug cookies (`XDEBUG_SESSION`, SPX) do not bypass the cache; uncomment the line in the generated
  Caddyfile on a development server.

## License

MIT
