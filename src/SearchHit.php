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
 *       "cursor": "eyJvZmZzZXQiOjksImxhc3RfaWQiOiIxMDQ4MCJ9"
 *     }
 *
 *   * `id` is the document's primary key as a string (regardless of whether
 *     the field was numeric or string in the source — the engine canonicalizes).
 *   * `score` is BM25 (or the RRF-fused score on hybrid runs).
 *   * `payload` is everything the caller originally pushed; we expose it as
 *     `$hit->document` and as a typed accessor `$hit->get('field')`.
 *   * `cursor` is an opaque base64 token used for `search_after` deep
 *     pagination — only present on hits where pagination can resume from
 *     this row. The last row of the last page has no cursor.
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
     * @param array<string, mixed> $raw The full JSON object for this hit.
     *                                   Expected keys: `id`, `score`,
     *                                   `payload`, optional `cursor`.
     */
    public function __construct(array $raw)
    {
        $this->id = (string) ($raw['id'] ?? '');
        $this->score = (float) ($raw['score'] ?? 0);
        $payload = $raw['payload'] ?? [];
        $this->document = is_array($payload) ? $payload : [];
        $cursor = $raw['cursor'] ?? null;
        $this->cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;
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
