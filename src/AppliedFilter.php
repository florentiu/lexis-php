<?php

declare(strict_types=1);

namespace Lexis;

/**
 * One filter the engine applied IMPLICITLY to the query because
 * `autoFacet: true` was passed and a query token matched a known
 * tag value.
 *
 * Wire shape (per engine `lexis_core::AppliedFilter`):
 *
 *     { "field": "culoare", "value": "Portocaliu" }
 *
 * Storefront UX: render these as pre-checked chips in the filter
 * sidebar so the operator can see what the engine inferred from
 * "tricou portocaliu" (`culoare:Portocaliu`) and un-toggle it if
 * the detection was over-eager. Each entry is the same shape the
 * caller would have produced as a manual `tag_eq` filter — round-
 * tripping it through `filters` on the next request is the
 * canonical "promote auto to manual" gesture.
 *
 * Empty list when `autoFacet` was off OR no token matched.
 */
final class AppliedFilter
{
    /** @readonly */
    public string $field;

    /** @readonly */
    public string $value;

    public function __construct(string $field, string $value)
    {
        $this->field = $field;
        $this->value = $value;
    }

    /**
     * @param array<string, mixed> $raw Decoded entry from the wire.
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            (string) ($raw['field'] ?? ''),
            (string) ($raw['value'] ?? '')
        );
    }

    /**
     * Convert the auto-applied filter to a `tag_eq` filter clause —
     * lets the storefront promote it to an explicit user-controlled
     * filter in the next request without losing fidelity.
     *
     *     $filters = [...$userFilters];
     *     foreach ($result->autoFilters as $auto) {
     *         $filters[] = $auto->toTagEqClause();
     *     }
     *
     * @return array{op: string, field: string, value: string}
     */
    public function toTagEqClause(): array
    {
        return [
            'op' => 'tag_eq',
            'field' => $this->field,
            'value' => $this->value,
        ];
    }
}
