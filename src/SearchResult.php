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
 *       "fallback_mode": null,           // optional
 *       "qid": "q_a8f4kx2j",             // empty when ?log=false
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
     * if the page were unbounded. Distinct from `count(hits)` which is
     * the page size capped by `limit`. Engine ships this as
     * `count_estimate` because Tantivy returns an upper bound for very
     * large segments — the value is exact for typical e-commerce
     * catalogs (millions, not hundreds of millions).
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
     * @param array<int, SearchHit> $hits           Relevance-ordered results (page only).
     * @param int                   $total          Total matching documents across all pages.
     * @param int                   $tookMs         Server-side query time.
     * @param string                $query          Normalised query the engine actually ran.
     * @param string|null           $suggestion     Did-you-mean; null when none.
     * @param bool                  $autoCorrected  Whether the executed query is a correction.
     * @param string                $qid            Per-search opaque id; '' when absent.
     * @param string|null           $nextCursor     `search_after` token for the next page; null on the last.
     */
    public function __construct(
        array $hits,
        int $total,
        int $tookMs,
        string $query,
        ?string $suggestion,
        bool $autoCorrected,
        string $qid,
        ?string $nextCursor
    ) {
        $this->hits = $hits;
        $this->total = $total;
        $this->tookMs = $tookMs;
        $this->query = $query;
        $this->suggestion = $suggestion;
        $this->autoCorrected = $autoCorrected;
        $this->qid = $qid;
        $this->nextCursor = $nextCursor;
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

        return new self(
            $hits,
            (int) ($raw['count_estimate'] ?? count($hits)),
            $tookMs,
            (string) ($raw['effective_query'] ?? ''),
            is_string($suggestion) && $suggestion !== '' ? $suggestion : null,
            $autoCorrected,
            $qid,
            $nextCursor
        );
    }
}
