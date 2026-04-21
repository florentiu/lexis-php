<?php

declare(strict_types=1);

namespace Lexis;

/**
 * Typed wrapper over the /search response. The server response shape is:
 *
 *   {
 *     "hits": [...],
 *     "total": 42,
 *     "limit": 20,
 *     "offset": 0,
 *     "took_ms": 12,
 *     "query": "adidași",
 *     "expanded_terms": ["adidas", "sneakers"],
 *     "suggestion": "adidași"   // optional, only on typo-ish queries
 *   }
 *
 * `expanded_terms` is the normalised/stemmed form of the query after the
 * engine applied synonyms — surfaces the reason a hit matched even if the
 * exact query string isn't in the document.
 */
final class SearchResult
{
    /**
     * @var array<int, SearchHit>
     * @readonly
     */
    public array $hits;

    /** @readonly */
    public int $total;

    /** @readonly */
    public int $limit;

    /** @readonly */
    public int $offset;

    /** @readonly */
    public int $tookMs;

    /** @readonly */
    public string $query;

    /**
     * @var array<int, string>
     * @readonly
     */
    public array $expandedTerms;

    /** @readonly */
    public ?string $suggestion;

    /**
     * @param array<int, SearchHit> $hits           Relevance-ordered results (page only).
     * @param int                   $total          Total matching documents across all pages.
     * @param int                   $limit          Page size used.
     * @param int                   $offset         Offset used.
     * @param int                   $tookMs         Server-side query time.
     * @param string                $query          Normalised query the engine actually ran.
     * @param array<int, string>    $expandedTerms  Stemmed/synonym-expanded terms.
     * @param string|null           $suggestion     Did-you-mean; null when none.
     */
    public function __construct(
        array $hits,
        int $total,
        int $limit,
        int $offset,
        int $tookMs,
        string $query,
        array $expandedTerms,
        ?string $suggestion
    ) {
        $this->hits = $hits;
        $this->total = $total;
        $this->limit = $limit;
        $this->offset = $offset;
        $this->tookMs = $tookMs;
        $this->query = $query;
        $this->expandedTerms = $expandedTerms;
        $this->suggestion = $suggestion;
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

        $expanded = isset($raw['expanded_terms']) && is_array($raw['expanded_terms'])
            ? $raw['expanded_terms']
            : [];
        $expandedStrings = array_values(array_map('strval', $expanded));

        $suggestion = isset($raw['suggestion']) ? $raw['suggestion'] : null;

        return new self(
            $hits,
            (int) ($raw['total'] ?? count($hits)),
            (int) ($raw['limit'] ?? 20),
            (int) ($raw['offset'] ?? 0),
            (int) ($raw['took_ms'] ?? 0),
            (string) ($raw['query'] ?? ''),
            $expandedStrings,
            is_string($suggestion) && $suggestion !== '' ? $suggestion : null
        );
    }
}
