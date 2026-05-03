<?php

declare(strict_types=1);

namespace Lexis;

/**
 * One bucket inside a facet aggregation: a tag value plus how many
 * documents in the matching set carry that value.
 *
 * Wire shape (per engine `lexis_core::FacetCount`):
 *
 *     { "value": "Cofra", "count": 24 }
 *
 * Buckets arrive sorted (count desc, value asc on ties) and capped
 * at 200 per field — that's the engine's hard limit. The matching
 * set already has all filters applied, so a "brand" facet rendered
 * next to a "category=boots" filter shows brand counts WITHIN
 * boots only.
 *
 * Self-exclusion ("uncheck me to see other values") is the
 * storefront's job, not the engine's. The recommended pattern is:
 * when rendering the facet for field X, re-issue the search WITHOUT
 * the user-applied filter on X — that way the user sees every
 * available bucket on the column they're refining. Costs a second
 * search per facet column but matches what users expect from
 * Amazon / Booking-style filter sidebars.
 */
final class FacetBucket
{
    /** @readonly */
    public string $value;

    /** @readonly */
    public int $count;

    public function __construct(string $value, int $count)
    {
        $this->value = $value;
        $this->count = $count;
    }

    /**
     * @param array<string, mixed> $raw Decoded bucket from the wire.
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            (string) ($raw['value'] ?? ''),
            (int) ($raw['count'] ?? 0)
        );
    }
}
