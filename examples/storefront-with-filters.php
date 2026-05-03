<?php
/**
 * Storefront listing page — search + filter sidebar + sort + grouping +
 * auto-faceting + boost + click attribution + page-view tracking, all in
 * one PHP file.
 *
 * Drop this into a controller / template that handles
 *   /search?q=tricou&page=1&sort=price_asc&culoare[]=Rosu&marca[]=Renania
 *
 * The pattern is **one search per render**: every piece of UI state
 * (filters, sort, page, group, facets) goes into a single `search()`
 * call. The engine returns hits, facets, auto-filters, and counts in one
 * round-trip — there's no need to issue a second "facets only" request.
 *
 * Requires PHP 7.4+, ext-curl, ext-json, and an index whose schema declares:
 *   - denumire_produs  : TextAndTag, facetable
 *   - pret             : Numeric, sortable
 *   - stoc             : Numeric, sortable
 *   - parent_id        : Tag
 *   - marca            : Tag, facetable
 *   - culoare          : Tag, facetable
 *   - marime           : Tag, facetable
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Lexis\Client;
use Lexis\Exception\LexisException;

// ---------- 0) Setup ----------

$lexis = new Client(getenv('LEXIS_API_KEY') ?: 'lexis_test_key');
// Forward the storefront's session id so the engine can count distinct
// visitors in zero-results / CTR analytics.
if (function_exists('session_id') && session_id() !== '') {
    $lexis->setSessionId(session_id());
}

$indexSlug = 'products';
$limit = 20;

// ---------- 1) Read URL state — single source of truth ----------

$q     = trim((string) ($_GET['q']    ?? ''));
$page  = max(1, (int) ($_GET['page']  ?? 1));
$sort  = (string) ($_GET['sort']      ?? 'relevance');
$offset = ($page - 1) * $limit;

// Tag-field filter selections. The URL carries each as `field[]=value` so
// PHP parses it as an array natively. Single-value selections become
// `tag_eq`, multi-value become `tag_in` — the engine handles both.
$tagFields = ['marca', 'culoare', 'marime'];
$selectedFilters = [];
$selectedByField = [];
foreach ($tagFields as $field) {
    $values = $_GET[$field] ?? [];
    if (is_string($values)) {
        $values = $values === '' ? [] : explode(',', $values);
    }
    if (!is_array($values) || $values === []) {
        continue;
    }
    $values = array_values(array_filter(array_map('strval', $values), 'strlen'));
    if ($values === []) {
        continue;
    }
    $selectedByField[$field] = $values;
    $selectedFilters[] = count($values) === 1
        ? ['op' => 'tag_eq', 'field' => $field, 'value' => $values[0]]
        : ['op' => 'tag_in', 'field' => $field, 'values' => $values];
}

// Price range — half-open numeric range; either bound may be omitted.
$minPret = isset($_GET['min_pret']) && $_GET['min_pret'] !== '' ? (float) $_GET['min_pret'] : null;
$maxPret = isset($_GET['max_pret']) && $_GET['max_pret'] !== '' ? (float) $_GET['max_pret'] : null;
if ($minPret !== null || $maxPret !== null) {
    $clause = ['op' => 'numeric_range', 'field' => 'pret'];
    if ($minPret !== null) {
        $clause['min'] = $minPret;
    }
    if ($maxPret !== null) {
        $clause['max'] = $maxPret;
    }
    $selectedFilters[] = $clause;
}

// ---------- 2) Map UI sort modes to engine sort specs ----------

$sortSpecs = [
    'relevance'  => null,                                                  // BM25 (default)
    'price_asc'  => [['field' => 'pret',            'direction' => 'asc']],
    'price_desc' => [['field' => 'pret',            'direction' => 'desc']],
    'name_asc'   => [['field' => 'denumire_produs', 'direction' => 'asc']],
    'name_desc'  => [['field' => 'denumire_produs', 'direction' => 'desc']],
    'newest'     => [['field' => 'parent_id',       'direction' => 'desc']],
];
$sortSpec = $sortSpecs[$sort] ?? null;

// ---------- 3) ONE search — hits + facets + auto-filters ----------

$options = [
    'groupBy'   => 'parent_id',                  // collapse variants into one card
    'facets'    => $tagFields,                   // bucket counts for the sidebar
    'autoFacet' => true,                         // "tricou portocaliu" → culoare:Portocaliu
];
if ($sortSpec !== null) {
    $options['sort'] = $sortSpec;
} else {
    // Boost is mutually exclusive with sort. Use it only when the
    // operator is on relevance mode — in-stock items outrank
    // out-of-stock peers without dominating BM25.
    $options['boost'] = ['field' => 'stoc', 'function' => 'log', 'weight' => 1.0];
}

try {
    $result = $lexis->search(
        $indexSlug,
        $q !== '' ? $q : '*',
        $limit,
        $offset,
        $selectedFilters !== [] ? $selectedFilters : null,
        null,                                    // searchAfter — incompatible with groupBy
        $options
    );
} catch (LexisException $e) {
    http_response_code(500);
    echo "Search failed: " . htmlspecialchars($e->getMessage());
    return;
}

// ---------- 4) Helpers for filter-toggle URLs ----------

/**
 * Build the URL for the current page with one filter (field, value)
 * toggled on/off. Preserves all other state so checking a checkbox
 * doesn't reset sort / page / query.
 */
function urlWithFilterToggled(string $field, string $value, array $selectedByField): string {
    $params = $_GET;
    $current = $selectedByField[$field] ?? [];
    if (in_array($value, $current, true)) {
        $current = array_values(array_filter($current, fn($v) => $v !== $value));
    } else {
        $current[] = $value;
    }
    if ($current === []) {
        unset($params[$field]);
    } else {
        $params[$field] = $current;
    }
    // Reset to page 1 when filters change — the user expects to see
    // "page 1 of the new results", not their old page index.
    unset($params['page']);
    return strtok($_SERVER['REQUEST_URI'] ?? '/', '?') . '?' . http_build_query($params);
}

function urlWithSort(string $newSort): string {
    $params = $_GET;
    $params['sort'] = $newSort;
    unset($params['page']);
    return strtok($_SERVER['REQUEST_URI'] ?? '/', '?') . '?' . http_build_query($params);
}

function urlWithPage(int $newPage): string {
    $params = $_GET;
    $params['page'] = $newPage;
    return strtok($_SERVER['REQUEST_URI'] ?? '/', '?') . '?' . http_build_query($params);
}

// ---------- 5) Track the page view (best effort) ----------

try {
    $referrer = $_SERVER['HTTP_REFERER'] ?? null;
    $lexis->recordView(
        'search',
        Client::detectSource($referrer, $_SERVER['HTTP_HOST'] ?? null),
        null,
        null,
        $referrer,
        $_SERVER['REQUEST_URI'] ?? null
    );
} catch (LexisException $e) {
    error_log('lexis view tracking: ' . $e->getMessage());
}

// ---------- 6) Render ----------

?><!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<title>Cautare: <?= htmlspecialchars($q) ?></title>
<style>
  body { font-family: sans-serif; max-width: 1200px; margin: 0 auto; }
  .layout { display: grid; grid-template-columns: 240px 1fr; gap: 24px; }
  .facet h4 { margin: 16px 0 4px; }
  .facet a { display: block; padding: 2px 0; color: #333; text-decoration: none; }
  .facet a.selected { font-weight: bold; color: #d44; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }
  .card { border: 1px solid #ddd; padding: 12px; }
  .chip { display: inline-block; padding: 4px 10px; background: #f0e8ff; border-radius: 12px; margin: 4px; }
  .meta { color: #666; font-size: 13px; }
</style>
</head>
<body>

<form method="get">
  <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Caută…" autofocus>
  <button type="submit">Caută</button>
</form>

<div style="margin: 12px 0;">
  Sortare:
  <?php foreach (['relevance' => 'Relevanță', 'price_asc' => 'Preț ↑', 'price_desc' => 'Preț ↓', 'name_asc' => 'Nume A-Z', 'name_desc' => 'Nume Z-A', 'newest' => 'Cele mai noi'] as $key => $label): ?>
    <a href="<?= htmlspecialchars(urlWithSort($key)) ?>"
       style="<?= $sort === $key ? 'font-weight:bold' : '' ?>">
      <?= $label ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($result->autoCorrected): ?>
  <div class="meta">Rezultate pentru: <strong><?= htmlspecialchars($result->query) ?></strong></div>
<?php elseif ($result->fallbackMode !== null): ?>
  <div class="meta">Rezultate aproximative (<?= htmlspecialchars($result->fallbackMode) ?>)</div>
<?php endif; ?>

<?php if ($result->autoFilters !== []): ?>
  <div>
    Filtre detectate automat din interogare:
    <?php foreach ($result->autoFilters as $auto): ?>
      <span class="chip"><?= htmlspecialchars($auto->field) ?>: <?= htmlspecialchars($auto->value) ?></span>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="meta"><?= number_format($result->total) ?> produse · <?= $result->tookMs ?> ms</div>

<div class="layout">

  <aside>
    <?php foreach ($result->facets as $field => $buckets): ?>
      <div class="facet">
        <h4><?= htmlspecialchars($result->facetLabels[$field] ?? $field) ?></h4>
        <?php foreach ($buckets as $bucket):
          $checked = in_array($bucket->value, $selectedByField[$field] ?? [], true);
          $href = urlWithFilterToggled($field, $bucket->value, $selectedByField);
        ?>
          <a href="<?= htmlspecialchars($href) ?>" class="<?= $checked ? 'selected' : '' ?>">
            <?= $checked ? '✓ ' : '' ?><?= htmlspecialchars($bucket->value) ?>
            <span class="meta">(<?= $bucket->count ?>)</span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <div class="facet">
      <h4>Preț</h4>
      <form method="get">
        <?php foreach ($_GET as $k => $v):
          if (in_array($k, ['min_pret', 'max_pret', 'page'], true)) continue;
          if (is_array($v)) {
            foreach ($v as $vv) echo '<input type="hidden" name="' . htmlspecialchars($k) . '[]" value="' . htmlspecialchars($vv) . '">';
          } else {
            echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars((string) $v) . '">';
          }
        endforeach; ?>
        <input type="number" name="min_pret" value="<?= htmlspecialchars((string) ($minPret ?? '')) ?>" placeholder="min" style="width:60px">
        –
        <input type="number" name="max_pret" value="<?= htmlspecialchars((string) ($maxPret ?? '')) ?>" placeholder="max" style="width:60px">
        <button type="submit">Aplică</button>
      </form>
    </div>
  </aside>

  <main>
    <?php if ($result->hits === []): ?>
      <p>Nu am găsit nimic pentru <strong><?= htmlspecialchars($q) ?></strong>.</p>
      <?php if ($result->suggestion !== null): ?>
        <p>Ai vrut să spui <a href="?q=<?= urlencode($result->suggestion) ?>"><?= htmlspecialchars($result->suggestion) ?></a>?</p>
      <?php endif; ?>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($result->hits as $hit):
          $url = '/produse/' . rawurlencode($hit->id);
          $href = $lexis->withQid($url, $result->qid);
        ?>
          <article class="card">
            <a href="<?= htmlspecialchars($href) ?>">
              <?php if ($hit->get('imagine')): ?>
                <img src="<?= htmlspecialchars($hit->get('imagine')) ?>" alt="" style="width:100%;">
              <?php endif; ?>
              <h3 style="font-size:14px;"><?= htmlspecialchars((string) $hit->get('denumire_produs')) ?></h3>
              <p><strong><?= number_format((float) $hit->get('pret', 0), 2) ?> lei</strong></p>
              <?php if ($hit->groupedCount > 0): ?>
                <p class="meta"><?= ($hit->groupedCount + 1) ?> variante disponibile</p>
              <?php endif; ?>
            </a>
          </article>
        <?php endforeach; ?>
      </div>

      <?php
        $totalPages = max(1, (int) ceil($result->total / $limit));
        if ($totalPages > 1):
      ?>
        <nav style="margin: 24px 0;">
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="<?= htmlspecialchars(urlWithPage($p)) ?>"
               style="<?= $p === $page ? 'font-weight:bold' : '' ?>"><?= $p ?></a>
          <?php endfor; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </main>

</div>

</body>
</html>
