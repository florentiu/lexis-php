<?php

declare(strict_types=1);

namespace Lexis\Exception;

/**
 * Base class for every error thrown by the Lexis SDK. Catch this if you want
 * a single catch-all; catch the subclasses below for fine-grained handling.
 *
 * The HTTP status is exposed as the exception code. The raw decoded response
 * body (if the server sent JSON) is available via {@see getResponseBody()} —
 * useful for logging the full error envelope without swallowing detail.
 */
class LexisException extends \RuntimeException
{
    /** @var array<string, mixed>|null */
    private $responseBody;

    /**
     * @param array<string, mixed>|null $responseBody
     */
    public function __construct(
        string $message,
        int $statusCode = 0,
        ?array $responseBody = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
        $this->responseBody = $responseBody;
    }

    /** HTTP status code, or 0 for transport-level failures. */
    public function getStatusCode(): int
    {
        return $this->getCode();
    }

    /**
     * Decoded JSON response body (if the API returned JSON), or null for
     * transport errors and non-JSON responses.
     *
     * @return array<string, mixed>|null
     */
    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }
}
