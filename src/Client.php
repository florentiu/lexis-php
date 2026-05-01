<?php

declare(strict_types=1);

namespace Lexis;

use Lexis\Exception\AuthenticationException;
use Lexis\Exception\ConflictException;
use Lexis\Exception\LexisException;
use Lexis\Exception\NetworkException;
use Lexis\Exception\NotFoundException;
use Lexis\Exception\PlanLimitException;
use Lexis\Exception\RateLimitException;
use Lexis\Exception\ServerException;
use Lexis\Exception\ValidationException;
use Lexis\Http\Response;

/**
 * Main entry point for the Lexis API.
 *
 *     // Managed cloud
 *     $lexis = new \Lexis\Client('lexis_live_xxx');
 *
 *     // Enterprise / enterprise — point at your own dashboard
 *     $lexis = new \Lexis\Client(
 *         'lexis_live_xxx',
 *         'https://search.my-company.internal'
 *     );
 *
 *     // Sync
 *     $run = $lexis->sync->start('products');
 *     $run->push($batch);
 *     $run->commit();
 *
 *     // Search
 *     $result = $lexis->search('products', 'adidași');
 *     foreach ($result->hits as $hit) { ... }
 *
 * All public methods throw {@see \Lexis\Exception\LexisException} (or a
 * subclass) on failure; transient failures (429, 5xx, network blips) are
 * retried automatically before the exception bubbles up.
 */
final class Client
{
    /** @readonly */
    public Config $config;

    /** @readonly */
    public Sync $sync;

    /**
     * Storefront-supplied session id forwarded on every request.
     * Routed via the `X-Lexis-Session-Id` header so the engine can
     * count distinct visitors in zero-results / CTR analytics. Set
     * once on the client (typically right after construction) with
     * {@see setSessionId()}; cleared with `setSessionId(null)`.
     *
     * @var string|null
     */
    private ?string $sessionId = null;

    /**
     * Two call shapes:
     *
     *   1. Quick — pass the API key (and optionally the base URL) directly.
     *      Covers the 95% case including enterprise installs:
     *          new Client('lexis_live_xxx');
     *          new Client('lexis_live_xxx', 'https://search.acme.corp');
     *
     *   2. Advanced — pre-build a {@see Config} when you need to tune
     *      timeouts, retries, user-agent, or inject a custom transport:
     *          new Client(new Config('lexis_live_xxx', 'https://x', 60.0));
     *
     * Mixing the two (passing a Config AND a baseUrl) is rejected so nobody
     * has to wonder which value won.
     *
     * @param string|Config $apiKeyOrConfig API key, or a fully-built Config.
     *                                      (Untyped in the signature because
     *                                      union types are PHP 8+ only.)
     * @param string|null   $baseUrl        Optional base URL for enterprise /
     *                                      enterprise installs (no trailing
     *                                      slash; `/api/v1` is appended per
     *                                      request). Ignored — and rejected —
     *                                      when $apiKeyOrConfig is a Config.
     */
    public function __construct($apiKeyOrConfig, ?string $baseUrl = null)
    {
        if ($apiKeyOrConfig instanceof Config) {
            if ($baseUrl !== null) {
                throw new \InvalidArgumentException(
                    'Pass $baseUrl either on a Config or as the second Client '
                    . 'argument — not both. Build the Config with baseUrl set '
                    . 'instead.'
                );
            }
            $this->config = $apiKeyOrConfig;
        } elseif (is_string($apiKeyOrConfig)) {
            $this->config = new Config(
                $apiKeyOrConfig,
                $baseUrl ?? Config::DEFAULT_BASE_URL
            );
        } else {
            throw new \InvalidArgumentException(
                'First argument must be an API key (string) or a Config instance, got '
                . (is_object($apiKeyOrConfig) ? get_class($apiKeyOrConfig) : gettype($apiKeyOrConfig))
            );
        }
        $this->sync = new Sync($this);
    }

    /**
     * Full-text search against a committed index.
     *
     * Supports two pagination styles:
     *
     *   * **Shallow** — pass `$offset` (0, 20, 40, ...). Cheap up to a few
     *     hundred rows; the engine still has to walk every skipped row.
     *   * **Deep** — pass `$searchAfter` with the previous page's
     *     {@see SearchResult::$nextCursor}. O(page) regardless of depth;
     *     `$offset` is ignored when a cursor is set.
     *
     * Use deep pagination past ~1k results, or for any "walk the whole
     * catalog" loop:
     *
     *     $cursor = null;
     *     do {
     *         $r = $lexis->search('products', '*', 100, 0, null, $cursor);
     *         foreach ($r->hits as $h) { ... }
     *         $cursor = $r->nextCursor;
     *     } while ($cursor !== null);
     *
     * Filters narrow the candidate set BEFORE ranking — they're applied
     * server-side against the engine's tag and numeric indexes (configured
     * at index creation as `tag:` / `numeric:` mappings). Three operator
     * shapes are supported, identifiable by `op`:
     *
     *     // 1) Exact tag match — useful for brand, category, etc.
     *     ['op' => 'tag_eq', 'field' => 'brand', 'value' => 'Nike']
     *
     *     // 2) Any-of tag match — multiple acceptable values for one field.
     *     ['op' => 'tag_in', 'field' => 'category', 'values' => ['boots', 'sneakers']]
     *
     *     // 3) Half-open numeric range — either bound may be omitted.
     *     ['op' => 'numeric_range', 'field' => 'price', 'min' => 100, 'max' => 500]
     *
     * Multiple clauses combine with AND (every clause must match). Pass them
     * as a list under a single `filters` argument:
     *
     *     $r = $lexis->search('products', 'iarnă', 20, 0, [
     *         ['op' => 'tag_eq', 'field' => 'brand', 'value' => 'Timberland'],
     *         ['op' => 'numeric_range', 'field' => 'price', 'min' => 200, 'max' => 600],
     *     ]);
     *
     * Unknown fields in a filter — fields the index wasn't configured to
     * tag/index numerically — return a 400 from the engine. Make sure the
     * index settings list those fields under `tagFields` /
     * `numericFields` before sending filters that reference them.
     *
     * @param string                     $index       Slug of the index to query.
     * @param string                     $query       User query; up to 500 chars.
     * @param int|null                   $limit       1–100, default 20.
     * @param int|null                   $offset      0-based pagination; ignored when `$searchAfter` is set.
     * @param list<array<string, mixed>>|null $filters Filter clauses combined with AND. See above for the operator shapes.
     * @param string|null                $searchAfter `search_after` cursor; consume {@see SearchResult::$nextCursor}.
     */
    public function search(
        string $index,
        string $query,
        ?int $limit = null,
        ?int $offset = null,
        ?array $filters = null,
        ?string $searchAfter = null
    ): SearchResult {
        $body = ['index' => $index, 'q' => $query];
        if ($limit !== null) {
            $body['limit'] = $limit;
        }
        if ($offset !== null) {
            $body['offset'] = $offset;
        }
        if ($filters !== null) {
            $body['filters'] = $filters;
        }
        if ($searchAfter !== null && $searchAfter !== '') {
            // Engine ignores `offset` when `search_after` is set; we
            // still forward both if the caller passed them so server
            // logs reflect the caller's intent.
            $body['search_after'] = $searchAfter;
        }

        $data = $this->request('POST', '/api/v1/search', $body);
        return SearchResult::fromArray($data);
    }

    /**
     * URL parameter the engine reads back to attribute clicks. Single source
     * of truth — both {@see withQid()} and the storefront's request handler
     * read this constant rather than hard-coding "lexis_qid", so renaming it
     * (engine-side) only takes one change here.
     */
    public const ATTRIBUTION_PARAM = 'lexis_qid';

    /**
     * Set (or clear) the storefront-supplied session id forwarded on every
     * request as `X-Lexis-Session-Id`. Lets the engine count distinct
     * visitors in zero-results / CTR analytics — without it the
     * `affectedSessions` and `uniqueSessions` columns are blank.
     *
     * Call this once after instantiation, typically with the framework's
     * native session id:
     *
     *     // PHP native
     *     $lexis->setSessionId(session_id() ?: null);
     *
     *     // Symfony
     *     $lexis->setSessionId($request->getSession()->getId());
     *
     *     // Laravel
     *     $lexis->setSessionId($request->session()->getId());
     *
     * The value is opaque to the engine — pass anything stable across a
     * single visitor's session. Never include PII; the id is hashed for
     * counting, but you control what enters the hash.
     *
     * Pass `null` to stop forwarding.
     */
    public function setSessionId(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /**
     * Currently configured session id, or `null` if none is set.
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Append the per-search `qid` to a result link as a query parameter so
     * the storefront's landing-page request can echo it back to
     * {@see recordClick()}. Idempotent — a URL that already has a
     * `?lexis_qid=...` is overwritten so re-stamping during pagination
     * doesn't accumulate duplicate params.
     *
     *     foreach ($result->hits as $i => $hit) {
     *         $href = $lexis->withQid($hit->get('url'), $result->qid);
     *         // render <a href="$href">...
     *     }
     *
     * No-op when `$qid` is empty (talking to a pre-attribution engine, or
     * `?log=false` was used on the search call) — the storefront link still
     * works, attribution just doesn't fire.
     *
     * @param string $url Absolute or relative product URL.
     * @param string $qid The {@see SearchResult::$qid} from the matching search.
     */
    public function withQid(string $url, string $qid): string
    {
        if ($qid === '' || $url === '') {
            return $url;
        }
        $param = self::ATTRIBUTION_PARAM;
        $separator = strpos($url, '?') === false ? '?' : '&';
        // If the URL already carries `lexis_qid=...`, replace it in place
        // rather than appending a second copy (some referrer chains can
        // forward the original URL and we'd end up with two values).
        $pattern = '/([?&])' . preg_quote($param, '/') . '=[^&]*/';
        if (preg_match($pattern, $url) === 1) {
            $replaced = preg_replace($pattern, '$1' . $param . '=' . urlencode($qid), $url);
            return $replaced ?? $url;
        }
        return $url . $separator . $param . '=' . urlencode($qid);
    }

    /**
     * Record a click against a previous search. Called by the storefront
     * server when a request comes in carrying `?lexis_qid=...` — that
     * proves the visit originated from a Lexis search result. Strictly
     * server-side: there is intentionally no JavaScript counterpart so
     * customers don't have to ship our analytics code to the browser.
     *
     *     // In your product-page controller:
     *     $qid = $_GET[\Lexis\Client::ATTRIBUTION_PARAM] ?? null;
     *     if ($qid) {
     *         $lexis->recordClick(
     *             'products',
     *             (string) $qid,
     *             $product->id,
     *             position: $_GET['lexis_pos'] ?? null,
     *             landingUrl: $_SERVER['REQUEST_URI'] ?? null,
     *         );
     *     }
     *
     * Best-effort: the call returns silently on success and throws the
     * usual {@see LexisException} family on hard failure. Wrap the call
     * in a try/catch if you don't want analytics noise to break the
     * product page render.
     *
     * @param string      $index      Slug of the index the search ran against.
     * @param string      $qid        The qid echoed back from the search response.
     * @param string      $productId  Primary key of the clicked document.
     * @param int|null    $position   1-based rank in the result list (optional).
     * @param string|null $landingUrl Final landing URL for ops debugging (optional).
     */
    public function recordClick(
        string $index,
        string $qid,
        string $productId,
        ?int $position = null,
        ?string $landingUrl = null
    ): void {
        $body = [
            'index' => $index,
            'qid' => $qid,
            'product_id' => $productId,
        ];
        if ($position !== null) {
            $body['position'] = $position;
        }
        if ($landingUrl !== null && $landingUrl !== '') {
            $body['landing_url'] = $landingUrl;
        }
        $this->request('POST', '/api/v1/click', $body);
    }

    /**
     * Pull the click-attribution rollup the engine builds in-memory from
     * search × click events. Returns a typed view ready to render in the
     * dashboard or a self-hosted admin page.
     *
     * Currently lives on the admin tier of the engine
     * (`/v1/admin/orgs/:org_id/analytics/click-attribution`) — pass the
     * org id you got from the dashboard / CLI. The session-style bearer
     * (or an admin-scoped API key) is the same one the rest of the SDK uses.
     *
     * @param string   $orgId      Org id (the dashboard shows this on the org page).
     * @param string|null $indexSlug Optional slug filter — narrow to one index.
     * @param int|null $fromMs     Window start, ms-since-epoch. Defaults to now-90d.
     * @param int|null $toMs       Window end, ms-since-epoch. Defaults to now.
     * @param int|null $limit      Top-N rows in the response (default 50, max 200).
     */
    public function getClickAttribution(
        string $orgId,
        ?string $indexSlug = null,
        ?int $fromMs = null,
        ?int $toMs = null,
        ?int $limit = null
    ): ClickAttribution {
        $query = [];
        if ($indexSlug !== null && $indexSlug !== '') {
            $query['index_slug'] = $indexSlug;
        }
        if ($fromMs !== null) {
            $query['from_ms'] = $fromMs;
        }
        if ($toMs !== null) {
            $query['to_ms'] = $toMs;
        }
        if ($limit !== null) {
            $query['limit'] = $limit;
        }
        $path = '/v1/admin/orgs/' . rawurlencode($orgId) . '/analytics/click-attribution';
        if (!empty($query)) {
            $path .= '?' . http_build_query($query);
        }
        $data = $this->request('GET', $path, null);
        return ClickAttribution::fromArray($data);
    }

    /**
     * Execute a request against the Lexis API. Shared between Client::search
     * and the Sync sub-client; applies retry, status-to-exception mapping,
     * and JSON decoding so every handler above stays trivial.
     *
     * @param array<string, mixed>|null $body Encoded as JSON when non-null.
     * @return array<string, mixed>           Decoded JSON response body.
     *
     * @internal Used by Sync and SyncRun; not part of the public API.
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        $url = rtrim($this->config->baseUrl, '/') . $path;
        $headers = [
            'Authorization' => 'Bearer ' . $this->config->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $this->config->userAgent,
        ];
        // Forward the storefront-supplied session id (if set) on every
        // request. The engine logs it on search and click events so
        // analytics can count distinct visitors. Header-based wiring
        // means handlers don't have to thread it through every call.
        if ($this->sessionId !== null && $this->sessionId !== '') {
            $headers['X-Lexis-Session-Id'] = $this->sessionId;
        }
        $encodedBody = null;
        if ($body !== null) {
            $encodedBody = json_encode(
                $body,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $headers['Content-Type'] = 'application/json';
        }

        $attempt = 0;
        // We'll always try at least once; maxRetries bounds the *additional*
        // attempts on top. So a maxRetries=3 budget means up to 4 calls.
        while (true) {
            try {
                $response = $this->config->transport->request(
                    $method,
                    $url,
                    $headers,
                    $encodedBody,
                    $this->config->timeout
                );
            } catch (NetworkException $e) {
                if ($attempt >= $this->config->maxRetries) {
                    throw $e;
                }
                $this->sleepBackoff($attempt, null);
                $attempt++;
                continue;
            }

            $status = $response->statusCode;
            if ($status >= 200 && $status < 300) {
                $json = $response->json();
                return $json ?? [];
            }

            // Retry on 429 and 5xx; let everything else through.
            $retryable = $status === 429 || $status >= 500;
            if ($retryable && $attempt < $this->config->maxRetries) {
                $retryAfter = $this->parseRetryAfter($response);
                $this->sleepBackoff($attempt, $retryAfter);
                $attempt++;
                continue;
            }

            throw $this->toException($response);
        }
    }

    /**
     * Map an error response to the right exception subclass. Centralised so
     * every call site gets consistent treatment — and so tests of individual
     * endpoints don't have to repeat the mapping logic.
     */
    private function toException(Response $response): LexisException
    {
        $body = null;
        try {
            $body = $response->json();
        } catch (\Throwable $e) {
            // Non-JSON error body (e.g. an upstream gateway HTML page). Fall
            // through to generic handling below — the raw body is still
            // accessible via Response if we ever need it.
        }
        $message = is_array($body) && isset($body['error']) && is_string($body['error'])
            ? $body['error']
            : 'HTTP ' . $response->statusCode;

        $status = $response->statusCode;
        // Explicit if/elseif (instead of match) so the class stays parseable
        // on PHP 7.4 — match is PHP 8.0+.
        if ($status === 400) {
            return new ValidationException($message, 400, $body);
        }
        if ($status === 401) {
            return new AuthenticationException($message, 401, $body);
        }
        if ($status === 402) {
            return new PlanLimitException($message, 402, $body);
        }
        if ($status === 404) {
            return new NotFoundException($message, 404, $body);
        }
        if ($status === 409) {
            return new ConflictException($message, 409, $body);
        }
        if ($status === 429) {
            $retryAfter = $this->parseRetryAfter($response);
            return new RateLimitException(
                $message,
                429,
                $retryAfter !== null ? $retryAfter : 1,
                $body
            );
        }
        if ($status >= 500) {
            return new ServerException($message, $status, $body);
        }
        return new LexisException($message, $status, $body);
    }

    /**
     * Parse the Retry-After header if present. Returns seconds-from-now.
     * Supports both delta-seconds and HTTP-date forms per RFC 7231.
     */
    private function parseRetryAfter(Response $response): ?int
    {
        $raw = $response->header('retry-after');
        if ($raw === null || $raw === '') {
            return null;
        }
        if (ctype_digit($raw)) {
            return max(0, (int) $raw);
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }
        return max(0, $ts - time());
    }

    /**
     * Sleep before retrying. When the server told us exactly how long to
     * wait (Retry-After), respect that; otherwise fall back to exponential
     * backoff with a jitter spread so concurrent clients don't thundering-
     * herd the recovery.
     */
    private function sleepBackoff(int $attempt, ?int $retryAfterSeconds): void
    {
        if ($retryAfterSeconds !== null) {
            usleep($retryAfterSeconds * 1_000_000);
            return;
        }
        $exp = $this->config->retryBaseDelay * (2 ** $attempt);
        $jitter = mt_rand(0, 100) / 1000.0; // 0–100 ms
        $sleep = min(30.0, $exp + $jitter);
        usleep((int) ($sleep * 1_000_000));
    }
}
