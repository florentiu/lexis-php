<?php

declare(strict_types=1);

namespace Lexis;

/**
 * One result row from /search.
 *
 * Wire shape emitted by the engine (see `lexis_core::SearchHit`):
 *
 *     {
 *       "id": "sku-1",
 *       "score": 4.2,
 *       "payload": { "title": "Adidași Nike Air", "price": 349 },
 *       "cursor": "eyJvZmZzZXQiOjksImxhc3RfaWQiOiIxMDQ4MCJ9",
 *       "grouped_count": 4
 *     }
 *
 *   * `id` is the document's primary key as a string (regardless of whether
 *     the field was numeric or string in the source — the engine canonicalizes).
 *   * `score` is BM25 (or the RRF-fused score on hybrid runs, or the sort
 *     value re-packed into the score slot when an explicit `sort` was used).
 *   * `payload` is everything the caller originally pushed; we expose it as
 *     `$hit->document` and as a typed accessor `$hit->get('field')`.
 *   * `cursor` is an opaque base64 token used for `search_after` deep
 *     pagination — only present on hits where pagination can resume from
 *     this row. The last row of the last page has no cursor.
 *   * `grouped_count` is the number of OTHER variants collapsed under this
 *     row when {@see SearchRequest::group_by} was set. `0` when grouping is
 *     off OR when the group has a single member. Use it to render
 *     "+5 variante" badges on a variant-collapsed product card.
 */
final class SearchHit
{
    /**
     * @var array<string, mixed>
     * @readonly
     */
    public array $document;

    /** @readonly */
    public string $id;

    /** @readonly */
    public float $score;

    /**
     * Opaque `search_after` cursor, or `null` when this hit isn't a
     * resumable boundary (the engine omits the field on the final
     * hit of the final page). The convenience accessor for the
     * "next page" cursor is {@see SearchResult::$nextCursor} — most
     * code shouldn't read this per-hit.
     *
     * @readonly
     */
    public ?string $cursor;

    /**
     * Number of OTHER documents collapsed under this hit when
     * `groupBy` was set on the search call. `0` when grouping is
     * off, when the group has only one member, or for engines that
     * predate the variant-grouping feature.
     *
     * Storefront UIs typically display this as "+{$n} variante" or
     * "5 mărimi disponibile" on the product card. Add 1 if you need
     * the total member count (representative + siblings).
     *
     * @readonly
     */
    public int $groupedCount;

    /**
     * @param array<string, mixed> $raw The full JSON object for this hit.
     *                                   Expected keys: `id`, `score`,
     *                                   `payload`, optional `cursor`,
     *                                   optional `grouped_count`.
     */
    public function __construct(array $raw)
    {
        $this->id = (string) ($raw['id'] ?? '');
        $this->score = (float) ($raw['score'] ?? 0);
        $payload = $raw['payload'] ?? [];
        $this->document = is_array($payload) ? $payload : [];
        $cursor = $raw['cursor'] ?? null;
        $this->cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;
        // `grouped_count` is omitted on the wire when zero (engine
        // uses `skip_serializing_if = is_zero_u32`). Default to 0
        // here so callers can read the field unconditionally.
        $this->groupedCount = isset($raw['grouped_count'])
            ? (int) $raw['grouped_count']
            : 0;
    }

    /**
     * Read a single document field. Returns $default if the field was not
     * present in the original document — distinguishes "never set" from
     * "set to null" only at the array level via array_key_exists.
     *
     * @param mixed $default
     * @return mixed
     */
    public function get(string $field, $default = null)
    {
        return array_key_exists($field, $this->document)
            ? $this->document[$field]
            : $default;
    }
}
