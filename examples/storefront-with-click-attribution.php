<?php

/**
 * Storefront-side click attribution — the server-side flow.
 *
 * Lexis ships click attribution as a strictly server-side feature: the
 * engine mints an opaque `qid` on every search, the SDK stamps it onto
 * result link URLs, and the storefront's request handler echoes it back
 * via {@see \Lexis\Client::recordClick()} when the customer's browser
 * navigates to the product page. There is no JavaScript involved on the
 * customer's side — you don't have to ship analytics code to the browser.
 *
 * This example demonstrates the three touch points end-to-end:
 *
 *   1. SEARCH PAGE — call $lexis->search(...) and stamp `?lexis_qid=...`
 *      onto every result link via $lexis->withQid($url, $result->qid).
 *   2. PRODUCT PAGE — when a request lands carrying `?lexis_qid=...`,
 *      call $lexis->recordClick(...) (best-effort, swallow errors).
 *   3. ADMIN DASHBOARD — pull the rolled-up CTR / top-by-CTR /
 *      zero-click report via $lexis->getClickAttribution($orgId, ...).
 *
 * Run with:
 *
 *   LEXIS_API_KEY=lexis_live_xxx php examples/storefront-with-click-attribution.php
 *
 * For a real storefront, the three blocks below would live in different
 * controllers — but they share the same `Lexis\Client` instance, so the
 * Bearer API key is set once.
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

$baseUrlEnv = getenv('LEXIS_BASE_URL');
$baseUrl = is_string($baseUrlEnv) && $baseUrlEnv !== '' ? $baseUrlEnv : null;

$lexis = new Client($apiKey, $baseUrl);

// =============================================================================
// 1. SEARCH PAGE — stamp qid onto every result link.
// =============================================================================
//
// In your search controller (e.g. /search?q=...), call $lexis->search()
// and grab `$result->qid` — the per-search opaque id the engine minted.
// Then, when rendering each result, build the link href via withQid()
// instead of using the raw URL.
//
// The qid is round-tripped to the engine on click — no need to
// persist anything yourself.
echo "── Search page ───────────────────────────────────────\n";
try {
    $result = $lexis->search('products', 'adidasi', 5);
    printf("Search qid: %s\n", $result->qid !== '' ? $result->qid : '(none)');
    printf("Found %d hits.\n\n", $result->total);

    foreach ($result->hits as $i => $hit) {
        $rawUrl = sprintf('https://shop.example.ro/p/%s', $hit->id);
        // ↓ The actual integration point: stamp the qid onto the URL.
        $href = $lexis->withQid($rawUrl, $result->qid);
        // Optional: also pass `lexis_pos` so recordClick() can report
        // the rank that was clicked. Not required, but it lights up
        // the position-bias slice of the analytics rollup.
        $href .= '&lexis_pos=' . ($i + 1);
        printf("  <a href=\"%s\">%s</a>\n", $href, $hit->get('title', $hit->id));
    }
} catch (LexisException $e) {
    fprintf(STDERR, "search failed (HTTP %d): %s\n", $e->getStatusCode(), $e->getMessage());
}

// =============================================================================
// 2. PRODUCT PAGE — record the click when ?lexis_qid lands.
// =============================================================================
//
// In your product-page controller, check whether the incoming request
// carries the attribution param. If it does, post a click beacon —
// best-effort, so analytics noise never breaks the page render.
//
// In a real Symfony / Laravel / vanilla-PHP controller you'd read
// $request->query->get(...) or $_GET[...]; here we simulate a request
// that already carries the qid we minted above.
echo "\n── Product page ──────────────────────────────────────\n";
$simulatedQuery = [
    Client::ATTRIBUTION_PARAM => $result->qid,
    'lexis_pos' => '1',
];
$simulatedQid = $simulatedQuery[Client::ATTRIBUTION_PARAM] ?? null;
$simulatedPos = isset($simulatedQuery['lexis_pos'])
    ? (int) $simulatedQuery['lexis_pos']
    : null;
$simulatedProductId = $result->hits[0]->id ?? 'sku-1';

if (is_string($simulatedQid) && $simulatedQid !== '' && count($result->hits) > 0) {
    // Best-effort wrap — a failure here mustn't break the product page.
    try {
        $lexis->recordClick(
            'products',
            $simulatedQid,
            $simulatedProductId,
            $simulatedPos,
            'https://shop.example.ro/p/' . $simulatedProductId
                . '?' . Client::ATTRIBUTION_PARAM . '=' . $simulatedQid
        );
        printf(
            "Recorded click: product=%s qid=%s position=%d\n",
            $simulatedProductId,
            $simulatedQid,
            $simulatedPos
        );
    } catch (LexisException $e) {
        // Log but swallow — see docblock on recordClick().
        fprintf(
            STDERR,
            "click telemetry failed (HTTP %d): %s\n",
            $e->getStatusCode(),
            $e->getMessage()
        );
    }
}

// =============================================================================
// 3. ADMIN DASHBOARD — pull the click-attribution rollup.
// =============================================================================
//
// On any admin page (or a nightly cron) you can read the same data
// the Lexis dashboard renders for /analytics. The endpoint joins
// search × click events on `qid` server-side; this PHP call is a
// 1:1 typed wrapper.
//
// $orgId is what the dashboard shows on your org page (or the value
// the CLI prints when listing orgs).
echo "\n── Admin dashboard ───────────────────────────────────\n";
$orgId = getenv('LEXIS_ORG_ID');
if (is_string($orgId) && $orgId !== '') {
    try {
        $rep = $lexis->getClickAttribution($orgId, 'products');
        printf(
            "KPI: %d clicks, %d searches, %s CTR, %d zero-click queries.\n",
            $rep->kpi['clicks'],
            $rep->kpi['searches'],
            number_format($rep->kpi['ctr'] * 100, 1) . '%',
            $rep->kpi['zeroClickCount']
        );
        if (!empty($rep->topByCtr)) {
            echo "Top by CTR:\n";
            foreach (array_slice($rep->topByCtr, 0, 3) as $row) {
                printf(
                    "  - %s — %d clicks, %s CTR%s\n",
                    $row['query'],
                    $row['clicks'],
                    number_format($row['ctr'] * 100, 1) . '%',
                    $row['topProduct'] ? " → {$row['topProduct']}" : ''
                );
            }
        }
    } catch (LexisException $e) {
        fprintf(
            STDERR,
            "click attribution rollup failed (HTTP %d): %s\n",
            $e->getStatusCode(),
            $e->getMessage()
        );
    }
} else {
    echo "Set LEXIS_ORG_ID to see the rollup against your org.\n";
}
