<?php

declare(strict_types=1);

namespace Lexis;

/**
 * One result row from /search. The server prefixes three synthetic fields on
 * every hit — `_id`, `_pk`, `_score` — and mixes in every document field the
 * caller originally pushed. We lift the synthetic ones to typed accessors
 * and keep the rest available raw.
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
    public string $primaryKey;

    /** @readonly */
    public float $score;

    /**
     * @param array<string, mixed> $raw The full JSON object for this hit,
     *                                   including `_id`, `_pk`, `_score`.
     */
    public function __construct(array $raw)
    {
        $this->id = (string) ($raw['_id'] ?? '');
        $this->primaryKey = (string) ($raw['_pk'] ?? $this->id);
        $this->score = (float) ($raw['_score'] ?? 0);

        // Strip the synthetic fields so $document holds only what the caller
        // actually pushed. Keeps downstream serialisation (e.g. twig, JSON
        // response) free of Lexis internals.
        $doc = $raw;
        unset($doc['_id'], $doc['_pk'], $doc['_score']);
        $this->document = $doc;
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
