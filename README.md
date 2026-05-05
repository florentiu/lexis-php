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

## Syncing variant catalogs

Variant catalogs (one product line, many size × color × ... combinations)
need a deliberate decision **before** you start syncing from PHP: do you
want the engine to explode parent rows into variant rows on the way in,
or do you want to pre-explode in PHP and skip the explosion path?

The two strategies have the same `groupBy: 'parent_id'` search behavior
but very different sync code on your side. Pick one and stick with it
per index — switching mid-stream means re-syncing the whole catalog.

### Background: how variant explosion works

Some indexes have a `variant_template` configured (visible in the
dashboard at **Settings → Variants** or in the index config). When set,
every document the engine receives via `/api/v1/sync/*/documents` runs
through `explode_doc(parent, template)` server-side: the engine reads a
named blob column on the doc, parses it, and emits one document per
variant.

A typical fashion / apparel template (size × color × price × stock):

```
source_column      = "combinatii_marime-culoare-pret-stoc"
parent_id_field    = "parent_id"
size_field         = "marime"
color_field        = "culoare"
price_field        = "pret"
stock_field        = "stoc"
```

Engine behavior on each incoming doc:

  * **`source_column` populated with a parseable blob** → emits N
    variant docs, one per segment, with PK `<parent_pk>__<idx>`.
  * **`source_column` missing or empty** → emits one placeholder doc
    with `stoc=0` so the parent still surfaces in search. This is
    what bites people who push raw DB rows: 11k rows in, 11k
    placeholders out — no explosion, every product looks
    out-of-stock.

You can verify what your index expects by reading its config:

```bash
curl -sS -H "Authorization: Bearer $LEXIS_API_KEY" \
  "https://lexis.software/v1/admin/orgs/{ORG_ID}/indexes/{INDEX_SLUG}" \
  | jq '.config.variant_template'
```

If the response is `null`, your index has no template — you're on
**Strategy B** territory and the engine will index whatever you push
verbatim. If you see a template, you're on **Strategy A** and need to
respect the blob shape, OR remove the template (Settings → Variants →
disable) and migrate to Strategy B.

### The blob format

When `variant_template` is configured, each parent document must carry
a string under `source_column` shaped like:

```
size :: color :: price :: stock | size :: color :: price :: stock | ...
```

  * **Pipe (`|`)** separates variants.
  * **Double-colon (`::`)** separates the four slots inside one
    variant. (A legacy ` - ` separator is also accepted for older
    spreadsheets — pick one and stick with it.)
  * **Slot order is positional**: `size :: color :: price :: stock`.
    Always 4 slots. A missing field stays as a literal `null`
    placeholder, NOT empty:

```
XL :: Rosu :: 49 RON :: 12 | XL :: Albastru :: 49 RON :: 8 | M :: null :: 49 RON :: 5
```

  * **Price slot** accepts any of `RON`, `EUR`, `USD`, `GBP`, `MDL`,
    `BGN` (configurable per template via `price_labels`).
  * **Stock slot** is an integer; `0` means out-of-stock. A
    non-integer becomes `0`.
  * **Whitespace around the separators is tolerated** — both
    `XL::Rosu::49 RON::12` and `XL :: Rosu :: 49 RON :: 12` parse
    identically.

### Strategy A — PHP builds the variant blob

Use this when your index already has `variant_template` configured
(typical: you've been importing via the dashboard's Excel uploader and
want to keep that schema). Your PHP script reads per-variant rows from
the DB, groups them by parent, and assembles the blob before pushing.

```php
$run = $lexis->sync->start('products');

// Source: per-variant rows ordered by parent_id so we can stream-group
// without holding the whole catalog in memory.
$stmt = $pdo->query("
    SELECT parent_id, denumire_produs, marca, descriere, imagine, url,
           marime, culoare, pret, stoc
    FROM produse
    ORDER BY parent_id
");

$current = null;
$variantParts = [];
$batch = [];

$flushParent = function () use (&$current, &$variantParts, &$batch, $run): void {
    if ($current === null) return;

    // Build the engine-expected blob: size :: color :: price :: stock,
    // pipe-joined across variants. Use 'null' (literal string) for
    // missing size/color so the engine's positional parser keeps the
    // 4-slot layout.
    $blob = implode(' | ', array_map(static function (array $v): string {
        $size  = $v['marime']  !== null && $v['marime']  !== '' ? $v['marime']  : 'null';
        $color = $v['culoare'] !== null && $v['culoare'] !== '' ? $v['culoare'] : 'null';
        $price = $v['pret']    !== null ? sprintf('%.2f RON', (float) $v['pret']) : 'null';
        $stock = $v['stoc']    !== null ? (int) $v['stoc'] : 0;
        return "{$size} :: {$color} :: {$price} :: {$stock}";
    }, $variantParts));

    $batch[] = [
        'id'                                  => (string) $current['parent_id'],
        'parent_id'                           => (string) $current['parent_id'],
        'denumire_produs'                     => $current['denumire_produs'],
        'marca'                               => $current['marca'],
        'descriere'                           => $current['descriere'],
        'imagine'                             => $current['imagine'],
        'url'                                 => $current['url'],
        // The blob — column name must match `source_column` from the
        // index's variant_template exactly.
        'combinatii_marime-culoare-pret-stoc' => $blob,
    ];

    if (count($batch) >= 500) {
        $run->push($batch);
        $batch = [];
    }
};

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($current !== null && $current['parent_id'] !== $row['parent_id']) {
        $flushParent();
        $variantParts = [];
    }
    $current = $row;
    $variantParts[] = $row;
}
$flushParent();
if ($batch !== []) {
    $run->push($batch);
}

$stats = $run->commit();
echo "OK: {$stats['documents']} variants indexed\n";
```

**Result on the engine side**: each parent row explodes into N variant
rows; the index ends up holding the variant total. With 100 parent
products averaging 110 variants you'd push 100 docs and the engine
indexes ~11,000.

### Strategy B — PHP pushes pre-exploded variant rows

Use this when you want to keep PHP simple (no blob assembly) and own
the variant shape end-to-end. Requires the index to have **NO**
`variant_template` configured — disable it via Settings → Variants in
the dashboard, or recreate the index without one.

Each row you push is one variant. The dashboard's variant template is
off, so the engine indexes verbatim. You give each variant a unique PK
(typically `parent_id__variant_idx`) and copy the parent fields onto
every row so the variant is independently searchable.

```php
$run = $lexis->sync->start('products');

$batch = [];
foreach (fetchVariantsFromDb() as $row) {
    $batch[] = [
        // Unique per-variant PK — guarantees idempotent re-syncs and
        // gives groupBy something distinct to collapse.
        'id'              => "{$row['parent_id']}__{$row['variant_idx']}",
        // The grouping key — this becomes `groupBy: 'parent_id'` at
        // search time so the storefront sees one card per product.
        'parent_id'       => (string) $row['parent_id'],
        // Parent fields copied onto every variant row.
        'denumire_produs' => $row['denumire_produs'],
        'marca'           => $row['marca'],
        'descriere'       => $row['descriere'],
        'imagine'         => $row['imagine'],
        'url'             => $row['url'],
        // Per-variant fields — directly indexable, filterable.
        'marime'          => $row['marime'],
        'culoare'         => $row['culoare'],
        'pret'            => (float) $row['pret'],
        'stoc'            => (int) $row['stoc'],
    ];
    if (count($batch) >= 500) {
        $run->push($batch);
        $batch = [];
    }
}
if ($batch !== []) {
    $run->push($batch);
}

$stats = $run->commit();
echo "OK: {$stats['documents']} variants indexed\n";
```

**Result**: 11k DB rows in → 11k engine docs. Search returns one card
per `parent_id` thanks to `groupBy: 'parent_id'`. Index is bigger
than Strategy A because parent fields are duplicated on every variant
— for typical e-com catalogs (under 1M variants) this is irrelevant,
disk is cheap.

### Choosing between the two

| Aspect                               | Strategy A — engine explodes | Strategy B — PHP pre-explodes |
|--------------------------------------|-------------------------------|--------------------------------|
| `variant_template` on the index      | Required                      | Must NOT be set                |
| PHP code complexity                  | Higher (group by parent + blob assembly) | Lower (1 row in DB = 1 row out) |
| Bandwidth on `push()`                | Lower (sends 100 parent rows) | Higher (sends 11k rows)        |
| Index disk size                      | Smaller (engine deduplicates parent fields internally) | Larger (parent fields copied per variant) |
| Source-of-truth coupling             | Blob format is engine-defined (legacy / new separator, price labels) | You own the schema entirely |
| Migration effort                     | Match what the dashboard's Excel import already produces | Set up the schema once, push raw DB rows forever |
| Search behavior                      | Same — `groupBy: 'parent_id'` collapses on both | Same |

**Rule of thumb**: if your data already lives one-row-per-variant in
the DB (typical), pick **Strategy B** — it removes a moving piece.
Pick **Strategy A** only if the dashboard's Excel-driven import is
already in production and you're swapping it for a script that has to
produce identical data on the engine side without touching the index
config.

### Common pitfall: "I push N docs and N docs land"

The single most common bug when migrating from dashboard imports to
SDK pushes:

  * Index has `variant_template` configured (from earlier dashboard
    use).
  * PHP script sends per-variant rows from the DB without the blob
    column, OR sends parent rows without the blob.
  * Engine sees no `source_column` populated → emits ONE placeholder
    per row with `stoc=0` → product count looks roughly right but
    every variant collapses to "out of stock" and search relevance
    is broken because there's no per-variant size/color/price.

Diagnostic:

```php
$result = $lexis->search('products', 'shirt', 1, 0, null, null, [
    'groupBy' => 'parent_id',
]);
foreach ($result->hits as $hit) {
    var_dump($hit->document['marime']);  // null = explosion didn't fire
    var_dump($hit->document['stoc']);    // 0 = placeholder, not real variant
    echo "Total = {$result->total}\n";   // unique parents
}
```

Fix: either add the blob column (Strategy A) or disable
`variant_template` on the index (Strategy B).

## Search

```php
// search(index, query, limit, offset, filters, searchAfter, options)
$result = $lexis->search('products', 'adidași nike', 20, 0);
```

Each hit carries the original document fields plus three synthetic ones —
`id` (the primary-key value), `score`, and (when `groupBy` is on)
`groupedCount`:

```php
foreach ($result->hits as $hit) {
    $hit->id;                      // "sku-1"
    $hit->score;                   // 4.2
    $hit->get('title');            // "Adidași Nike Air"
    $hit->get('price', 0);         // 349 (with default if missing)
    $hit->document;                // full associative array
    $hit->groupedCount;            // 0, or N siblings when groupBy is on
}

$result->total;                    // total matches across all pages (or unique groups)
$result->tookMs;                   // server-side query time
$result->query;                    // engine-normalised effective query
$result->autoCorrected;            // bool: engine retried with a corrected query
$result->suggestion;               // "adidași" did-you-mean (or null)
$result->fallbackMode;             // 'phonetic' / 'union' / 'strict' / null
$result->qid;                      // "q_a8f4kx2j" — per-search id
$result->facets;                   // array<string, FacetBucket[]>
$result->facetLabels;              // array<string, string>
$result->autoFilters;              // AppliedFilter[]
$result->nextCursor;               // search_after token for the next page
```

## Sort, grouping, facets, boost — `$options`

The 7th argument to `search()` is an associative array of advanced
features. Every key is optional; defaults match plain BM25 relevance.

```php
$result = $lexis->search(
    'products',
    'tricou rosu',
    20,                                    // limit
    0,                                     // offset
    [['op' => 'tag_eq', 'field' => 'culoare', 'value' => 'Rosu']],  // filters
    null,                                  // searchAfter
    [                                      // options
        'sort'      => [['field' => 'pret', 'direction' => 'asc']],
        'groupBy'   => 'parent_id',
        'facets'    => ['marca', 'culoare', 'marime'],
        'autoFacet' => true,
        'boost'     => ['field' => 'stoc', 'function' => 'log', 'weight' => 1.0],
    ]
);
```

### Sort

`sort` overrides BM25 relevance with an explicit field sort. Three
valid field shapes:

| Use case            | Field kind                      | Example |
|---------------------|---------------------------------|---------|
| Price asc/desc      | `Numeric` + `sortable: true`    | `[{"field":"pret","direction":"asc"}]` |
| Newest first        | `Tag` field that matches `groupBy` (typically `parent_id`) | `[{"field":"parent_id","direction":"desc"}]` |
| Name A-Z / Z-A      | `Text` / `TextAndTag` + `groupBy` set | `[{"field":"denumire_produs","direction":"asc"}]` |

For "newest", the engine parses the group key as a number, so `1000` >
`999` (lex sort would rank them backwards). For text-field sort, the
engine reads each group representative's payload and lex-sorts
case-insensitive.

```php
// Price ascending
'sort' => [['field' => 'pret', 'direction' => 'asc']],

// Newest first (assumes parent_id is incrementing)
'sort' => [['field' => 'parent_id', 'direction' => 'desc']],
'groupBy' => 'parent_id',

// Alphabetic by product name
'sort' => [['field' => 'denumire_produs', 'direction' => 'asc']],
'groupBy' => 'parent_id',
```

`sort` is mutually exclusive with `boost` (the engine returns 400 if
you set both). The engine also bypasses hybrid (vector) when sort is
set.

### Variant grouping (`groupBy`)

Catalogs that index one document per `size × color` should pass
`groupBy: 'parent_id'` (or whatever your variant grouping field is
called). The engine collapses all variants of the same parent into one
hit, picks the best-scoring variant as the representative, and reports
how many siblings were collapsed:

```php
'groupBy' => 'parent_id',

// In the template:
foreach ($result->hits as $hit) {
    echo $hit->get('denumire_produs');
    if ($hit->groupedCount > 0) {
        echo " · " . ($hit->groupedCount + 1) . " variante";
    }
}
```

`$result->total` reports the count of **unique parents**, not raw
variants — the right number for "59 produse" UX.

Two constraints to keep in mind:

  * The `groupBy` field MUST be declared as `kind: "Tag"` in the
    index schema. Text fields don't have the dictionary fast column
    the engine reads at collapse time.
  * `searchAfter` is rejected when `groupBy` is on — use `offset`
    pagination instead. (This rules out cursor-based deep walks
    over a grouped catalog; in practice variant catalogs are small
    enough by parent count that offset works fine.)

### Facets — building the filter sidebar

Pass `facets: ['marca', 'culoare', ...]` and the engine returns
bucket counts per field, capped at 200 buckets per field, sorted
`(count desc, value asc)`:

```php
'facets' => ['marca', 'culoare', 'marime'],
```

```php
foreach ($result->facets as $field => $buckets) {
    $label = $result->facetLabels[$field] ?? $field;
    echo "<h4>{$label}</h4>";
    foreach ($buckets as $bucket) {
        $href = buildFilterHref($field, $bucket->value);
        echo "<a href=\"{$href}\">{$bucket->value} ({$bucket->count})</a>";
    }
}
```

Notes:

  * Each facet field must be declared `kind: "Tag"`. Numeric fields
    don't work as facets in the MVP — for price, build your own
    range sliders and send `numeric_range` filters.
  * Buckets reflect the FILTERED set: a "Marcă" facet rendered next
    to a `culoare:Rosu` filter shows only brands that have at least
    one red variant. For "uncheck me to see other values" UX,
    re-issue the search WITHOUT that field's filter when rendering
    its facet column. (Yes, that's a second search per facet column;
    in practice you only need it if your operators report missing
    values.)
  * `facetLabels` carries the original spreadsheet header (e.g.
    `tip_de_protectie` → `"Tip de protectie"`). Fall back to the
    identifier when no label is registered.

### Auto-faceting — query categorisation

Pass `autoFacet: true` to let the engine recognise tag values inside
the query string and apply them as implicit `tag_eq` filters. "tricou
portocaliu" → query becomes "tricou" + filter `culoare:Portocaliu`.

```php
'autoFacet' => true,
```

Each implicit filter surfaces in `$result->autoFilters`:

```php
foreach ($result->autoFilters as $auto) {
    echo "Pre-checked: {$auto->field} = {$auto->value}";
    // Promote to an explicit clause if the user toggles it:
    $explicit = $auto->toTagEqClause();
    // ['op' => 'tag_eq', 'field' => 'culoare', 'value' => 'Portocaliu']
}
```

Storefront UX: render auto-filters as pre-checked chips in the filter
sidebar so the operator understands "tricou portocaliu" became
`q=tricou + culoare:Portocaliu` under the hood — and can un-toggle the
chip if the detection was over-eager.

Matching rules:

  * Builds the vocabulary from ALL `tag` fields in the schema.
  * Longest-phrase first: "Albastru Royal" wins over "Albastru".
  * Token boundary only — facet value `"S"` doesn't match every
    query containing the letter "s".
  * Stacks with explicit `filters` — auto-applied on top, never
    replaces what the caller sent.
  * Idempotent: an explicit filter with the same `(field, value)` is
    detected and not duplicated.

### Numeric boost — stock-aware ranking

`boost` multiplies BM25 by `1 + weight × f(value)` where `f` is `log`
(default — diminishing returns, safe for unbounded fields like `stoc`)
or `linear` (use only for bounded fields like a 0..1 popularity
quantile).

```php
'boost' => [
    'field'    => 'stoc',
    'function' => 'log',     // 'log' (default) or 'linear'
    'weight'   => 1.0,       // 1.0 default — tune per catalog
],
```

The classic e-commerce use case: in-stock variants outrank out-of-
stock peers without dominating relevance. With `function: 'log'` and
the default weight, `log10(1+0) = 0` keeps zero-stock hits at their
BM25 score while in-stock variants get a smooth lift.

Constraints:

  * Field must be `kind: "Numeric"` AND `sortable: true`.
  * Mutually exclusive with `sort` — the engine returns 400 if both
    are set. (Sort overrides BM25; multiplying the sort key is
    meaningless.)
  * Hybrid bypasses to BM25-only when boost is set.

### Autocomplete — `prefixLast`

Treat the last query token as a prefix. "adi" matches "adidași". Use
this on autocomplete dropdowns where the user is mid-typing.

```php
'prefixLast' => true,
```

Pair with a low `limit` (5–10) and avoid grouping/facets — autocomplete
budgets are tight.

## Building a filter page (end-to-end)

Storefront filter pages combine a half-dozen of the features above. The
recommended pattern is **one search per render** — every piece of UI
state (filters, sort, page, group, facets) goes into a single
`search()` call. The engine returns hits, facets, and auto-filters in
one round-trip.

```php
// 1. Parse URL state — single source of truth.
$q     = trim($_GET['q'] ?? '');
$sort  = $_GET['sort']  ?? 'relevance';   // 'relevance'|'price_asc'|'price_desc'|'name_asc'|'name_desc'|'newest'
$page  = max(1, (int) ($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$selectedFilters = [];
foreach (['marca', 'culoare', 'marime'] as $field) {
    if (!empty($_GET[$field])) {
        $values = is_array($_GET[$field])
            ? $_GET[$field]
            : explode(',', $_GET[$field]);
        $selectedFilters[] = count($values) === 1
            ? ['op' => 'tag_eq', 'field' => $field, 'value' => $values[0]]
            : ['op' => 'tag_in', 'field' => $field, 'values' => $values];
    }
}
if (!empty($_GET['min_pret']) || !empty($_GET['max_pret'])) {
    $selectedFilters[] = [
        'op'    => 'numeric_range',
        'field' => 'pret',
        'min'   => isset($_GET['min_pret']) ? (float) $_GET['min_pret'] : null,
        'max'   => isset($_GET['max_pret']) ? (float) $_GET['max_pret'] : null,
    ];
}

// 2. Map UI sort mode to engine sort spec.
$sortSpecs = [
    'relevance'  => null,
    'price_asc'  => [['field' => 'pret',            'direction' => 'asc']],
    'price_desc' => [['field' => 'pret',            'direction' => 'desc']],
    'name_asc'   => [['field' => 'denumire_produs', 'direction' => 'asc']],
    'name_desc'  => [['field' => 'denumire_produs', 'direction' => 'desc']],
    'newest'     => [['field' => 'parent_id',       'direction' => 'desc']],
];

// 3. ONE search — hits + facets + auto-filters in one round-trip.
$options = [
    'groupBy'   => 'parent_id',
    'facets'    => ['marca', 'culoare', 'marime'],
    'autoFacet' => true,
];
if ($sortSpecs[$sort] !== null) {
    $options['sort'] = $sortSpecs[$sort];
} else {
    // Boost is mutually exclusive with sort — use it only on
    // relevance mode where BM25 is the base.
    $options['boost'] = ['field' => 'stoc', 'function' => 'log', 'weight' => 1.0];
}

$result = $lexis->search(
    'products',
    $q !== '' ? $q : '*',
    $limit,
    $offset,
    $selectedFilters,
    null,
    $options
);

// 4. Render the filter sidebar from $result->facets.
foreach ($result->facets as $field => $buckets) {
    $label = $result->facetLabels[$field] ?? $field;
    // <h4>$label</h4>
    foreach ($buckets as $bucket) {
        // Toggle this (field, value) on the URL so the same
        // request shape works whether it's checked or unchecked.
        $href = currentUrlWithFilterToggled($field, $bucket->value);
        $checked = in_array($bucket->value, $_GET[$field] ?? [], true);
        // <a href="$href" class="@if($checked) selected @endif">
        //   $bucket->value ($bucket->count)
        // </a>
    }
}

// 5. Render auto-filter chips with an "x" link to remove.
foreach ($result->autoFilters as $auto) {
    // <span class="chip">$auto->field: $auto->value
    //   <a href="?q={$strippedQuery}">×</a>
    // </span>
}

// 6. Render product cards.
foreach ($result->hits as $hit) {
    $href = $lexis->withQid("/produse/{$hit->id}", $result->qid);
    // <a href="$href">
    //   <img src="{$hit->get('imagine')}">
    //   <h3>{$hit->get('denumire_produs')}</h3>
    //   <p>{$hit->get('pret')} lei</p>
    //   @if ($hit->groupedCount > 0)
    //     <span>{($hit->groupedCount + 1)} variante</span>
    //   @endif
    // </a>
}

// 7. Pagination.
$totalPages = max(1, (int) ceil($result->total / $limit));
// Render page links — &page=1, &page=2, ...

// 8. Track the page-view event (best effort).
try {
    $lexis->recordView(
        'search',
        \Lexis\Client::detectSource(
            $_SERVER['HTTP_REFERER'] ?? null,
            $_SERVER['HTTP_HOST'] ?? null,
        ),
        null,
        null,
        $_SERVER['HTTP_REFERER'] ?? null,
        $_SERVER['REQUEST_URI'] ?? null,
        null,
    );
} catch (\Lexis\Exception\LexisException $e) {
    error_log('lexis view tracking: ' . $e->getMessage());
}
```

A complete runnable example lives at
`examples/storefront-with-filters.php`.

### Index schema requirements

The index `mappings` must declare the right field kinds for each
feature. The dashboard's "Settings → Index schema" page does this; if
you build the schema by hand, the relevant entries:

```json
{
  "mappings": [
    { "name": "denumire_produs", "kind": "TextAndTag", "facetable": true },
    { "name": "pret",            "kind": "Numeric",    "sortable": true },
    { "name": "stoc",            "kind": "Numeric",    "sortable": true },
    { "name": "parent_id",       "kind": "Tag" },
    { "name": "marca",           "kind": "Tag",        "facetable": true },
    { "name": "culoare",         "kind": "Tag",        "facetable": true },
    { "name": "marime",          "kind": "Tag",        "facetable": true }
  ]
}
```

| Feature                    | Required mapping kind                  | Required flags        |
|----------------------------|----------------------------------------|-----------------------|
| `sort` (numeric)           | `Numeric`                              | `sortable: true`      |
| `sort` (parent_id)         | `Tag` matching `groupBy`               | —                     |
| `sort` (text/name)         | `Text` or `TextAndTag` + `groupBy` set | —                     |
| `groupBy`                  | `Tag`                                  | —                     |
| `facets`                   | `Tag`                                  | (`facetable: true` informational) |
| `autoFacet`                | `Tag` (any tag field qualifies)        | —                     |
| `boost`                    | `Numeric`                              | `sortable: true`      |
| filter `tag_eq` / `tag_in` | `Tag` or `TextAndTag`                  | —                     |
| filter `numeric_range`     | `Numeric`                              | —                     |

Using a feature against a field declared with the wrong kind returns
a `ValidationException` (HTTP 400) from the engine.

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

### Per-search drilldown — `getClicksForEvent()`

`getClickAttribution()` rolls up the whole window into KPIs and per-
query slices. Sometimes you want the opposite: given **one** search,
which products did the shopper actually click? The dashboard's
`/logs` page uses this to expand a row into a list of attributed
clicks; you can call the same endpoint from PHP if you want to
surface "what did the user pick" inline next to a search log entry.

```php
$result = $lexis->search('products', 'manusi');
// …time passes, the customer clicks something, the click is recorded…

// later, in admin tooling:
$clicks = $lexis->getClicksForEvent($orgId, $result->qid);
// $clicks: Lexis\Click[] — oldest first
foreach ($clicks as $c) {
    printf("%s  pos=%s  %s\n",
        $c->productId,
        $c->position ?? '—',
        $c->landingUrl ?? '');
}
```

The empty case (search with no attributed clicks) returns `[]`, not
an exception — most searches don't get clicked, that's normal.
Cheaper than `getClickAttribution()` when you already know the
specific search row to expand. Requires engine 0.7.10 or newer.

### Top queries — `getTopQueries()`

If you're building a "top searches" widget on your own admin (or
embedding Lexis analytics into a customer's CMS), `getTopQueries()`
returns the per-query rollup the engine builds from the event log:

```php
$rows = $lexis->getTopQueries($orgId, null, null, 20);
foreach ($rows as $row) {
    // $row: Lexis\TopQuery
    printf("%s: %d unique (%d raw events)\n",
        $row->query, $row->uniqueSearches, $row->searches);
}
```

Each row carries TWO complementary readings of the same event log:

- `$row->searches` — raw event count, every `/v1/search` call counts.
  Inflated by storefront back-button re-fetches and pagination —
  useful as a **load** metric.
- `$row->uniqueSearches` — dedup'd by `(session_proxy, hour_bucket)`
  server-side. Same shopper hitting back twice on the same query
  within an hour collapses to one. The **intent** metric to
  headline ahead of the raw count.

Session proxy fallback chain (engine-side): the storefront-supplied
session id (forward via `setSessionId()` or `X-Lexis-Session-Id`),
falling back to the client IP, falling back to per-event uniqueness
so anonymous traffic without either signal degrades to raw counting
rather than collapsing into a single bucket.

Pre-engine-0.7.9 builds don't return `unique_searches`; the SDK
falls back to mirroring `searches` so callers comparing the two
see equality ("no dedup applied") rather than a phantom zero.

## Page-view tracking

Click attribution answers "what does a user do **after** they search".
Page-view tracking answers the complementary question: "**how does**
the user arrive here?". Every hit on a product / category /
search-results / home page records a generic event regardless of
whether the visitor came from a Lexis search, an internal category,
an external source (Google, Facebook, email), or a direct bookmark.

What you can pivot on later in the dashboard at
`/c/<connection>/analytics/journeys`:

- **Per-product entry funnel** — for product X, what % of visits
  came from search vs category vs external
- **Top referring domains** — which external sites drive the most
  traffic
- **Search-to-view ratio** — how many searches convert into a
  visit on the result page
- **Source breakdown** — traffic distribution by source over time

### One call per page template

```php
$referrer = $_SERVER['HTTP_REFERER'] ?? null;

$lexis->recordView(
    pageType: 'product',                          // search/product/category/home/other
    source: \Lexis\Client::detectSource(          // auto-classify the referrer
        $referrer,
        $_SERVER['HTTP_HOST'] ?? null,
    ),
    productId: $product->id,                      // primary key on product pages
    referrer: $referrer,                          // host extracted on SDK; full URL never leaves
    landingUrl: $_SERVER['REQUEST_URI'] ?? null,  // strip query params before passing
    qid: $_GET[\Lexis\Client::ATTRIBUTION_PARAM] ?? null,  // forward the qid from a search
);
```

### Per page type

```php
// PRODUCT page
$lexis->recordView(
    pageType: 'product',
    source: \Lexis\Client::detectSource($referrer, $host),
    productId: $product->id,
    referrer: $referrer,
    landingUrl: $_SERVER['REQUEST_URI'] ?? null,
    qid: $_GET[\Lexis\Client::ATTRIBUTION_PARAM] ?? null,
);

// CATEGORY page
$lexis->recordView(
    pageType: 'category',
    source: \Lexis\Client::detectSource($referrer, $host),
    categorySlug: $category->slug,
    referrer: $referrer,
    landingUrl: $_SERVER['REQUEST_URI'] ?? null,
);

// SEARCH-RESULTS page (rendered server-side after Client::search())
$lexis->recordView(
    pageType: 'search',
    source: \Lexis\Client::detectSource($referrer, $host),
    referrer: $referrer,
    landingUrl: $_SERVER['REQUEST_URI'] ?? null,
);

// HOMEPAGE
$lexis->recordView(
    pageType: 'home',
    source: \Lexis\Client::detectSource($referrer, $host),
    referrer: $referrer,
    landingUrl: '/',
);
```

### Auto-detect `source`

The static helper `Client::detectSource($referrer, $currentHost)`
classifies the visitor's origin into one of five buckets:

| Referrer                                       | Source     |
|------------------------------------------------|------------|
| `null` / empty / unparseable                   | `direct`   |
| External domain (`google.com`, `facebook.com`) | `external` |
| Same origin + `/search` or `/cautare` path     | `search`   |
| Same origin + `/?q=...` query                  | `search`   |
| Same origin + `/category/` or `/categorie/`    | `category` |
| Same origin + any other path                   | `referral` |

For storefronts with non-standard URLs (e.g. `/products-cat-boots/`
for categories), pass `source` to `recordView` directly without
going through `detectSource`.

### Full traffic journeys

Three storyboards that combine the SDK calls into end-to-end flows:

**Journey A — Google → product page directly (single page hit):**

```php
// Visitor lands on /produse/bocanci-timberland from Google search.
// $_SERVER['HTTP_REFERER'] === 'https://www.google.com/search?q=bocanci+timberland'

$lexis->recordView(
    pageType: 'product',
    source: \Lexis\Client::detectSource(
        $_SERVER['HTTP_REFERER'] ?? null,
        $_SERVER['HTTP_HOST'] ?? null,
    ),  // → 'external'
    productId: $product->id,
    referrer: $_SERVER['HTTP_REFERER'] ?? null,
    landingUrl: $_SERVER['REQUEST_URI'] ?? null,
);
// Engine logs: external traffic from google.com → product sku-1234
```

**Journey B — Google → internal search → click result → product page:**

```php
// 1) On the search-results page, after the search call:
$result = $lexis->search('products', $_GET['q']);
$lexis->recordView(
    pageType: 'search',
    source: \Lexis\Client::detectSource(/* ... */),  // → 'external' (came from Google)
    referrer: $_SERVER['HTTP_REFERER'] ?? null,
    landingUrl: $_SERVER['REQUEST_URI'] ?? null,
);

// 2) Stamp qid onto every result link so click attribution fires:
foreach ($result->hits as $hit) {
    $href = $lexis->withQid("/produse/{$hit->id}", $result->qid);
    // <a href="{$href}">…</a>
}

// 3) Visitor clicks a result. On the product page, BOTH calls fire:
$qid = $_GET[\Lexis\Client::ATTRIBUTION_PARAM] ?? null;
if ($qid) {
    $lexis->recordClick('products', $qid, $product->id);
}
$lexis->recordView(
    pageType: 'product',
    source: \Lexis\Client::detectSource(/* ... */),  // → 'search' (came from same-origin /search)
    productId: $product->id,
    referrer: $_SERVER['HTTP_REFERER'] ?? null,
    landingUrl: $_SERVER['REQUEST_URI'] ?? null,
    qid: $qid,  // join key against the original search event
);
```

**Journey C — Internal browsing (homepage → category → product):**

```php
// Each page just calls recordView once. detectSource picks up the
// same-origin path and classifies correctly without the controller
// having to know.
//   /            → source: direct  (no referrer)
//   /categorie/bocanci   → source: referral  (came from /)
//   /produse/sku-X       → source: category  (came from /categorie/...)
```

### Privacy

The SDK **never sends the full referrer URL** — it extracts only the
host (`google.com`) before posting. This protects PII that can leak
through referrer query strings (`utm_*`, partner ids, marketing
recipient markers). The engine re-validates the host on its side
and only accepts the already-extracted string.

`landingUrl` is forwarded as-is, but the storefront **must strip**
query params and fragments before passing it. The engine does not
index `landingUrl` for analytics — it's stored only for ops
debugging.

### Framework integration

**Laravel** — middleware that runs on every request:

```php
// app/Http/Middleware/LexisJourneyTracker.php
public function handle(Request $request, Closure $next)
{
    $response = $next($request);
    try {
        $lexis = app(\Lexis\Client::class);
        // ... determine pageType + productId from route binding ...
        $lexis->recordView(/* ... */);
    } catch (\Lexis\Exception\LexisException $e) {
        report($e);  // log but never break the response
    }
    return $response;
}
```

**Symfony** — kernel.response event subscriber, same shape.

**WordPress** — `template_redirect` action hook on each page template
(single product, archive, page).

### Best-effort

`recordView` throws `LexisException` on network / 4xx / 5xx — wrap
the call in try/catch so analytics noise never breaks the page render:

```php
try {
    $lexis->recordView(/* ... */);
} catch (\Lexis\Exception\LexisException $e) {
    error_log('lexis view tracking: ' . $e->getMessage());
}
```

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
