<?php

declare(strict_types=1);

namespace Lexis\Http;

/**
 * Immutable HTTP response passed from the transport layer up to the Client.
 * Headers are stored lowercase-keyed because HTTP/2 normalises them that way
 * and callers shouldn't care about the wire-level casing.
 */
final class Response
{
    /** @readonly */
    public int $statusCode;

    /**
     * @var array<string, string> header name (lowercase) → value
     * @readonly
     */
    public array $headers;

    /** @readonly */
    public string $body;

    /**
     * @param array<string, string> $headers header name (lowercase) → value
     */
    public function __construct(int $statusCode, array $headers, string $body)
    {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function header(string $name): ?string
    {
        $key = strtolower($name);
        return isset($this->headers[$key]) ? $this->headers[$key] : null;
    }

    /**
     * Decode the body as JSON. Returns null when the body is empty (some
     * endpoints respond with 204) or when the Content-Type clearly isn't
     * JSON; raises on malformed JSON so bugs don't get silently swallowed.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        if ($this->body === '') {
            return null;
        }
        $contentType = $this->header('content-type');
        if ($contentType !== null && $contentType !== ''
            && stripos($contentType, 'json') === false) {
            // str_contains would be cleaner but it's PHP 8+ only; stripos
            // works everywhere and is case-insensitive, which is actually
            // what we want here ("application/JSON" should still match).
            return null;
        }
        $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : null;
    }
}
