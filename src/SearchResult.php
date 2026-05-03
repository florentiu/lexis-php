<?php

declare(strict_types=1);

namespace Lexis;

/**
 * Typed wrapper over the /search response. The engine emits (see
 * `lexis_core::SearchResponse`):
 *
 *     {
 *       "hits": [...],
 *       "count_estimate": 5000,
 *       "effective_query": "adidași",
 *       "suggestion": "adidași",        // optional, only on did-you-mean
 *       "auto_corrected": false,
 *       "fallback_mode": null,           // optional: "strict" | "phonetic" | "union"
 *       "qid": "q_a8f4kx2j",             // empty when ?log=false
 *       "facets": {                      // present only when `facets:[...]` requested
 *         "marca":   [{"value":"Cofra","count":24}, ...],
 *         "culoare": [{"value":"Albastru","count":17}, ...]
 *       },
 *       "auto_filters": [                // present only when `autoFacet:true`
 *         {"field":"culoare","value":"Portocaliu"}
 *       ],
 *       "facet_labels": {                // human-readable names per field
 *         "tip_de_protectie": "Tip de protectie"
 *       },
 *       "diagnostics": { "took_ms": 12, "primary_hits": 5000, ... }
 *     }
 *
 * Field naming on the PHP side keeps the conventions PHP devs expect
 * (`total` for cardinality, `tookMs` for latency, `query` for the
 * effective query) — not the engine's internal names — so user code
 * doesn't have to learn Tantivy / BM25 vocabulary to read a result.
 */
final class SearchResult
{
    /**
     * @var array<int, SearchHit>
     * @readonly
     */
    public array $hits;

    /**
     * Cardinality of the match: how many documents *would* be returned
     * if the page were unbounded.
     *
     * **When `groupBy` is active**, this counts UNIQUE GROUPS, not raw
     * variant documents — the right number to render "59 produse" on
     * a variant-collapsed listing where each card represents one
     * parent product but the index stores one document per
     * size × color × ... combination.
     *
     * Distinct from `count(hits)` which is the page size capped by
     * `limit`. Engine ships this as `count_estimate` because Tantivy
     * returns an upper bound for very large segments — the value is
     * exact for typical e-commerce catalogs (millions, not hundreds
     * of millions).
     *
     * @readonly
     */
    public int $total;

    /** @readonly */
    public int $tookMs;

    /**
     * Query string actually executed, after normalization, stemming,
     * and (when applicable) auto-correction. Differs from what the
     * caller passed when {@see $autoCorrected} is true. Useful for
     * "showing results for X" UI strings.
     *
     * @readonly
     */
    public string $query;

    /**
     * Did-you-mean suggestion. Set only when the engine has a
     * different-but-likely candidate (typo correction, FST hit) AND it
     * differs from the executed query. `null` otherwise.
     *
     * @readonly
     */
    public ?string $suggestion;

    /**
     * True when the engine retried the search with the corrected query
     * (because the original returned zero / very few hits). The hits
     * you're seeing came from {@see $query}, not from the original
     * input. Use this to render a "Searched for X instead of Y" hint.
     *
     * @readonly
     */
    public bool $autoCorrected;

    /**
     * Per-search opaque id minted by the engine (`q_<8 base62>`). Round-trip
     * it back to the engine as `?lexis_qid=...` on result links so the
     * storefront's landing-page request can post a click attribution beacon
     * via {@see Client::recordClick()}. Empty string when the engine response
     * omitted the field — talking to a pre-click-attribution engine, or the
     * caller passed `?log=false`.
     *
     * @readonly
     */
    public string $qid;

    /**
     * Cursor to pass as `searchAfter` on the next call to
     * {@see Client::search()} for deep pagination. Equals the `cursor`
     * field of the last hit; `null` when the current page is the last
     * (no resumable boundary). Use this instead of incrementing
     * `offset` past ~1000 — `search_after` is O(page) regardless of
     * depth, while `offset` is O(offset+limit).
     *
     *     $cursor = null;
     *     do {
     *         $r = $lexis->search('products', '*', 100, 0, null, $cursor);
     *         foreach ($r->hits as $h) { ... }
     *         $cursor = $r->nextCursor;
     *     } while ($cursor !== null);
     *
     * @readonly
     */
    public ?string $nextCursor;

    /**
     * Facet aggregations — populated only when the search request
     * asked for them via the `facets` option. Keys are the requested
     * field names (engine identifiers); values are top-K bucket
     * lists in `(count desc, value asc)` order, capped at 200 per
     * field by the engine.
     *
     * Use {@see $facetLabels} to look up the human-readable display
     * name for the engine identifier; fall back to the identifier
     * itself when no label is registered.
     *
     *     // Render: "Brand: Acme (24), Globex (18), Initech (7)"
     *     foreach ($result->facets['marca'] ?? [] as $bucket) {
     *         echo $bucket->value . ' (' . $bucket->count . ')';
     *     }
     *
     * Empty array when no facets were requested OR the matching set
     * was empty.
     *
     * @var array<string, array<int, FacetBucket>>
     * @readonly
     */
    public array $facets;

    /**
     * Human-readable label per facet/sort field, sourced from the
     * per-index column-label map (the original spreadsheet header).
     * Maps engine identifier (`tip_de_protectie`) to display name
     * (`"Tip de protectie"`).
     *
     *     $field = 'tip_de_protectie';
     *     $label = $result->facetLabels[$field] ?? $field;
     *
     * Empty array when the index has no label registry (typical for
     * indexes synced from JSON whose keys are already valid
     * identifiers).
     *
     * @var array<string, string>
     * @readonly
     */
    public array $facetLabels;

    /**
     * Filters the engine added IMPLICITLY when `autoFacet: true` was
     * set on the request — query tokens that exactly matched known
     * tag values get promoted to `tag_eq` filters and stripped from
     * the BM25 query. Empty list when `autoFacet` was off or no
     * match was found.
     *
     * Storefront UX: render as pre-checked chips so the operator
     * understands "tricou portocaliu" became
     * `q=tricou + culoare:Portocaliu` under the hood. Each entry
     * exposes {@see AppliedFilter::toTagEqClause()} for promoting
     * the auto-filter to an explicit one on the next request (e.g.
     * after the user un-checks then re-applies it).
     *
     * @var array<int, AppliedFilter>
     * @readonly
     */
    public array $autoFilters;

    /**
     * Which fallback mode the engine ran when the primary BM25 pass
     * returned no hits. One of:
     *
     *   * `'strict'`  — same query, fuzzy match disabled (rare).
     *   * `'phonetic'` — sound-alike retry (Romanian-tuned Soundex).
     *   * `'union'`   — OR'd query tokens after AND failed.
     *
     * `null` when the primary pass had hits or fallback was
     * disabled. Useful for a "Showing approximate matches" UI hint
     * — phonetic / union results are deliberately broader.
     *
     * @readonly
     */
    public ?string $fallbackMode;

    /**
     * @param array<int, SearchHit>            $hits          Relevance-ordered results (page only).
     * @param int                              $total         Total matching documents (or unique groups when grouped).
     * @param int                              $tookMs        Server-side query time.
     * @param string                           $query         Normalised query the engine actually ran.
     * @param string|null                      $suggestion    Did-you-mean; null when none.
     * @param bool                             $autoCorrected Whether the executed query is a correction.
     * @param string                           $qid           Per-search opaque id; '' when absent.
     * @param string|null                      $nextCursor    `search_after` token for the next page; null on the last.
     * @param array<string, array<int, FacetBucket>> $facets        Bucket lists per requested facet field.
     * @param array<string, string>            $facetLabels   Display names per engine field id.
     * @param array<int, AppliedFilter>        $autoFilters   Implicitly-applied filters from `autoFacet`.
     * @param string|null                      $fallbackMode  Fallback path that ran (or null).
     */
    public function __construct(
        array $hits,
        int $total,
        int $tookMs,
        string $query,
        ?string $suggestion,
        bool $autoCorrected,
        string $qid,
        ?string $nextCursor,
        array $facets = [],
        array $facetLabels = [],
        array $autoFilters = [],
        ?string $fallbackMode = null
    ) {
        $this->hits = $hits;
        $this->total = $total;
        $this->tookMs = $tookMs;
        $this->query = $query;
        $this->suggestion = $suggestion;
        $this->autoCorrected = $autoCorrected;
        $this->qid = $qid;
        $this->nextCursor = $nextCursor;
        $this->facets = $facets;
        $this->facetLabels = $facetLabels;
        $this->autoFilters = $autoFilters;
        $this->fallbackMode = $fallbackMode;
    }

    /**
     * @param array<string, mixed> $raw Raw decoded JSON body.
     */
    public static function fromArray(array $raw): self
    {
        $hitsRaw = isset($raw['hits']) && is_array($raw['hits']) ? $raw['hits'] : [];
        $hits = [];
        foreach ($hitsRaw as $hit) {
            if (is_array($hit)) {
                $hits[] = new SearchHit($hit);
            }
        }

        // The engine nests latency under `diagnostics.took_ms` rather
        // than echoing it at the top level (the diagnostics block also
        // carries per-stage timings useful for tuning, kept aside on
        // the SDK so the typed shape stays focused). Fall back to 0
        // for older builds that only emit a partial diagnostics block.
        $diag = isset($raw['diagnostics']) && is_array($raw['diagnostics']) ? $raw['diagnostics'] : [];
        $tookMs = isset($diag['took_ms']) ? (int) $diag['took_ms'] : 0;

        $suggestion = $raw['suggestion'] ?? null;
        $qid = isset($raw['qid']) && is_string($raw['qid']) ? $raw['qid'] : '';
        $autoCorrected = isset($raw['auto_corrected'])
            ? (bool) $raw['auto_corrected']
            : false;

        // `nextCursor` mirrors the cursor on the last hit. The engine
        // omits the cursor field on the final hit of the final page,
        // so a null here is the natural "no more pages" signal.
        $nextCursor = null;
        if (count($hits) > 0) {
            $nextCursor = $hits[count($hits) - 1]->cursor;
        }

        // `facets`: { fieldName: [ {value, count}, ... ] }. Decode
        // each bucket into a typed `FacetBucket`. Engine omits the
        // whole map when no facets were requested OR when buckets
        // are empty — default to an empty array so callers can
        // unconditionally `foreach` without an isset guard.
        $facets = [];
        if (isset($raw['facets']) && is_array($raw['facets'])) {
            foreach ($raw['facets'] as $field => $buckets) {
                if (!is_string($field) || !is_array($buckets)) {
                    continue;
                }
                $list = [];
                foreach ($buckets as $b) {
                    if (is_array($b)) {
                        $list[] = FacetBucket::fromArray($b);
                    }
                }
                $facets[$field] = $list;
            }
        }

        // `facet_labels`: { engineId: "Display Name" }. Forward
        // verbatim — typed as `array<string,string>` so consumers
        // can `$labels[$id] ?? $id` it without conversion.
        $facetLabels = [];
        if (isset($raw['facet_labels']) && is_array($raw['facet_labels'])) {
            foreach ($raw['facet_labels'] as $id => $label) {
                if (is_string($id) && is_string($label)) {
                    $facetLabels[$id] = $label;
                }
            }
        }

        // `auto_filters`: list of {field, value} for chips that the
        // engine applied implicitly via `autoFacet`. Empty list when
        // the option was off or no token matched.
        $autoFilters = [];
        if (isset($raw['auto_filters']) && is_array($raw['auto_filters'])) {
            foreach ($raw['auto_filters'] as $entry) {
                if (is_array($entry)) {
                    $autoFilters[] = AppliedFilter::fromArray($entry);
                }
            }
        }

        $fallbackMode = null;
        if (isset($raw['fallback_mode']) && is_string($raw['fallback_mode']) && $raw['fallback_mode'] !== '') {
            $fallbackMode = $raw['fallback_mode'];
        }

        return new self(
            $hits,
            (int) ($raw['count_estimate'] ?? count($hits)),
            $tookMs,
            (string) ($raw['effective_query'] ?? ''),
            is_string($suggestion) && $suggestion !== '' ? $suggestion : null,
            $autoCorrected,
            $qid,
            $nextCursor,
            $facets,
            $facetLabels,
            $autoFilters,
            $fallbackMode
        );
    }
}
