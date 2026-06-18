<?php

declare(strict_types=1);

namespace Milo\Sdk\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use Milo\Sdk\Config;
use Milo\Sdk\Exception\AuthException;
use Milo\Sdk\Exception\QuotaExceededException;
use Milo\Sdk\Exception\RateLimitedException;
use Milo\Sdk\Exception\TransportException;
use Milo\Sdk\Exception\ValidationException;
use Milo\Sdk\Tests\Support\RecordingClient;
use Milo\Sdk\Transport\Transporter;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class TransporterTest extends TestCase
{
    private function transporter(RecordingClient $rec): Transporter
    {
        $factory = new HttpFactory();
        // retryBaseDelay 0 => no real sleeping in tests.
        $config = new Config('https://api.test/prod', maxRetries: 2, retryBaseDelay: 0.0);

        return new Transporter($rec, $factory, $factory, $config);
    }

    public function testRetriesOn503ThenSucceeds(): void
    {
        $rec = (new RecordingClient())
            ->queueJson(503, ['error' => 'temporary'])
            ->queueJson(200, ['ok' => true]);

        $response = $this->transporter($rec)->request('GET', '/admin/health');

        self::assertSame(200, $response->status);
        self::assertCount(2, $rec->requests, 'should have retried once');
    }

    public function testDoesNotRetry4xxAndMapsValidation(): void
    {
        $rec = (new RecordingClient())->queueJson(400, ['error' => 'bad', 'errors' => [['field' => 'tenant_id', 'message' => 'required']]]);

        try {
            $this->transporter($rec)->request('POST', '/admin/tenants', ['x' => 1]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(400, $e->status);
            self::assertSame(['tenant_id' => 'required'], $e->fieldErrors());
        }
        self::assertCount(1, $rec->requests, '4xx must not be retried');
    }

    public function testDataPlaneReasonBecomesErrorCodeAndReason(): void
    {
        // Data plane rejections carry `reason`, not `code`.
        $rec = (new RecordingClient())->queueJson(403, [
            'status' => 'rejected',
            'reason' => 'tenant_disabled',
            'message' => "Tenant 'acme' is disabled and cannot receive messages.",
        ]);

        try {
            $this->transporter($rec)->request('POST', '/v1/messages', ['x' => 1], retry: false);
            self::fail('expected AuthException');
        } catch (AuthException $e) {
            self::assertSame(403, $e->status);
            self::assertSame('tenant_disabled', $e->errorCode);
            self::assertSame('tenant_disabled', $e->reason());
        }
    }

    public function testRateLimit429IsRetried(): void
    {
        $rec = (new RecordingClient())
            ->queueJson(429, ['status' => 'rejected', 'reason' => 'rate_limited'])
            ->queueJson(200, ['ok' => true]);

        $response = $this->transporter($rec)->request('GET', '/v1/conversations/c1');

        self::assertSame(200, $response->status);
        self::assertCount(2, $rec->requests, 'per-minute rate limit should be retried');
    }

    public function testQuota429IsNotRetried(): void
    {
        $rec = (new RecordingClient())
            ->queueJson(429, ['status' => 'rejected', 'reason' => 'quota_exceeded', 'limit' => 1000])
            ->queueJson(200, ['ok' => true]);

        try {
            $this->transporter($rec)->request('GET', '/v1/conversations/c1');
            self::fail('expected QuotaExceededException');
        } catch (QuotaExceededException $e) {
            self::assertInstanceOf(RateLimitedException::class, $e, 'still catchable as RateLimitedException');
            self::assertSame('quota_exceeded', $e->errorCode);
        }
        self::assertCount(1, $rec->requests, 'monthly quota must NOT be retried');
    }

    public function testRetryAfterFallsBackToBodyField(): void
    {
        // No Retry-After header; the data plane also carries retry_after_seconds.
        // maxRetries:0 so it throws on the first 429 (no backoff sleep in the test).
        $rec = (new RecordingClient())->queueJson(429, [
            'status' => 'rejected', 'reason' => 'rate_limited', 'retry_after_seconds' => 5,
        ]);
        $factory = new HttpFactory();
        $transporter = new Transporter($rec, $factory, $factory, new Config('https://api.test/prod', maxRetries: 0));

        try {
            $transporter->request('GET', '/v1/conversations/c1');
            self::fail('expected RateLimitedException');
        } catch (RateLimitedException $e) {
            self::assertSame(5, $e->retryAfter());
        }
    }

    public function testNetworkErrorBecomesTransportExceptionAfterRetries(): void
    {
        $networkError = new class ('boom') extends \RuntimeException implements ClientExceptionInterface {};
        $rec = (new RecordingClient())
            ->queueThrowable($networkError)
            ->queueThrowable($networkError)
            ->queueThrowable($networkError);

        $this->expectException(TransportException::class);
        $this->transporter($rec)->request('GET', '/admin/health');
    }

    public function testInjectsRequestIdAndAcceptHeaders(): void
    {
        $rec = (new RecordingClient())->queueJson(200, ['ok' => true]);
        $this->transporter($rec)->request('GET', '/admin/config');

        $req = $rec->lastRequest();
        self::assertStringStartsWith('milo-php-', $req->getHeaderLine('X-Request-Id'));
        self::assertSame('application/json', $req->getHeaderLine('Accept'));
    }
}
