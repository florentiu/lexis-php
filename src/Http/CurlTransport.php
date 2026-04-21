<?php

declare(strict_types=1);

namespace Lexis\Http;

use Lexis\Exception\NetworkException;

/**
 * Default transport — plain ext-curl, no Guzzle, no PSR-18 indirection. Kept
 * deliberately small: we only need POST with a JSON body and a few headers.
 *
 * The connect timeout is half the read timeout by default — a hung handshake
 * should surface faster than a server that accepted the TCP but is slow to
 * answer. Both are capped at the caller's timeoutSeconds so the whole
 * request can't exceed that budget.
 */
final class CurlTransport implements Transport
{
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeoutSeconds
    ): Response {
        $ch = curl_init();
        if ($ch === false) {
            throw new NetworkException('Failed to initialise cURL handle');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        /** @var array<string, string> $respHeaders */
        $respHeaders = [];
        // Using a regular closure (not arrow fn) because we capture by
        // reference — arrow functions only capture by value. Kept standalone
        // so the signature works on PHP 7.4 through 8.x without change.
        $headerFn = function ($_ch, string $line) use (&$respHeaders) {
            $len = strlen($line);
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, ':') === false) {
                return $len;
            }
            list($name, $value) = explode(':', $trimmed, 2);
            $respHeaders[strtolower(trim($name))] = trim($value);
            return $len;
        };

        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => $headerFn,
            // Timeouts are in whole seconds for CURLOPT_TIMEOUT; we use the
            // _MS variants so the caller can pass sub-second budgets for
            // tests without the whole thing snapping to 0.
            CURLOPT_TIMEOUT_MS => (int) ceil($timeoutSeconds * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil(max(1.0, $timeoutSeconds / 2) * 1000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Keep-alive can reuse connections within a single script run —
            // the sync flow does ≥3 round-trips to the same host, so this
            // shaves a TLS handshake off each one.
            CURLOPT_TCP_KEEPALIVE => 1,
        ];

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $errno = curl_errno($ch);
            $msg = curl_error($ch);
            curl_close($ch);
            throw new NetworkException(
                "HTTP transport failed: {$msg} (curl errno {$errno})",
                0
            );
        }

        /** @var string $raw */
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new Response($status, $respHeaders, $raw);
    }
}
