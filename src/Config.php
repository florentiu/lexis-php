<?php

declare(strict_types=1);

namespace Lexis;

use Lexis\Http\CurlTransport;
use Lexis\Http\Transport;

/**
 * Immutable client configuration. Instantiate once and pass to {@see Client}.
 *
 * Defaults target the managed cloud (`https://lexis.florentiu.me`). Self-hosted
 * deployments override baseUrl to point at their dashboard's hostname — the
 * API contract is identical across editions.
 *
 * Properties are declared individually (no constructor promotion) so the
 * class runs on PHP 7.4 as well as 8.x. Treat them as read-only — the SDK
 * doesn't mutate them and neither should you.
 */
final class Config
{
    public const DEFAULT_BASE_URL = 'https://lexis.florentiu.me';
    public const DEFAULT_USER_AGENT = 'lexis-php/0.1.0';

    /** @readonly */
    public string $apiKey;

    /** @readonly */
    public string $baseUrl;

    /** @readonly */
    public float $timeout;

    /** @readonly */
    public int $maxRetries;

    /** @readonly */
    public float $retryBaseDelay;

    /** @readonly */
    public Transport $transport;

    /** @readonly */
    public string $userAgent;

    /**
     * @param string         $apiKey          Bearer key from Settings → API keys.
     * @param string         $baseUrl         No trailing slash; /api/v1 is appended per request.
     * @param float          $timeout         Per-request budget in seconds (connect + read).
     * @param int            $maxRetries      Retries on 429 and 5xx. Set to 0 to disable.
     * @param float          $retryBaseDelay  Starting delay for exponential backoff (seconds).
     * @param Transport|null $transport       Inject a custom transport for testing or proxies.
     * @param string         $userAgent       Sent as User-Agent header; helps ops correlate logs.
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = 30.0,
        int $maxRetries = 3,
        float $retryBaseDelay = 0.5,
        ?Transport $transport = null,
        string $userAgent = self::DEFAULT_USER_AGENT
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Lexis API key must not be empty');
        }
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('timeout must be > 0');
        }
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries must be >= 0');
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
        $this->retryBaseDelay = $retryBaseDelay;
        $this->transport = $transport ?? new CurlTransport();
        $this->userAgent = $userAgent;
    }
}
