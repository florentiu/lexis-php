<?php

declare(strict_types=1);

namespace Lexis\Tests;

use Lexis\Client;
use Lexis\Config;
use Lexis\Exception\AuthenticationException;
use Lexis\Exception\ConflictException;
use Lexis\Exception\NotFoundException;
use Lexis\Exception\PlanLimitException;
use Lexis\Exception\RateLimitException;
use Lexis\Exception\ServerException;
use Lexis\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

// Tests are written using positional arguments only (no named args) so the
// test suite itself runs on PHP 7.4+, matching the SDK's supported range.
// On 8.x you could substitute named args; the behaviour is identical.
final class ClientTest extends TestCase
{
    /** @var FakeTransport */
    private $transport;

    /** @var Client */
    private $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        // Config positional args: apiKey, baseUrl, timeout, maxRetries,
        // retryBaseDelay, transport, userAgent. Zero retry delay so retry
        // tests don't actually sleep.
        $this->client = new Client(new Config(
            'lexis_test_key',
            'https://lexis.test',
            5.0,
            2,
            0.0,
            $this->transport
        ));
    }

    public function testSearchReturnsTypedResult(): void
    {
        $this->transport->queue(200, [
            'hits' => [
                ['_id' => 'sku-1', '_pk' => 'sku-1', '_score' => 4.2, 'title' => 'Nike Air', 'price' => 349],
                ['_id' => 'sku-2', '_pk' => 'sku-2', '_score' => 3.9, 'title' => 'Puma RS',  'price' => 299],
            ],
            'total' => 2,
            'limit' => 20,
            'offset' => 0,
            'took_ms' => 12,
            'query' => 'adidași',
            'expanded_terms' => ['adidas'],
        ]);

        $result = $this->client->search('products', 'adidași');

        $this->assertSame(2, $result->total);
        $this->assertSame(12, $result->tookMs);
        $this->assertCount(2, $result->hits);
        $this->assertSame('sku-1', $result->hits[0]->id);
        $this->assertSame(4.2, $result->hits[0]->score);
        $this->assertSame('Nike Air', $result->hits[0]->get('title'));
        $this->assertArrayNotHasKey('_id', $result->hits[0]->document);
    }

    public function testSearchSendsBearerToken(): void
    {
        $this->transport->queue(200, [
            'hits' => [], 'total' => 0, 'limit' => 20, 'offset' => 0,
            'took_ms' => 1, 'query' => '', 'expanded_terms' => [],
        ]);
        $this->client->search('products', 'x');

        $call = $this->transport->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://lexis.test/api/v1/search', $call['url']);
        $this->assertSame('Bearer lexis_test_key', $call['headers']['Authorization']);
        $this->assertSame('application/json', $call['headers']['Content-Type']);
        $this->assertStringContainsString('"index":"products"', $call['body']);
    }

    public function testSyncStartReturnsHandle(): void
    {
        $this->transport->queue(200, [
            'sync_run_id' => 'run-abc',
            'index_id' => 'idx-123',
            'index_slug' => 'products',
            'primary_key' => 'id',
        ]);

        $run = $this->client->sync->start('products');

        $this->assertSame('run-abc', $run->id);
        $this->assertSame('products', $run->indexSlug);
        $this->assertSame('id', $run->primaryKey);
    }

    public function testPushChunksLargeBatches(): void
    {
        $this->transport->queue(200, [
            'sync_run_id' => 'run-1',
            'index_id' => 'idx-1',
            'index_slug' => 'products',
            'primary_key' => 'id',
        ]);
        // Two documents responses — the batch is 2500 docs, chunked 1000/1000/500.
        $this->transport->queue(200, ['received' => 1000]);
        $this->transport->queue(200, ['received' => 1000]);
        $this->transport->queue(200, ['received' => 500]);

        $run = $this->client->sync->start('products');

        $docs = [];
        for ($i = 0; $i < 2500; $i++) {
            $docs[] = ['id' => "sku-{$i}", 'title' => "Product {$i}"];
        }
        $total = $run->push($docs);

        $this->assertSame(2500, $total);
        // 1 start call + 3 push calls.
        $this->assertCount(4, $this->transport->calls);
        $this->assertStringContainsString('/sync/run-1/documents', $this->transport->calls[1]['url']);
    }

    public function testPushRejectsDocumentMissingPrimaryKey(): void
    {
        $this->transport->queue(200, [
            'sync_run_id' => 'run-1',
            'index_id' => 'idx-1',
            'index_slug' => 'products',
            'primary_key' => 'id',
        ]);
        $run = $this->client->sync->start('products');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/missing the primary key "id"/');
        $run->push([['title' => 'nope']]);
    }

    public function testCommitReturnsStats(): void
    {
        $this->transport->queue(200, [
            'sync_run_id' => 'run-1',
            'index_id' => 'idx-1',
            'index_slug' => 'products',
            'primary_key' => 'id',
        ]);
        $this->transport->queue(200, ['committed' => true, 'documents' => 42, 'deleted' => 3]);

        $run = $this->client->sync->start('products');
        $stats = $run->commit();

        $this->assertTrue($stats['committed']);
        $this->assertSame(42, $stats['documents']);
        $this->assertSame(3, $stats['deleted']);
    }

    public function testRateLimitRetriesThenSucceeds(): void
    {
        $this->transport->queue(429, ['error' => 'slow down'], ['retry-after' => '0']);
        $this->transport->queue(200, [
            'hits' => [], 'total' => 0, 'limit' => 20, 'offset' => 0,
            'took_ms' => 1, 'query' => '', 'expanded_terms' => [],
        ]);

        $result = $this->client->search('products', 'x');

        $this->assertSame(0, $result->total);
        $this->assertCount(2, $this->transport->calls);
    }

    public function testRateLimitSurfacesAfterBudget(): void
    {
        // maxRetries=2 means up to 3 total attempts.
        $this->transport->queue(429, ['error' => 'slow down'], ['retry-after' => '0']);
        $this->transport->queue(429, ['error' => 'slow down'], ['retry-after' => '0']);
        $this->transport->queue(429, ['error' => 'slow down'], ['retry-after' => '0']);

        $this->expectException(RateLimitException::class);
        $this->client->search('products', 'x');
    }

    public function testStatusMappingsThrowTypedExceptions(): void
    {
        $cases = [
            [400, ValidationException::class],
            [401, AuthenticationException::class],
            [402, PlanLimitException::class],
            [404, NotFoundException::class],
            [409, ConflictException::class],
        ];
        foreach ($cases as $case) {
            list($status, $class) = $case;
            $fresh = new FakeTransport();
            $fresh->queue($status, ['error' => 'boom']);
            $client = new Client(new Config(
                'k',
                'https://lexis.test',
                5.0,
                0,      // no retries
                0.0,
                $fresh
            ));
            try {
                $client->search('x', 'y');
                $this->fail("Expected {$class} for HTTP {$status}");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($class, $e, "HTTP {$status}");
                $this->assertSame($status, $e->getCode());
                $this->assertSame('boom', $e->getMessage());
            }
        }
    }

    public function testServerErrorRetriesThenSurfaces(): void
    {
        $this->transport->queue(500, ['error' => 'internal']);
        $this->transport->queue(500, ['error' => 'internal']);
        $this->transport->queue(500, ['error' => 'internal']);

        $this->expectException(ServerException::class);
        $this->client->search('products', 'x');
    }

    public function testEnterpriseBaseUrlViaShortConstructor(): void
    {
        // Self-hosted flow: pass baseUrl as the second constructor arg, no
        // need to import Config at all. Every request must go to the custom
        // host — never to the default cloud URL.
        $client = new Client('lexis_live_xxx', 'https://search.acme.corp');
        $this->assertSame('https://search.acme.corp', $client->config->baseUrl);

        // Verify requests actually go there by building a second client with
        // a Config that carries the same URL plus our FakeTransport. Also
        // exercises the "custom Config" constructor path.
        $fake = new FakeTransport();
        $fake->queue(200, [
            'hits' => [], 'total' => 0, 'limit' => 20, 'offset' => 0,
            'took_ms' => 1, 'query' => '', 'expanded_terms' => [],
        ]);
        $enterprise = new Client(new Config(
            'lexis_live_xxx',
            'https://search.acme.corp',
            30.0,
            0,
            0.0,
            $fake
        ));
        $enterprise->search('products', 'x');

        $this->assertSame(
            'https://search.acme.corp/api/v1/search',
            $fake->calls[0]['url']
        );
    }

    public function testRejectsBaseUrlMixedWithConfig(): void
    {
        // Ambiguity guard — if you're building a Config you own every knob;
        // passing baseUrl alongside would silently override (or be ignored)
        // depending on ordering. Better to fail loudly.
        $this->expectException(\InvalidArgumentException::class);
        new Client(
            new Config('k', 'https://a.example'),
            'https://b.example'
        );
    }
}
