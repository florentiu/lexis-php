<?php

declare(strict_types=1);

namespace Lexis;

/**
 * One click event the storefront posted via `/v1/click`. Returned
 * by {@see Client::getClicksForEvent()} as the per-search drilldown
 * — given a search id (`qid`), the engine returns every click
 * attributed to that exact search, oldest first.
 *
 * Mirrors the engine's `ClickRow` wire shape with snake_case →
 * camelCase translation. {@see $position} and {@see $landingUrl}
 * are nullable because the storefront SDKs treat them as optional
 * — `Client::recordClick()` accepts null for both.
 */
final class Click
{
    /** @readonly */
    public string $id;

    /** @readonly */
    public string $orgId;

    /** @readonly */
    public string $indexSlug;

    /**
     * The search id this click is attributed to. JOIN key against
     * {@see Client::search()}'s `SearchResult::$qid`.
     *
     * @readonly
     */
    public string $qid;

    /** @readonly */
    public string $productId;

    /**
     * The 1-based slot in the result list the user clicked, when
     * the storefront passed it on `recordClick()`. `null` when the
     * storefront didn't track position.
     *
     * @readonly
     */
    public ?int $position;

    /**
     * URL the click took the shopper to, when the storefront
     * captured it on `recordClick()`. Lets dashboards link back
     * out without crawling the customer's site. `null` when the
     * storefront didn't pass one.
     *
     * @readonly
     */
    public ?string $landingUrl;

    /**
     * Engine-side identifier for the API key that recorded the
     * click. `null` for clicks recorded under a session-token
     * principal (operator probes from the dashboard).
     *
     * @readonly
     */
    public ?string $apiKeyId;

    /** @readonly */
    public int $createdAtMs;

    /**
     * Storefront-supplied session identifier. Same shape and
     * intent as `EventRow::session_id` on the search side. `null`
     * when the storefront didn't plumb one through.
     *
     * @readonly
     */
    public ?string $sessionId;

    public function __construct(
        string $id,
        string $orgId,
        string $indexSlug,
        string $qid,
        string $productId,
        ?int $position,
        ?string $landingUrl,
        ?string $apiKeyId,
        int $createdAtMs,
        ?string $sessionId
    ) {
        $this->id = $id;
        $this->orgId = $orgId;
        $this->indexSlug = $indexSlug;
        $this->qid = $qid;
        $this->productId = $productId;
        $this->position = $position;
        $this->landingUrl = $landingUrl;
        $this->apiKeyId = $apiKeyId;
        $this->createdAtMs = $createdAtMs;
        $this->sessionId = $sessionId;
    }

    /**
     * @param array<string, mixed> $raw One element of the engine's
     *                                  `{clicks: [...]}` response array.
     */
    public static function fromArray(array $raw): self
    {
        $position = null;
        if (isset($raw['position']) && is_int($raw['position'])) {
            $position = $raw['position'];
        } elseif (isset($raw['position']) && is_numeric($raw['position'])) {
            $position = (int) $raw['position'];
        }

        $apiKeyId = isset($raw['api_key_id']) && is_string($raw['api_key_id'])
            ? $raw['api_key_id']
            : null;

        $landing = isset($raw['landing_url']) && is_string($raw['landing_url'])
            ? $raw['landing_url']
            : null;

        $session = isset($raw['session_id']) && is_string($raw['session_id'])
            ? $raw['session_id']
            : null;

        return new self(
            (string) ($raw['id'] ?? ''),
            (string) ($raw['org_id'] ?? ''),
            (string) ($raw['index_slug'] ?? ''),
            (string) ($raw['qid'] ?? ''),
            (string) ($raw['product_id'] ?? ''),
            $position,
            $landing,
            $apiKeyId,
            (int) ($raw['created_at_ms'] ?? 0),
            $session
        );
    }
}
