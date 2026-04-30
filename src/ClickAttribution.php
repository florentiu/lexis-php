<?php

declare(strict_types=1);

namespace Lexis;

/**
 * Typed wrapper over the /v1/admin/orgs/:org/analytics/click-attribution
 * response. The engine joins search events × click events on `qid`
 * server-side and returns three slices:
 *
 *   - `kpi` — totals and CTR over the window.
 *   - `topByCtr` — queries sorted by click-through rate desc.
 *   - `zeroClickQueries` — queries that returned hits but never produced
 *     a click. Useful for merchandising — the high-volume zero-click rows
 *     are where a synonym or pin would pay off.
 *
 * Drives the dashboard's `/analytics` "Click attribution" panel; also
 * usable directly from PHP if the customer wants to surface CTR in
 * their own admin tooling.
 */
final class ClickAttribution
{
    /**
     * URL parameter the SDK injects into result links so click attribution
     * is automatic. Always `lexis_qid` today; surfaced as a field so a future
     * rename doesn't silently break integrations that hard-code it.
     *
     * @readonly
     */
    public string $attributionParam;

    /**
     * @var array{clicks: int, searches: int, ctr: float, zeroClickCount: int}
     * @readonly
     */
    public array $kpi;

    /**
     * @var array<int, array{query: string, clicks: int, ctr: float, topProduct: ?string}>
     * @readonly
     */
    public array $topByCtr;

    /**
     * @var array<int, array{query: string, searches: int, lastSeen: string}>
     * @readonly
     */
    public array $zeroClickQueries;

    /**
     * @param array{clicks: int, searches: int, ctr: float, zeroClickCount: int} $kpi
     * @param array<int, array{query: string, clicks: int, ctr: float, topProduct: ?string}> $topByCtr
     * @param array<int, array{query: string, searches: int, lastSeen: string}> $zeroClickQueries
     */
    public function __construct(
        string $attributionParam,
        array $kpi,
        array $topByCtr,
        array $zeroClickQueries
    ) {
        $this->attributionParam = $attributionParam;
        $this->kpi = $kpi;
        $this->topByCtr = $topByCtr;
        $this->zeroClickQueries = $zeroClickQueries;
    }

    /**
     * @param array<string, mixed> $raw Decoded JSON response body.
     */
    public static function fromArray(array $raw): self
    {
        $param = isset($raw['attribution_param']) && is_string($raw['attribution_param'])
            ? $raw['attribution_param']
            : 'lexis_qid';

        $kpiRaw = isset($raw['kpi']) && is_array($raw['kpi']) ? $raw['kpi'] : [];
        $kpi = [
            'clicks' => (int) ($kpiRaw['clicks'] ?? 0),
            'searches' => (int) ($kpiRaw['searches'] ?? 0),
            'ctr' => (float) ($kpiRaw['ctr'] ?? 0.0),
            'zeroClickCount' => (int) ($kpiRaw['zero_click_count'] ?? 0),
        ];

        $topByCtr = [];
        $topRaw = isset($raw['top_by_ctr']) && is_array($raw['top_by_ctr']) ? $raw['top_by_ctr'] : [];
        foreach ($topRaw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $top = isset($row['top_product']) && is_string($row['top_product'])
                ? $row['top_product']
                : null;
            $topByCtr[] = [
                'query' => (string) ($row['query'] ?? ''),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'ctr' => (float) ($row['ctr'] ?? 0.0),
                'topProduct' => $top,
            ];
        }

        $zeroClick = [];
        $zeroRaw = isset($raw['zero_click_queries']) && is_array($raw['zero_click_queries'])
            ? $raw['zero_click_queries']
            : [];
        foreach ($zeroRaw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $zeroClick[] = [
                'query' => (string) ($row['query'] ?? ''),
                'searches' => (int) ($row['searches'] ?? 0),
                'lastSeen' => (string) ($row['last_seen'] ?? ''),
            ];
        }

        return new self($param, $kpi, $topByCtr, $zeroClick);
    }
}
