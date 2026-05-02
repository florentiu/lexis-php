# Changelog

## v0.2.0 — page-view tracking

Adds generic page-view tracking — fires once per product / category /
search-results / home page hit on the storefront, regardless of how
the visitor got there. Powers the upcoming `/analytics/journeys` view
in the dashboard (per-product entry sources, top referring domains,
search-to-view funnel). Distinct from `recordClick()`, which only
fires when the visit originated from a Lexis search result.

  * **`Client::recordView(pageType, source, productId?, categorySlug?, referrer?, landingUrl?, qid?)`** —
    minimal positional API matching the existing `recordClick`
    style. Throws `LexisException` on hard failures; wrap in
    try/catch if analytics noise must not break the page render.
  * **`Client::detectSource($referrer, $currentHost)`** — heuristic
    classifier that maps `HTTP_REFERER` to one of `direct` /
    `search` / `category` / `external` / `referral`. Most
    storefronts can adopt the defaults; bespoke URL conventions
    can pass `source` to `recordView()` explicitly.
  * **`Client::extractReferrerHost($referrer)`** — strict host
    extractor mirroring the engine's `extract_referrer_host`. The
    SDK strips full URLs to just the host BEFORE sending so PII in
    the referrer query string (utm_*, partner ids, email markers)
    never crosses the network.

Engine-side this corresponds to lexis-server `0.5.0` — adds the
`POST /api/v1/view` endpoint, the `CF_VIEWS` column family, and the
`GET /v1/admin/orgs/:org/views` admin reader.

### Migration

Purely additive — no breaking changes. v0.1.3 callers keep working
unchanged. To start collecting page-view data, add one call per
page template:

```php
$referrer = $_SERVER['HTTP_REFERER'] ?? null;
$lexis->recordView(
    pageType: 'product',
    source: \Lexis\Client::detectSource($referrer, $_SERVER['HTTP_HOST'] ?? null),
    productId: $product->id,
    referrer: $referrer,
    landingUrl: $_SERVER['REQUEST_URI'] ?? null,
    qid: $_GET[\Lexis\Client::ATTRIBUTION_PARAM] ?? null,
);
```

See `/sdk/php` on the docs site for the full integration guide.

### Tests

33/33 pass with four new tests covering the recordView wire shape,
referrer-host privacy stripping, source auto-detection across all
five buckets, and host extraction edge cases (mixed case, ports,
bare hosts, garbage input).

## v0.1.3 — document filtering

The `filters` parameter on `Client::search()` has been functional
end-to-end since v0.1.0 (the engine implements `tag_eq`, `tag_in`, and
`numeric_range` server-side), but the PHPDoc and README still claimed
the field was "logged but not yet applied" — a fossil from the
PHP/Redis era of Lexis. This release fixes the docs only; no
behavioral changes, no new code, no breaking changes:

  * **PHPDoc on `Client::search()`** — describes all three filter
    operator shapes (`tag_eq`, `tag_in`, `numeric_range`), shows a
    multi-clause AND example, and notes the index-side prerequisite
    (fields must be declared as `tagFields` / `numericFields`).
  * **`@param` typed as `list<array<string, mixed>>|null`** — was
    `array<string, mixed>|null`, which suggested an associative array
    instead of a list of clause objects.
  * **Docs site** — new "Filtering" section on
    `lexis.software/docs/sdk/php` (RO + EN) between the auto-correct
    section and the deep-pagination loop, with the same three-operator
    walkthrough plus a pointer to the Search Logs detail sheet for
    debugging.

Existing v0.1.2 callers that already passed filter clauses will keep
working without changes — this release is purely a docs
correctness fix.

## v0.1.2 — wire-format alignment + deep pagination

**BREAKING.** Aligns the SDK with the engine's actual `/api/v1/search`
response shape. v0.1.1 was authored against an older / aspirational
wire format and would silently parse zeros — every hit had `id=""`,
`score=0`, and the document payload was unreachable. Anyone who
installed v0.1.1 should upgrade.

### Breaking changes

- **`SearchHit`**
  - Reads `id`, `score`, `payload` (engine's actual fields). Was
    reading `_id`, `_pk`, `_score` and mixing payload at the root.
  - **Removed** `$primaryKey` — the engine's `id` *is* the primary
    key. Read `$hit->id`.
  - **Added** `$cursor` (`?string`) — opaque `search_after` boundary
    token, populated only on hits that can resume pagination.
  - `$document` now holds the contents of the engine's `payload`
    object, with no synthetic `_id`/`_pk`/`_score` to strip.

- **`SearchResult`**
  - Reads `count_estimate` (mapped to `$total`), `effective_query`
    (mapped to `$query`), and `diagnostics.took_ms` (mapped to
    `$tookMs`). The previous top-level `total` / `query` / `took_ms`
    fields don't exist in the engine response.
  - **Removed** `$limit`, `$offset`, `$expandedTerms` — none of these
    are echoed by the engine. The caller already knows `$limit` /
    `$offset` from the request; expanded terms are an internal
    concern, not a wire field.
  - **Added** `$autoCorrected` (`bool`) — true when the engine
    re-ran the search with a corrected variant. Read `$query` for
    the variant that actually ran.
  - **Added** `$nextCursor` (`?string`) — convenience equal to the
    `cursor` of the last hit, or `null` when the page is the last.

- **`Client::search()`**
  - **Added** `?string $searchAfter = null` as the 6th parameter.
    When set, sent as `search_after` in the request body; engine
    ignores `offset` in that case.

### Migration

```php
// v0.1.1 — would silently return zeros
foreach ($result->hits as $hit) {
    echo $hit->primaryKey;     // ❌ removed; use $hit->id
    echo $result->expandedTerms;  // ❌ removed
}

// v0.1.2
foreach ($result->hits as $hit) {
    echo $hit->id;
    echo $hit->score;
    echo $hit->get('title');
}
echo $result->total;          // count_estimate from engine
echo $result->query;           // effective_query from engine
echo $result->autoCorrected;   // new
echo $result->nextCursor;      // new — for deep pagination
```

### Deep pagination

```php
$cursor = null;
do {
    $r = $lexis->search('products', '*', 100, 0, null, $cursor);
    foreach ($r->hits as $h) { /* ... */ }
    $cursor = $r->nextCursor;
} while ($cursor !== null);
```

`search_after` is O(page) regardless of depth; `offset` is O(offset+limit).
Use the cursor past ~1000 results.

## v0.1.1 — click attribution + session tracking

- Added `Client::recordClick()` for server-side click attribution.
- Added `Client::withQid()` to stamp `?lexis_qid=...` onto result links.
- Added `Client::getClickAttribution()` for the rolled-up CTR report.
- Added `Client::setSessionId()` / `getSessionId()` — forwards
  `X-Lexis-Session-Id` header for distinct-visitor analytics.

## v0.1.0 — initial release

- Sync (`start` / `push` / `commit` / `abort`) against `/api/v1/sync`.
- Search against `/api/v1/search`.
- Typed exceptions for 400/401/402/404/409/429/5xx.
- Automatic retries with `Retry-After` honoring + exponential backoff.
