<?php

declare(strict_types=1);

namespace Lexis\Exception;

/**
 * Thrown on HTTP 429 — the API key exceeded its per-minute budget (600/min
 * for search, 30/min for sync writes). The retryAfterSeconds value mirrors
 * the server's Retry-After header; the SDK already retries automatically up
 * to the configured max_retries, so you'll only see this exception if the
 * limit keeps tripping after every allowed retry.
 */
final class RateLimitException extends LexisException
{
    /** @var int */
    private $retryAfterSeconds;

    /**
     * @param array<string, mixed>|null $responseBody
     */
    public function __construct(
        string $message,
        int $statusCode,
        int $retryAfterSeconds,
        ?array $responseBody = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $responseBody, $previous);
        $this->retryAfterSeconds = $retryAfterSeconds;
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
