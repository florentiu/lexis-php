<?php

/**
 * End-to-end example: push a small product catalog, then search it.
 *
 *   LEXIS_API_KEY=lexis_live_xxx php examples/sync-and-search.php
 *
 * If your deployment isn't the managed cloud, set LEXIS_BASE_URL too:
 *   LEXIS_BASE_URL=https://search.example.com php examples/sync-and-search.php
 *
 * Runs on PHP 7.4+ — uses positional arguments throughout so the script is
 * parseable on every supported version. On PHP 8.0+ you can substitute
 * named arguments if you prefer.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Lexis\Client;
use Lexis\Exception\LexisException;

$apiKey = getenv('LEXIS_API_KEY');
if (!is_string($apiKey) || $apiKey === '') {
    fwrite(STDERR, "LEXIS_API_KEY env var is required\n");
    exit(1);
}

// LEXIS_BASE_URL is optional; unset = managed cloud, set = enterprise / self-hosted.
$baseUrlEnv = getenv('LEXIS_BASE_URL');
$baseUrl = is_string($baseUrlEnv) && $baseUrlEnv !== '' ? $baseUrlEnv : null;

$lexis = new Client($apiKey, $baseUrl);

try {
    // ---- 1. Start a sync run -------------------------------------------------
    // Sync::start() takes (indexSlug, indexName, primaryKey, source) — the
    // slug is first because it's the most commonly customised field.
    $run = $lexis->sync->start(
        'products',
        'Products (SDK example)'
    );
    printf(
        "Started run %s against index %s (pk=%s)\n",
        $run->id,
        $run->indexSlug,
        $run->primaryKey
    );

    // ---- 2. Push documents ---------------------------------------------------
    $catalog = [
        ['id' => 'sku-1', 'title' => 'Adidași Nike Air',    'price' => 349, 'brand' => 'Nike'],
        ['id' => 'sku-2', 'title' => 'Adidași Puma RS',     'price' => 299, 'brand' => 'Puma'],
        ['id' => 'sku-3', 'title' => 'Ghete Timberland',    'price' => 599, 'brand' => 'Timberland'],
        ['id' => 'sku-4', 'title' => 'Cizme iarnă bărbați', 'price' => 450, 'brand' => 'Geox'],
    ];
    $received = $run->push($catalog);
    printf("Pushed %d documents\n", $received);

    // ---- 3. Commit -----------------------------------------------------------
    $stats = $run->commit();
    printf(
        "Committed: documents=%d, deleted=%d\n",
        $stats['documents'],
        $stats['deleted']
    );

    // ---- 4. Search -----------------------------------------------------------
    $result = $lexis->search('products', 'adidasi', 10);
    printf("\nFound %d hits in %d ms:\n", $result->total, $result->tookMs);
    foreach ($result->hits as $hit) {
        printf(
            "  - %s (score %.2f) %s — %d lei\n",
            $hit->id,
            $hit->score,
            $hit->get('title', '(no title)'),
            $hit->get('price', 0)
        );
    }

    if ($result->suggestion !== null) {
        printf("\nDid you mean: %s\n", $result->suggestion);
    }
} catch (LexisException $e) {
    fprintf(
        STDERR,
        "Lexis API error (HTTP %d): %s\n",
        $e->getStatusCode(),
        $e->getMessage()
    );
    if ($e->getResponseBody() !== null) {
        fprintf(STDERR, "Body: %s\n", json_encode($e->getResponseBody()));
    }
    exit(1);
}
