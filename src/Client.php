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
 *     // Self-hosted / enterprise — point at your own dashboard
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
     *                                      self-hosted installs (no trailing
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
     * @param string                     $index   Slug of the index to query.
     * @param string                     $query   User query; up to 500 chars.
     * @param int|null                   $limit   1–100, default 20.
     * @param int|null                   $offset  0-based pagination, default 0.
     * @param array<string, mixed>|null  $filters Logged but not yet applied in the engine.
     */
    public function search(
        string $index,
        string $query,
        ?int $limit = null,
        ?int $offset = null,
        ?array $filters = null
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

        $data = $this->request('POST', '/api/v1/search', $body);
        return SearchResult::fromArray($data);
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
