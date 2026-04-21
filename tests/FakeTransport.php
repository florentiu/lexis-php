<?php

declare(strict_types=1);

namespace Lexis\Tests;

use Lexis\Http\Response;
use Lexis\Http\Transport;

/**
 * In-memory transport for unit tests. Records every request and returns
 * canned responses in FIFO order. Intentionally dumb — tests that need
 * richer behaviour (conditional responses, retry sequences) push the right
 * chain of responses upfront.
 */
final class FakeTransport implements Transport
{
    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public $calls = [];

    /** @var array<int, Response> */
    private $responses = [];

    /**
     * @param array<string, mixed>|string $body
     * @param array<string, string>       $headers
     */
    public function queue(int $status, $body = [], array $headers = []): void
    {
        $raw = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body;
        if (!isset($headers['content-type'])) {
            $headers['content-type'] = 'application/json';
        }
        $lowerHeaders = [];
        foreach ($headers as $k => $v) {
            $lowerHeaders[strtolower($k)] = $v;
        }
        $this->responses[] = new Response($status, $lowerHeaders, $raw);
    }

    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeoutSeconds
    ): Response {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];
        if ($this->responses === []) {
            throw new \RuntimeException("No response queued for {$method} {$url}");
        }
        return array_shift($this->responses);
    }
}
