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
        // Enterprise flow: pass baseUrl as the second constructor arg, no
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

    public function testSearchExposesQidWhenEnginePresentsIt(): void
    {
        // Engines on click-attribution stamp every response with `qid`;
        // the SDK round-trips it as a typed field on SearchResult so the
        // storefront can hand it to withQid()/recordClick() without
        // touching raw arrays.
        $this->transport->queue(200, [
            'hits' => [],
            'total' => 0,
            'limit' => 20,
            'offset' => 0,
            'took_ms' => 1,
            'query' => 'x',
            'expanded_terms' => [],
            'qid' => 'q_abc12345',
        ]);

        $result = $this->client->search('products', 'x');
        $this->assertSame('q_abc12345', $result->qid);
    }

    public function testSearchQidIsEmptyWhenEngineOmitsIt(): void
    {
        // Older engines (or `?log=false`) won't carry qid. The field
        // must still be populated as an empty string so consumers can
        // skip click-attribution wiring with one cheap check rather
        // than a null guard.
        $this->transport->queue(200, [
            'hits' => [], 'total' => 0, 'limit' => 20, 'offset' => 0,
            'took_ms' => 1, 'query' => '', 'expanded_terms' => [],
        ]);

        $result = $this->client->search('products', 'x');
        $this->assertSame('', $result->qid);
    }

    public function testWithQidAppendsParamToBareUrl(): void
    {
        $url = $this->client->withQid('https://shop.example/p/A', 'q_abc12345');
        $this->assertSame('https://shop.example/p/A?lexis_qid=q_abc12345', $url);
    }

    public function testWithQidUsesAmpersandWhenQueryAlreadyPresent(): void
    {
        $url = $this->client->withQid('https://shop.example/p/A?ref=email', 'q_abc12345');
        $this->assertSame(
            'https://shop.example/p/A?ref=email&lexis_qid=q_abc12345',
            $url
        );
    }

    public function testWithQidReplacesExistingQid(): void
    {
        // Re-stamping during pagination shouldn't double-encode — replace
        // the existing param in-place rather than appending a duplicate.
        $url = $this->client->withQid(
            'https://shop.example/p/A?lexis_qid=old_value&ref=email',
            'q_new_one'
        );
        $this->assertSame(
            'https://shop.example/p/A?lexis_qid=q_new_one&ref=email',
            $url
        );
    }

    public function testWithQidIsNoOpWhenQidEmpty(): void
    {
        // Pre-attribution engines / `?log=false` callers ship empty qids.
        // The link must still work — leave the URL alone.
        $url = $this->client->withQid('https://shop.example/p/A', '');
        $this->assertSame('https://shop.example/p/A', $url);
    }

    public function testRecordClickPostsExpectedPayload(): void
    {
        $this->transport->queue(200, [
            'id' => 'click-1',
            'qid' => 'q_abc12345',
            'product_id' => 'A',
            'created_at_ms' => 1_700_000_000_000,
        ]);

        $this->client->recordClick(
            'products',
            'q_abc12345',
            'A',
            3,
            'https://shop.example/p/A?lexis_qid=q_abc12345'
        );

        $call = $this->transport->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://lexis.test/api/v1/click', $call['url']);
        $this->assertSame('Bearer lexis_test_key', $call['headers']['Authorization']);
        $body = json_decode($call['body'], true);
        $this->assertSame('products', $body['index']);
        $this->assertSame('q_abc12345', $body['qid']);
        $this->assertSame('A', $body['product_id']);
        $this->assertSame(3, $body['position']);
        $this->assertSame(
            'https://shop.example/p/A?lexis_qid=q_abc12345',
            $body['landing_url']
        );
    }

    public function testRecordClickOmitsOptionalFieldsWhenNotProvided(): void
    {
        // Position + landing_url are optional context; the engine doesn't
        // require them. The SDK shouldn't ship `null` either — that would
        // fail the engine's serde validation. Drop the keys entirely.
        $this->transport->queue(200, [
            'id' => 'click-2',
            'qid' => 'q_abc12345',
            'product_id' => 'A',
            'created_at_ms' => 1_700_000_000_000,
        ]);

        $this->client->recordClick('products', 'q_abc12345', 'A');

        $body = json_decode($this->transport->calls[0]['body'], true);
        $this->assertArrayNotHasKey('position', $body);
        $this->assertArrayNotHasKey('landing_url', $body);
    }

    public function testGetClickAttributionDecodesResponse(): void
    {
        $this->transport->queue(200, [
            'attribution_param' => 'lexis_qid',
            'kpi' => [
                'clicks' => 12,
                'searches' => 30,
                'ctr' => 0.4,
                'zero_click_count' => 5,
            ],
            'top_by_ctr' => [
                ['query' => 'shoes', 'clicks' => 9, 'ctr' => 0.75, 'top_product' => 'A'],
                ['query' => 'boots', 'clicks' => 3, 'ctr' => 0.25, 'top_product' => null],
            ],
            'zero_click_queries' => [
                ['query' => 'socks', 'searches' => 5, 'last_seen' => '2026-04-30T10:00:00Z'],
            ],
        ]);

        $rep = $this->client->getClickAttribution(
            'org_acme',
            'products',
            1_700_000_000_000,
            1_700_086_400_000,
            50
        );

        $this->assertSame('lexis_qid', $rep->attributionParam);
        $this->assertSame(12, $rep->kpi['clicks']);
        $this->assertSame(30, $rep->kpi['searches']);
        $this->assertSame(0.4, $rep->kpi['ctr']);
        $this->assertSame(5, $rep->kpi['zeroClickCount']);
        $this->assertCount(2, $rep->topByCtr);
        $this->assertSame('shoes', $rep->topByCtr[0]['query']);
        $this->assertSame('A', $rep->topByCtr[0]['topProduct']);
        $this->assertNull($rep->topByCtr[1]['topProduct']);
        $this->assertCount(1, $rep->zeroClickQueries);
        $this->assertSame('socks', $rep->zeroClickQueries[0]['query']);

        $call = $this->transport->calls[0];
        $this->assertSame('GET', $call['method']);
        $this->assertStringContainsString(
            '/v1/admin/orgs/org_acme/analytics/click-attribution',
            $call['url']
        );
        $this->assertStringContainsString('index_slug=products', $call['url']);
        $this->assertStringContainsString('from_ms=1700000000000', $call['url']);
        $this->assertStringContainsString('to_ms=1700086400000', $call['url']);
        $this->assertStringContainsString('limit=50', $call['url']);
    }

    public function testSessionIdForwardedAsHeader(): void
    {
        // Setting the session id should forward it on every subsequent
        // request via `X-Lexis-Session-Id`. Engine uses that to count
        // distinct visitors in zero-results / CTR analytics.
        $this->client->setSessionId('sess_abc123');

        $this->transport->queue(200, [
            'hits' => [], 'total' => 0, 'limit' => 20, 'offset' => 0,
            'took_ms' => 1, 'query' => '', 'expanded_terms' => [],
        ]);
        $this->client->search('products', 'x');

        $headers = $this->transport->calls[0]['headers'];
        $this->assertSame('sess_abc123', $headers['X-Lexis-Session-Id']);
    }

    public function testSessionIdAbsentByDefault(): void
    {
        // Without an explicit `setSessionId`, the header should NOT be
        // forwarded — guarantees that integrators who don't opt in
        // never accidentally leak whatever string `$this->sessionId`
        // happens to default to.
        $this->transport->queue(200, [
            'hits' => [], 'total' => 0, 'limit' => 20, 'offset' => 0,
            'took_ms' => 1, 'query' => '', 'expanded_terms' => [],
        ]);
        $this->client->search('products', 'x');

        $headers = $this->transport->calls[0]['headers'];
        $this->assertArrayNotHasKey('X-Lexis-Session-Id', $headers);
    }

    public function testSessionIdClearable(): void
    {
        $this->client->setSessionId('sess_abc123');
        $this->assertSame('sess_abc123', $this->client->getSessionId());
        $this->client->setSessionId(null);
        $this->assertNull($this->client->getSessionId());

        $this->transport->queue(200, [
            'hits' => [], 'total' => 0, 'limit' => 20, 'offset' => 0,
            'took_ms' => 1, 'query' => '', 'expanded_terms' => [],
        ]);
        $this->client->search('products', 'x');
        $headers = $this->transport->calls[0]['headers'];
        $this->assertArrayNotHasKey('X-Lexis-Session-Id', $headers);
    }

    public function testGetClickAttributionWithoutFiltersOmitsQueryString(): void
    {
        $this->transport->queue(200, [
            'attribution_param' => 'lexis_qid',
            'kpi' => ['clicks' => 0, 'searches' => 0, 'ctr' => 0.0, 'zero_click_count' => 0],
            'top_by_ctr' => [],
            'zero_click_queries' => [],
        ]);

        $this->client->getClickAttribution('org_acme');

        $url = $this->transport->calls[0]['url'];
        $this->assertSame(
            'https://lexis.test/v1/admin/orgs/org_acme/analytics/click-attribution',
            $url
        );
    }
}
