# Lexis PHP SDK

Official PHP client for the [Lexis](https://lexis.software) search API —
sync your catalog, query the index, done. Zero runtime dependencies beyond
`ext-curl` + `ext-json`.

## Requirements

- PHP 7.4 or 8.x (all minors supported)
- `ext-curl`, `ext-json`
- A Lexis API key (Settings → API keys in the dashboard)

> Examples below are written with positional arguments so they paste-run on
> every supported PHP version. On PHP 8.0+ you're free to use named arguments
> (`$client->search(index: 'products', query: 'x')`) — the method signatures
> match.

## Install

```bash
composer require lexis/lexis-php
```

## Quickstart

```php
<?php
require 'vendor/autoload.php';

use Lexis\Client;

// Managed cloud (default)
$lexis = new Client(getenv('LEXIS_API_KEY'));

// OR — enterprise install, point at your own dashboard URL:
// $lexis = new Client(getenv('LEXIS_API_KEY'), 'https://search.my-company.internal');

// 1. Push your catalog
//    sync->start(indexSlug, indexName, primaryKey, source)
$run = $lexis->sync->start('products', 'Products');

$run->push([
    ['id' => 'sku-1', 'title' => 'Adidași Nike Air', 'price' => 349, 'brand' => 'Nike'],
    ['id' => 'sku-2', 'title' => 'Adidași Puma RS',  'price' => 299, 'brand' => 'Puma'],
    // ... up to millions of docs; SDK chunks into 1000-doc batches
]);

$stats = $run->commit();
echo "Committed {$stats['documents']} docs (deleted {$stats['deleted']})\n";

// 2. Search — (index, query, limit?, offset?, filters?)
$result = $lexis->search('products', 'adidasi');

foreach ($result->hits as $hit) {
    printf("- %s (%.2f) — %s\n",
        $hit->id,
        $hit->score,
        $hit->get('title'),
    );
}
```

## Enterprise / enterprise deployments

For installs on your own infrastructure, pass the dashboard URL as the
second argument — that's the only change needed. Auth header, request /
response shapes, retries, error codes: all identical across cloud and
enterprise.

```php
use Lexis\Client;

$lexis = new Client(
    getenv('LEXIS_API_KEY'),              // key from *your* dashboard
    'https://search.my-company.internal', // *your* base URL, no trailing slash
);
```

Pin the URL and key in your app config (`.env`, Laravel config, Symfony
parameters — whatever fits) so each environment points at the right
dashboard: a staging SDK instance talks to the staging Lexis install, prod
to prod.

```php
// .env
LEXIS_API_KEY=lexis_live_...
LEXIS_BASE_URL=https://search.my-company.internal

// app code
$lexis = new Client(
    getenv('LEXIS_API_KEY'),
    getenv('LEXIS_BASE_URL') ?: null,   // null falls back to managed cloud
);
```

Need more than just the URL (custom timeout, retries, user agent, injected
HTTP transport)? Build a `Config` instead — see [Configuration
reference](#configuration-reference) below.

## Sync flow in detail

### Full-replace semantics

A sync run is atomic: whatever you push between `start()` and `commit()`
becomes the entire index content. Documents that were in the index before
but aren't in this run are deleted. There's no incremental upsert — if you
want to add one product to a catalog of 100k, you still push all 100k in a
new run.

### Batching

The API caps each `/documents` call at 1000 documents. The SDK handles this
for you: pass as many as you want to `push()`, they're chunked into 1000-doc
batches and POSTed sequentially. One failed chunk aborts the whole thing
with an exception — you can then call `$run->abort()` if you want to mark
the run cleanly (otherwise it auto-expires server-side in ~15 minutes).

```php
// Streaming from a large catalog
$run = $lexis->sync->start('products');
foreach (fetchProductsFromDb() as $page) {  // $page = array of up to N docs
    $run->push($page);
}
$run->commit();
```

### Aborting

Call `abort()` explicitly if your source query fails mid-sync and you don't
want to wait 15 minutes for the run to expire:

```php
try {
    $run = $lexis->sync->start('products');
    foreach ($source as $batch) {
        $run->push($batch);
    }
    $run->commit();
} catch (\Throwable $e) {
    $run->abort('source query failed: ' . $e->getMessage());
    throw $e;
}
```

### Custom primary key

By default each document must have an `id` field. Override with the third
argument on the first `start()` call — it's locked at index creation and
ignored thereafter.

```php
// start(indexSlug, indexName, primaryKey, source)
$run = $lexis->sync->start('articles', 'Articles', 'slug');
```

## Search

```php
// search(index, query, limit, offset, filters)
$result = $lexis->search('products', 'adidași nike', 20, 0);
```

Each hit carries the original document fields plus three synthetic ones —
`id` (the primary-key value), `primaryKey`, and `score`:

```php
foreach ($result->hits as $hit) {
    $hit->id;                      // "sku-1"
    $hit->primaryKey;              // "sku-1" (same; exposed for clarity)
    $hit->score;                   // 4.2
    $hit->get('title');            // "Adidași Nike Air"
    $hit->get('price', 0);         // 349 (with default if missing)
    $hit->document;                // full associative array, clean of _ prefixes
}

$result->total;                    // total matches across all pages
$result->tookMs;                   // server-side query time
$result->expandedTerms;            // ["adidas", "nike"] — stemmed/synonym-expanded
$result->suggestion;               // "adidași" when the engine has a did-you-mean
$result->qid;                      // "q_a8f4kx2j" — per-search id; '' on older engines
```

## Click attribution

Click attribution measures which search results actually got clicked,
so the engine can compute CTR, top-by-CTR, and zero-click rollups for
the dashboard's `/analytics` page. **It's strictly server-side** — no
JavaScript ships to the browser, you don't need to inject a tracking
pixel, and the customer's storefront keeps full control of what's sent.

The flow is three touch points wired through this SDK:

```
┌────────────┐  search()         ┌──────────────┐
│ /search    │ ────────────────► │   Lexis      │
│ controller │                   │   engine     │
│            │ ◄──────────────── │              │
└────────────┘  hits + qid       └──────────────┘
       │
       │ withQid($url, $qid)  ← stamps ?lexis_qid onto links
       ▼
   <a href="…?lexis_qid=q_a8f4kx2j">…</a>

──── customer's browser navigates to a product page ────

┌────────────┐  recordClick()    ┌──────────────┐
│ /product   │ ────────────────► │   Lexis      │
│ controller │                   │   engine     │
└────────────┘                   └──────────────┘
```

### 1. Stamp `qid` onto result links

```php
$result = $lexis->search('products', 'adidasi');
foreach ($result->hits as $i => $hit) {
    $rawUrl = "https://shop.example.ro/p/{$hit->id}";
    $href = $lexis->withQid($rawUrl, $result->qid)
        . '&lexis_pos=' . ($i + 1);   // optional rank, lights up position-bias analytics
    // …render <a href="$href">…</a>
}
```

### 2. Record the click on the product page

In your product-page controller, post a beacon when the request
carries `?lexis_qid=…`. Best-effort: wrap in try/catch so analytics
noise never breaks the page render.

```php
$qid = $_GET[\Lexis\Client::ATTRIBUTION_PARAM] ?? null;
if (is_string($qid) && $qid !== '') {
    try {
        $lexis->recordClick(
            'products',
            $qid,
            $product->id,
            isset($_GET['lexis_pos']) ? (int) $_GET['lexis_pos'] : null,
            $_SERVER['REQUEST_URI'] ?? null,
        );
    } catch (\Lexis\Exception\LexisException $e) {
        error_log('lexis click telemetry: ' . $e->getMessage());
    }
}
```

### 3. Pull the rollup from your admin

```php
$rep = $lexis->getClickAttribution($orgId, 'products');

$rep->kpi['clicks'];          // 1234
$rep->kpi['searches'];        // 4500
$rep->kpi['ctr'];             // 0.274 (clicks/searches)
$rep->kpi['zeroClickCount'];  // 12

foreach ($rep->topByCtr as $row) {
    // ['query' => 'shoes', 'clicks' => 42, 'ctr' => 0.6, 'topProduct' => 'sku-A']
}
foreach ($rep->zeroClickQueries as $row) {
    // ['query' => 'socks', 'searches' => 9, 'lastSeen' => '2026-04-30T10:00:00Z']
}
```

A complete end-to-end script (search → result links → product page →
rollup) lives at `examples/storefront-with-click-attribution.php`.

## Error handling

All SDK exceptions extend `\Lexis\Exception\LexisException`. Catch that if
you want a single net, or one of the specifics for fine-grained recovery:

| Exception                  | HTTP | Retryable by SDK |
|----------------------------|------|------------------|
| `ValidationException`      | 400  | no               |
| `AuthenticationException`  | 401  | no               |
| `PlanLimitException`       | 402  | no               |
| `NotFoundException`        | 404  | no               |
| `ConflictException`        | 409  | no               |
| `RateLimitException`       | 429  | yes (auto)       |
| `ServerException`          | 5xx  | yes (auto)       |
| `NetworkException`         | —    | yes (auto)       |

Retries are automatic on 429, 5xx, and transport errors — the SDK respects
`Retry-After` on 429 and falls back to exponential backoff (0.5s → 1s → 2s
→ …) otherwise. You only see the exception if the budget is exhausted.

```php
use Lexis\Exception\AuthenticationException;
use Lexis\Exception\LexisException;
use Lexis\Exception\PlanLimitException;

try {
    $lexis->search('products', 'shoes');
} catch (AuthenticationException $e) {
    // Rotate the key.
} catch (PlanLimitException $e) {
    // Upgrade or free some headroom.
} catch (LexisException $e) {
    // Log $e->getMessage(), $e->getStatusCode(), $e->getResponseBody().
}
```

## Configuration reference

Config is a plain constructor — the positional order is:
`apiKey, baseUrl, timeout, maxRetries, retryBaseDelay, transport, userAgent`.

```php
use Lexis\Client;
use Lexis\Config;

// PHP 7.4-compatible (positional):
$lexis = new Client(new Config(
    'lexis_live_...',            // apiKey
    'https://lexis.software', // baseUrl (default — change for enterprise)
    30.0,                         // timeout (s)
    3,                            // maxRetries on 429/5xx/network; 0 disables
    0.5,                          // retryBaseDelay (s) — doubles each attempt
    null,                         // transport — null = built-in cURL
    'my-app/1.0'                  // userAgent
));

// PHP 8.0+ (named args, same thing):
// $lexis = new Client(new Config(
//     apiKey: 'lexis_live_...',
//     timeout: 60.0,
//     maxRetries: 5,
// ));
```

### Custom HTTP transport

Inject anything implementing `\Lexis\Http\Transport` — useful for testing
(pass a fake) or for corporate proxies that need Guzzle/PSR-18:

```php
$lexis = new Client(new Config(
    'lexis_live_...',
    Config::DEFAULT_BASE_URL,
    30.0,
    3,
    0.5,
    new MyGuzzleAdapter()
));
```

The default is `\Lexis\Http\CurlTransport` — pure `ext-curl`, no extra
dependencies.

## Rate limits (server-side)

- `/search`: 600 requests/minute per API key
- `/sync/*`: 30 write requests/minute per API key

Plan quotas (documents, indexes, monthly search calls) surface as
`PlanLimitException`. Check your dashboard for the current numbers.

## Testing

```bash
composer install
composer test
```

## License

MIT — see [LICENSE](LICENSE).
