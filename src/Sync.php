<?php

declare(strict_types=1);

namespace Lexis;

/**
 * Namespace for sync-flow operations. Accessed via `$client->sync` — kept
 * as a sub-client rather than methods on Client itself so related calls
 * cluster together in autocomplete (`$client->sync->...`).
 *
 * The sync flow is three endpoints:
 *
 *   1. start()   → returns a {@see SyncRun} handle
 *   2. push()    → called on the handle, repeatable, batches of ≤ 1000
 *   3. commit()  → on the handle, flips the index live
 *
 * Or abort() if something goes wrong mid-sync. Pending runs that are
 * neither committed nor aborted expire server-side after ~15 minutes.
 */
final class Sync
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Begin a fresh sync run. The returned handle carries the run id and
     * the primary-key field the server will enforce on each document.
     *
     * Args are ordered by how often they're customised in practice — slug
     * first (varies per install), then name, then primary key, then source
     * (which almost always stays at its default). That keeps positional
     * calls readable on PHP 7.4; on PHP 8+ you can use named arguments and
     * the order doesn't matter.
     *
     * @param string|null $indexSlug  Lowercase slug. Auto-provisioned on first use.
     *                                 Defaults: custom→"default", opencart/woo→"products".
     * @param string|null $indexName  Human-readable name shown in the dashboard.
     * @param string|null $primaryKey Field on each document that uniquely
     *                                 identifies it. Fixed at index-creation
     *                                 time; ignored on subsequent runs against
     *                                 an existing index.
     * @param string|null $source     "custom" (default), "opencart", "woocommerce".
     *                                 Affects only the default index slug/name/pk
     *                                 if you don't pass them explicitly.
     */
    public function start(
        ?string $indexSlug = null,
        ?string $indexName = null,
        ?string $primaryKey = null,
        ?string $source = null
    ): SyncRun {
        $body = [];
        if ($source !== null) {
            $body['source'] = $source;
        }
        if ($indexSlug !== null) {
            $body['index_slug'] = $indexSlug;
        }
        if ($indexName !== null) {
            $body['index_name'] = $indexName;
        }
        if ($primaryKey !== null) {
            $body['primary_key'] = $primaryKey;
        }

        $data = $this->client->request('POST', '/api/v1/sync/start', $body);

        return new SyncRun(
            $this->client,
            (string) ($data['sync_run_id'] ?? ''),
            (string) ($data['index_id'] ?? ''),
            (string) ($data['index_slug'] ?? ''),
            (string) ($data['primary_key'] ?? 'id')
        );
    }
}
