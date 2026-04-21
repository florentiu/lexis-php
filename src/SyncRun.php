<?php

declare(strict_types=1);

namespace Lexis;

use Lexis\Exception\ValidationException;

/**
 * Handle to an in-flight sync run. Returned by {@see Sync::start()}; carries
 * the run id plus the identity of the target index (useful for logging and
 * for validating documents on the client side before sending).
 *
 * Sync runs are a full replace: everything that gets committed is the new
 * index content. Documents that existed before but weren't pushed in this
 * run are deleted on commit.
 */
final class SyncRun
{
    /**
     * Max documents per wire call. The server rejects anything larger with
     * HTTP 400. Larger arrays passed to {@see push()} are chunked below.
     */
    public const BATCH_LIMIT = 1000;

    /** @var Client */
    private $client;

    /** @readonly */
    public string $id;

    /** @readonly */
    public string $indexId;

    /** @readonly */
    public string $indexSlug;

    /** @readonly */
    public string $primaryKey;

    public function __construct(
        Client $client,
        string $id,
        string $indexId,
        string $indexSlug,
        string $primaryKey
    ) {
        $this->client = $client;
        $this->id = $id;
        $this->indexId = $indexId;
        $this->indexSlug = $indexSlug;
        $this->primaryKey = $primaryKey;
    }

    /**
     * Push documents to the run. The API accepts batches of ≤ 1000, so if
     * you pass more the SDK splits into chunks and issues one POST each —
     * on the caller's thread, sequentially, stopping at the first failure.
     *
     * Each document MUST be a keyed array containing the run's primary-key
     * field (accessible as `$run->primaryKey`) with a non-empty value; the
     * SDK validates this client-side so network round-trips aren't wasted
     * on a typo in the data export.
     *
     * @param array<int, array<string, mixed>> $documents
     * @return int Total number of documents accepted across all batches.
     */
    public function push(array $documents): int
    {
        if ($documents === []) {
            return 0;
        }

        $this->assertValid($documents);

        $total = 0;
        foreach (array_chunk($documents, self::BATCH_LIMIT) as $batch) {
            $data = $this->client->request(
                'POST',
                '/api/v1/sync/' . rawurlencode($this->id) . '/documents',
                ['documents' => $batch]
            );
            $total += (int) ($data['received'] ?? 0);
        }
        return $total;
    }

    /**
     * Commit the run — atomically flips the index to the pushed dataset.
     * Returns the committed document count and the number removed (docs
     * that existed pre-run but weren't in this push).
     *
     * @return array{committed: bool, documents: int, deleted: int}
     */
    public function commit(): array
    {
        $data = $this->client->request(
            'POST',
            '/api/v1/sync/' . rawurlencode($this->id) . '/commit'
        );
        return [
            'committed' => (bool) ($data['committed'] ?? true),
            'documents' => (int) ($data['documents'] ?? 0),
            'deleted' => (int) ($data['deleted'] ?? 0),
        ];
    }

    /**
     * Abort the run. The live index is untouched; any documents pushed so
     * far are discarded on the server. Optional reason is stored with the
     * run record for debugging in the sync history.
     *
     * Calling abort() on an already-committed or already-aborted run raises
     * a {@see \Lexis\Exception\ConflictException}.
     */
    public function abort(?string $reason = null): void
    {
        $body = $reason !== null ? ['reason' => $reason] : [];
        $this->client->request(
            'POST',
            '/api/v1/sync/' . rawurlencode($this->id) . '/abort',
            $body
        );
    }

    /**
     * Pre-flight validation — keeps bad payloads from wasting a round trip
     * (and from tripping the sync-write rate limit on pointless 400s).
     *
     * @param array<int, mixed> $documents
     */
    private function assertValid(array $documents): void
    {
        foreach ($documents as $i => $doc) {
            if (!is_array($doc) || self::looksLikeList($doc)) {
                throw new ValidationException(
                    "Document at index {$i} must be an associative array, got "
                    . (is_object($doc) ? get_class($doc) : gettype($doc))
                );
            }
            $pk = isset($doc[$this->primaryKey]) ? $doc[$this->primaryKey] : null;
            if ($pk === null || $pk === '') {
                throw new ValidationException(
                    "Document at index {$i} is missing the primary key "
                    . "\"{$this->primaryKey}\" (or it's empty)."
                );
            }
        }
    }

    /**
     * array_is_list() polyfill — that function landed in PHP 8.1, so we
     * reimplement the check inline: an array is list-like if its keys are
     * the consecutive integers 0..N-1. Empty arrays count as lists too.
     *
     * @param array<int|string, mixed> $arr
     */
    private static function looksLikeList(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        $i = 0;
        foreach ($arr as $k => $_) {
            if ($k !== $i) {
                return false;
            }
            $i++;
        }
        return true;
    }
}
