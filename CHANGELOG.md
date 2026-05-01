# Changelog

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
