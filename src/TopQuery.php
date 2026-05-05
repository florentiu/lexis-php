<?php

declare(strict_types=1);

namespace Lexis;

/**
 * One row of the `/v1/admin/orgs/:org/analytics/top-queries` response.
 *
 * Two complementary readings of the same event log:
 *
 *   - {@see $searches} counts every `/v1/search` call that landed
 *     against this query. Inflated by storefront back-button
 *     re-fetches and pagination — useful as an engine-load signal,
 *     misleading as an intent metric (one shopper hitting back twice
 *     surfaces as 3 events).
 *   - {@see $uniqueSearches} collapses events into
 *     `(session_proxy, hour_bucket)` tuples server-side so the same
 *     shopper looking at the same query within the same hour counts
 *     once. The "people-searched-this" metric the dashboard
 *     headlines ahead of `searches`.
 *
 * Session proxy fallback chain: storefront-supplied `session_id` →
 * client `ip_address` → per-event fallback so anonymous traffic
 * without either signal degrades to raw counting (one unique per
 * event), not all-collapse-into-one-bucket.
 */
final class TopQuery
{
    /** @readonly */
    public string $query;

    /**
     * Raw event count — every `/v1/search` call counts.
     *
     * @readonly
     */
    public int $searches;

    /**
     * Dedup'd by `(session_proxy, hour_bucket)`. Strictly ≤
     * {@see $searches}, equal when there's no back-button traffic.
     *
     * @readonly
     */
    public int $uniqueSearches;

    /** @readonly */
    public int $zeroResultSearches;

    /** @readonly */
    public int $avgLatencyMs;

    /**
     * Last time this query showed up in the event log, ms-since-epoch.
     *
     * @readonly
     */
    public int $lastSeenMs;

    public function __construct(
        string $query,
        int $searches,
        int $uniqueSearches,
        int $zeroResultSearches,
        int $avgLatencyMs,
        int $lastSeenMs
    ) {
        $this->query = $query;
        $this->searches = $searches;
        $this->uniqueSearches = $uniqueSearches;
        $this->zeroResultSearches = $zeroResultSearches;
        $this->avgLatencyMs = $avgLatencyMs;
        $this->lastSeenMs = $lastSeenMs;
    }

    /**
     * @param array<string, mixed> $raw One element of the engine's
     *                                  `{queries: [...]}` response array.
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            (string) ($raw['query'] ?? ''),
            (int) ($raw['searches'] ?? 0),
            // Older engines (pre-0.7.9) won't return this field; default
            // to `searches` so callers comparing the two see equality
            // ("no dedup applied") instead of a phantom zero.
            (int) ($raw['unique_searches'] ?? ($raw['searches'] ?? 0)),
            (int) ($raw['zero_result_searches'] ?? 0),
            (int) ($raw['avg_latency_ms'] ?? 0),
            (int) ($raw['last_seen_ms'] ?? 0)
        );
    }
}
