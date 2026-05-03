# Changelog

## v0.3.1 — variant catalog sync docs in README

The README that Packagist surfaces (and that every developer reads
first when they `composer require lexis/lexis-php`) had no mention
of how to sync VARIANT catalogs (size × color × ... per product).
v0.3.0 documented how to *search* variants — `groupBy: 'parent_id'`,
`$hit->groupedCount`, sorts that work with grouping — but said
nothing about what shape data has to land in to produce those
variants. Anyone migrating from the dashboard's Excel import to a
direct SDK sync would push raw DB rows, see N docs in / N docs
out, and get one out-of-stock placeholder per parent.

Docs-only fix:

  * Adds a "Syncing variant catalogs" section between "Sync flow
    in detail" and "Search" with: background on `variant_template`
    and engine-side `explode_doc`, the exact blob format
    (`size :: color :: price :: stock | ...`), how to verify the
    template via `curl ... | jq .config.variant_template`, two
    full strategies (PHP builds the blob vs. PHP pre-explodes),
    a comparison table for picking between them, and a "Common
    pitfall" box with a diagnostic snippet for the
    "N docs in / N docs out" bug.

No code changes. v0.3.0 callers keep working without any update —
this release is purely a documentation correctness fix so Packagist
readers see how variant sync actually works end-to-end.

## v0.3.0 — sort, grouping, facets, auto-faceting, boost

Big release that turns `Client::search()` from a flat full-text search
call into the full storefront query surface — everything you need to
build an Amazon-style listing page (sort + group + filter sidebar +
boost + autocomplete) is now first-class on the SDK.

The engine has supported all of this since `lexis-server 0.6.x` (sort,
grouping, facets, boost, auto-faceting all landed in the Rust rewrite),
but the PHP SDK was still a v0.1-era surface that only exposed
`search(index, query, limit, offset, filters, searchAfter)`. v0.3.0
brings the SDK in line.

### Backward compatibility

**Non-breaking.** v0.2.x callers continue to work without changes.
The `search()` method gained a new optional 7th parameter
(`?array $options = null`) — anyone using the existing 6-arg
positional form keeps their behavior unchanged.

The new typed fields on `SearchResult` (`facets`, `facetLabels`,
`autoFilters`, `fallbackMode`) and the new field on `SearchHit`
(`groupedCount`) default to empty / null on responses that don't
carry them, so older engine builds and existing callers see zero
behavior change.

### What's new on the request

`Client::search()` accepts an `$options` associative array as its
7th argument. Every key is optional; defaults match the v0.2.x
behavior.

```php
$result = $lexis->search(
    'products',                          // index slug
    'tricou rosu',                       // query
    20,                                  // limit
    0,                                   // offset
    [                                    // filters (existing)
        ['op' => 'tag_eq', 'field' => 'culoare', 'value' => 'Rosu'],
    ],
    null,                                // searchAfter (existing)
    [                                    // $options (NEW)
        'sort'      => [['field' => 'pret', 'direction' => 'asc']],
        'groupBy'   => 'parent_id',
        'facets'    => ['marca', 'culoare', 'marime'],
        'autoFacet' => true,
        'boost'     => ['field' => 'stoc', 'function' => 'log', 'weight' => 1.0],
        'prefixLast' => false,
        'hybrid'     => false,
    ]
);
```

Each key:

  * **`sort`** — `list<{field, direction}>`. Override BM25 with an
    explicit field sort. Three valid field shapes:
      - **Numeric+sortable field** (`pret`, `stoc`) — typical price
        asc/desc.
      - **Tag field that matches `groupBy`** (`parent_id`) — engine
        parses the group key as f64; use case "newest = parent_id
        desc". Lex sort on tag ords would put "999" > "1000".
      - **Text field with `groupBy` active** (`denumire_produs`) —
        engine reads each group representative's payload and
        lex-sorts case-insensitive. Use case "Name A-Z / Z-A" on a
        variant-collapsed listing.
    Mutually exclusive with `boost` (engine 400). Hybrid bypasses to
    BM25-only when sort is non-empty.

  * **`groupBy`** — `string`. Field collapsing — dedupes hits by the
    value of this field, keeping the best-scored hit per group and
    reporting siblings via `$hit->groupedCount`. Field MUST be `tag`
    kind. `count_estimate` returns UNIQUE GROUPS (not raw variant
    docs). `searchAfter` is rejected when grouping is on.

  * **`facets`** — `list<string>`. Bucket-count over the matching set
    for filter sidebars. Each field must be `tag`-kind. Top-K capped
    at 200 per field. Buckets reflect the FILTERED set; for "uncheck
    me to see other values" UX, re-issue the search WITHOUT that
    field's filter.

  * **`autoFacet`** — `bool` (default false). Engine scans query
    tokens against tag-field values and applies matches as implicit
    `tag_eq` filters. "tricou portocaliu" → `q=tricou +
    culoare:Portocaliu`. Applied filters surface in
    `$result->autoFilters` as pre-checked chips.

  * **`boost`** — `{field, function?, weight?}`. Numeric boost —
    multiplies BM25 by `1 + weight × f(value)` where `f` is `log`
    (default, diminishing returns) or `linear`. Field must be
    `numeric` + `sortable`. Classic use:
    `{field:'stoc', function:'log', weight:1.0}` → in-stock variants
    outrank out-of-stock peers without dominating relevance.

  * **`prefixLast`** — `bool` (default false). Treat the last query
    token as a prefix — autocomplete mode. "adi" matches "adidași".

  * **`hybrid`** — `bool` (default false). BM25 + vector cosine fused
    via Reciprocal Rank Fusion. Requires the index built with
    `vector.enabled = true`; quietly bypasses to BM25 otherwise.

  * **`rerank`**, **`autoCorrect`**, **`fallback`**,
    **`requireAllTokens`** — orchestrator knobs (default `true`).
    Override only when you need to disable a stage for an
    experiment.

### What's new on the response

`SearchResult` exposes four new typed fields:

  * **`$facets`** — `array<string, FacetBucket[]>`. Per-field bucket
    lists in `(count desc, value asc)` order. Empty when no facets
    were requested.

  * **`$facetLabels`** — `array<string, string>`. Maps engine
    identifiers (`tip_de_protectie`) to display names
    (`"Tip de protectie"`). Empty when the index has no label
    registry. Pattern: `$labels[$field] ?? $field`.

  * **`$autoFilters`** — `AppliedFilter[]`. Filters the engine
    applied implicitly via `autoFacet`. Each entry exposes
    `->toTagEqClause()` for promoting to an explicit filter on the
    next request.

  * **`$fallbackMode`** — `?string`. Set when the engine ran a
    fallback path (`'strict'` / `'phonetic'` / `'union'`); null when
    the primary BM25 pass had hits or fallback was disabled.
    Storefronts use it to render a "Showing approximate matches"
    hint.

`SearchHit` exposes one new field:

  * **`$groupedCount`** — `int`. Number of OTHER variants collapsed
    under this hit when `groupBy` was active. Defaults to `0`
    (single-member group OR grouping off). Pattern:
    `"+{$hit->groupedCount} variante"`.

### New typed classes

  * **`Lexis\FacetBucket`** — `{string $value, int $count}`.
  * **`Lexis\AppliedFilter`** — `{string $field, string $value}` plus
    a `toTagEqClause()` helper for round-tripping through `filters`.

### What the engine returns — full wire shape

Reference for storefront authors who want to see exactly what comes
back over HTTP. The PHP SDK decodes this into the typed shape above.

```json
{
  "hits": [
    {
      "id": "5454-rosu-XL",
      "score": 4.2,
      "payload": {
        "id": "5454-rosu-XL",
        "parent_id": "5454",
        "denumire_produs": "Tricou tehnic Renania",
        "pret": 49.0,
        "stoc": 12,
        "marca": "Renania",
        "culoare": "Rosu",
        "marime": "XL",
        "imagine": "https://...",
        "url": "/produse/tricou-tehnic-renania"
      },
      "cursor": "eyJvZmZzZXQiOjksImxhc3RfaWQiOiI1NDU0LXJvc3UtWEwifQ",
      "grouped_count": 4
    }
  ],
  "count_estimate": 59,
  "effective_query": "tricou rosu",
  "suggestion": null,
  "auto_corrected": false,
  "fallback_mode": null,
  "qid": "q_a8f4kx2j",
  "facets": {
    "marca": [
      {"value": "Cofra",   "count": 24},
      {"value": "Renania", "count": 18},
      {"value": "Malfini", "count":  7}
    ],
    "culoare": [
      {"value": "Albastru",   "count": 17},
      {"value": "Negru",      "count": 14},
      {"value": "Rosu",       "count":  9}
    ],
    "marime": [
      {"value": "M",  "count": 22},
      {"value": "L",  "count": 19},
      {"value": "XL", "count": 17}
    ]
  },
  "auto_filters": [
    {"field": "culoare", "value": "Rosu"}
  ],
  "facet_labels": {
    "tip_de_protectie": "Tip de protectie",
    "denumire_produs":  "Denumire produs"
  },
  "diagnostics": {
    "took_ms": 12,
    "primary_hits": 11240,
    "rerank_ms": 3,
    "fallback_ms": 0
  }
}
```

Notes:
  * `count_estimate` is UNIQUE PARENTS when `group_by` is active —
    this listing has 59 distinct products, even if the index stores
    11,240 raw variant docs.
  * `grouped_count = 4` means this card represents 5 variants total
    (representative + 4 siblings).
  * `score` carries the BM25 score in the no-sort case, OR the sort
    value re-packed for sort-mode searches. UI code shouldn't depend
    on it being a relevance signal when `sort` was set.

### Building a storefront filter page — the playbook

Read `examples/storefront-with-filters.php` for a complete
end-to-end script. The recommended pattern:

1. **Read URL state** — `$q`, `$page`, `$sort`, `$selectedFilters`,
   `$searchAfter` from `$_GET`. URL is the single source of truth so
   filters survive refresh / back-button.

2. **Issue ONE search per render** — pass everything in one call.
   The engine returns hits, facets, and auto-filters in a single
   round-trip. Don't issue a second "facets-only" search.

3. **Render the filter sidebar from `$result->facets`** — one
   block per requested facet field. Use `$result->facetLabels` for
   the display name. Each bucket links to a URL that adds/removes
   that `(field, value)` from the active filter set.

4. **Render auto-filter chips from `$result->autoFilters`** —
   pre-checked, with an "x" link that re-runs the search WITHOUT
   that auto-filter (promote → explicit `tag_eq` then remove).

5. **Render product cards from `$result->hits`** — show
   `$hit->document['imagine']`, `$hit->document['denumire_produs']`,
   `$hit->document['pret']`. If `$hit->groupedCount > 0`, show
   "+{$hit->groupedCount} variante" under the price.

6. **Pagination** — use `$result->total / $limit` for page count,
   stamp `&page=N` on the URL, pass `offset = ($page-1) * $limit`
   to the next call. (Don't use `searchAfter` when `groupBy` is
   active — the engine rejects it.)

7. **Stamp `?lexis_qid=...` on every product link** —
   `$lexis->withQid($url, $result->qid)` — so click attribution
   fires when the visitor clicks through.

### Index requirements

For all the new features to work, the index `mappings` must declare
the right field kinds. The dashboard's "Settings → Index schema"
page does this; if you build the schema by hand, the relevant bits
are:

```json
{
  "mappings": [
    { "name": "denumire_produs", "kind": "TextAndTag", "facetable": true },
    { "name": "pret",            "kind": "Numeric", "sortable": true },
    { "name": "stoc",            "kind": "Numeric", "sortable": true },
    { "name": "parent_id",       "kind": "Tag" },
    { "name": "marca",           "kind": "Tag", "facetable": true },
    { "name": "culoare",         "kind": "Tag", "facetable": true },
    { "name": "marime",          "kind": "Tag", "facetable": true }
  ]
}
```

Field kind / option to feature mapping:

| Feature              | Required mapping kind          | Required flags |
|----------------------|--------------------------------|----------------|
| `sort` (numeric)     | `Numeric`                      | `sortable: true` |
| `sort` (parent_id)   | `Tag` matching `groupBy`       | —              |
| `sort` (text/name)   | `Text` or `TextAndTag` + `groupBy` set | — |
| `groupBy`            | `Tag`                          | —              |
| `facets`             | `Tag`                          | (`facetable: true` is informational) |
| `autoFacet`          | `Tag` (any tag field qualifies)| —              |
| `boost`              | `Numeric`                      | `sortable: true` |
| filter `tag_eq` / `tag_in`     | `Tag` or `TextAndTag` | — |
| filter `numeric_range` | `Numeric`                    | — |

### Tests

41/41 pass. 8 new tests cover:

  * options forwarded as the right wire keys (camelCase →
    snake_case),
  * options omitted when caller doesn't set them (backward compat),
  * `grouped_count` decoded on each hit (with default 0 fallback),
  * facets + facet_labels round-tripped to typed structures,
  * auto_filters round-tripped + `toTagEqClause()` helper,
  * `fallback_mode` exposed (and null by default),
  * empty / blank options handled correctly (empty string `groupBy`
    not sent, empty facets/sort lists ARE sent).

### Migration

Nothing to migrate — purely additive. To start using the new
features, just pass an `$options` array on the calls that need them.
v0.2.x callers using the legacy 6-arg positional form keep working
without any change.

```php
// v0.2.x — still works in v0.3.0
$result = $lexis->search('products', 'adidași', 20, 0);

// v0.3.0 — new features via the options array
$result = $lexis->search('products', 'adidași', 20, 0, null, null, [
    'groupBy' => 'parent_id',
    'sort'    => [['field' => 'pret', 'direction' => 'asc']],
    'facets'  => ['marca'],
]);
```

## v0.2.1 — page-view tracking docs in README

The README that Packagist surfaces (and that every developer reads
first when they `composer require lexis/lexis-php`) had no mention
of page-view tracking — the feature shipped in v0.2.0, but only the
docs site at lexis.software/docs/sdk/php documented it. Anyone
following the README alone would never know `recordView()`,
`detectSource()`, or the `/analytics/journeys` dashboard surface
existed.

Docs-only fix:

  * Adds a "Page-view tracking" section between "Click attribution"
    and "Error handling" with: a one-call quickstart, copy-paste
    examples for product / category / search / homepage templates,
    the auto-detect rules for `source`, three end-to-end journey
    storyboards (Google→product, Google→search→click→product,
    internal browsing), privacy notes, framework integration
    sketches (Laravel / Symfony / WordPress), and the best-effort
    try/catch pattern.

No code changes. v0.2.0 callers keep working without any update —
this release is purely a documentation correctness fix so Packagist
readers see the feature exists.

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
