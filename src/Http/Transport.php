<?php

declare(strict_types=1);

namespace Lexis\Http;

use Lexis\Exception\NetworkException;

/**
 * Minimal transport contract — one method, one purpose. The default
 * implementation is {@see CurlTransport}; tests swap in a fake, and operators
 * with custom TLS / proxy requirements can plug in their own PSR-18-style
 * adapter without the SDK growing a PSR-18 dependency.
 *
 * Implementations MUST throw NetworkException for transport-level failures
 * (DNS, connect, read timeout). HTTP-level statuses — including 4xx and 5xx —
 * are returned normally; the Client interprets them.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @throws NetworkException
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeoutSeconds
    ): Response;
}
