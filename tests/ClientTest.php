<?php

declare(strict_types=1);

namespace Milo\Sdk\Tests;

use Milo\Sdk\Client;
use Milo\Sdk\Exception\ConflictException;
use Milo\Sdk\Exception\MiloException;
use Milo\Sdk\Exception\NotFoundException;
use Milo\Sdk\Factory;
use Milo\Sdk\Responses\ExportResult;
use Milo\Sdk\Responses\SendResult;
use Milo\Sdk\Tests\Support\RecordingClient;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private function client(RecordingClient $rec): Client
    {
        return (new Factory())
            ->withBaseUrl('https://api.test/prod')
            ->withAdminToken('admin-token', 'sdk-tester')
            ->withApiClient('web_app', 'milo_sk_K3yId-s3cret')
            ->withHttpClient($rec)
            ->make();
    }

    public function testTenantCreateHitsAdminPathWithTokenHeaders(): void
    {
        $rec = (new RecordingClient())->queueJson(201, ['tenant' => ['tenant_id' => 'acme', 'config_version' => 1]]);
        $tenant = $this->client($rec)->tenants()->create(['tenant_id' => 'acme', 'prompt_variables' => ['brand' => 'Acme']]);

        $req = $rec->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('https://api.test/prod/admin/tenants', (string) $req->getUri());
        self::assertSame('admin-token', $req->getHeaderLine('X-Admin-Token'));
        self::assertSame('sdk-tester', $req->getHeaderLine('X-Admin-Actor'));
        self::assertSame('Acme', $rec->lastJsonBody()['prompt_variables']['brand']);
        self::assertSame('acme', $tenant->id());
        self::assertSame(1, $tenant->configVersion());
    }

    public function testSetVariablesCarriesExpectedConfigVersion(): void
    {
        $rec = (new RecordingClient())->queueJson(200, ['tenant' => ['tenant_id' => 'acme', 'config_version' => 3]]);
        $this->client($rec)->tenants()->setVariables('acme', ['brand' => 'Acme2'], expectedConfigVersion: 2);

        $body = $rec->lastJsonBody();
        self::assertSame(2, $body['expected_config_version']);
        self::assertSame('Acme2', $body['prompt_variables']['brand']);
        self::assertSame('PUT', $rec->lastRequest()->getMethod());
    }

    public function testMessagingSendIsBearerAuthedAndTyped(): void
    {
        $rec = (new RecordingClient())->queueJson(202, [
            'status' => 'accepted',
            'conversation_id' => 'conv_1',
            'external_message_id' => 'ext_1',
            'result_status' => 'pending',
        ]);

        $result = $this->client($rec)->messaging('acme', 'web_app')->send('Where is my order?', [
            'task_id' => 'support',
            'external_sender_id' => 'user-42',
        ]);

        $req = $rec->lastRequest();
        self::assertSame('https://api.test/prod/v1/messages', (string) $req->getUri());
        self::assertSame('Bearer milo_sk_K3yId-s3cret', $req->getHeaderLine('Authorization'));
        // No legacy signing headers.
        self::assertSame('', $req->getHeaderLine('X-Milo-Signature'));
        // No edge api-key unless configured (this client has none).
        self::assertSame('', $req->getHeaderLine('x-api-key'));

        $body = $rec->lastJsonBody();
        self::assertSame('acme', $body['tenant_id']);
        self::assertSame('support', $body['task_id']);
        self::assertSame('user-42', $body['external_sender']['id']);
        self::assertSame('Where is my order?', $body['message']['text']);
        self::assertNotEmpty($body['external_message_id']);

        self::assertInstanceOf(SendResult::class, $result);
        self::assertFalse($result->isDuplicate());
        self::assertSame('conv_1', $result->conversationId);
    }

    public function testRunToolsDrivesTheClientToolLoop(): void
    {
        $rec = (new RecordingClient())
            ->queueJson(202, ['status' => 'accepted', 'conversation_id' => 'conv_1', 'external_message_id' => 'ext_1'])
            ->queueJson(200, ['conversation_id' => 'conv_1', 'status' => 'open', 'last_reply' => null]) // baseline
            ->queueJson(200, ['conversation_id' => 'conv_1', 'status' => 'open', 'last_reply' => null,
                'pending_tool_calls' => [['tool_call_id' => 'tu_1', 'name' => 'get_weather', 'input' => ['city' => 'NYC']]],
                'pending_external_message_id' => 'grp_1']) // paused on a tool call
            ->queueJson(202, ['status' => 'accepted']) // submit tool results
            ->queueJson(200, ['conversation_id' => 'conv_1', 'status' => 'open',
                'last_reply' => ['text' => "It's sunny.", 'replied_at' => '2026-06-19T10:00:00Z']]); // final reply

        $calls = [];
        $send = $this->client($rec)->messaging('acme', 'web_app')->send('weather?', [
            'task_id' => 'support', 'external_sender_id' => 'u1',
        ]);
        $state = $send->runTools(function (string $name, array $input, string $id) use (&$calls) {
            $calls[] = [$name, $input, $id];

            return ['temp' => 72];
        }, maxRounds: 3, maxAttempts: 3, intervalSeconds: 0.0);

        self::assertSame([['get_weather', ['city' => 'NYC'], 'tu_1']], $calls);
        self::assertSame("It's sunny.", $state->replyText());
        // The submit hit the tool-results endpoint with the GROUPED id + the outputs.
        $submit = $rec->requests[3];
        self::assertSame('https://api.test/prod/v1/conversations/conv_1/tool-results', (string) $submit->getUri());
        $body = json_decode((string) $submit->getBody(), true);
        self::assertSame('grp_1', $body['external_message_id']);
        self::assertSame(['tu_1' => ['temp' => 72]], $body['tool_results']);
    }

    public function testSendSyncReturnsReplyInline(): void
    {
        $rec = (new RecordingClient())->queueJson(200, [
            'status' => 'completed', 'conversation_id' => 'conv_1', 'external_message_id' => 'm1',
            'reply' => ['type' => 'text', 'text' => 'Hello, synchronously.'],
        ]);
        $result = $this->client($rec)->messaging('acme', 'web_app')->sendSync('hi', [
            'task_id' => 'support', 'external_sender_id' => 'u1',
        ]);

        $req = $rec->lastRequest();
        self::assertSame('https://api.test/prod/v1/messages', (string) $req->getUri());
        self::assertSame('Bearer milo_sk_K3yId-s3cret', $req->getHeaderLine('Authorization'));
        self::assertTrue(json_decode((string) $req->getBody(), true)['sync']);
        self::assertInstanceOf(\Milo\Sdk\Responses\MessageResult::class, $result);
        self::assertTrue($result->isCompleted());
        self::assertSame('Hello, synchronously.', $result->text());
    }

    public function testRunToolsSyncDrivesTheLoopInline(): void
    {
        $rec = (new RecordingClient())
            ->queueJson(200, [ // sendSync -> paused on a tool call
                'status' => 'tool_calls_pending', 'conversation_id' => 'conv_1', 'external_message_id' => 'm1',
                'tool_calls' => [['tool_call_id' => 'tu_1', 'name' => 'get_weather', 'input' => ['city' => 'NYC']]],
                'reply' => ['type' => 'text', 'text' => ''],
            ])
            ->queueJson(200, [ // submitToolResultsSync -> final reply
                'status' => 'completed', 'conversation_id' => 'conv_1', 'external_message_id' => 'm1',
                'reply' => ['type' => 'text', 'text' => "It's sunny."],
            ]);

        $chat = $this->client($rec)->messaging('acme', 'web_app');
        $calls = [];
        $first = $chat->sendSync('weather?', ['task_id' => 'support', 'external_sender_id' => 'u1']);
        self::assertTrue($first->isToolCallsPending());

        $final = $chat->runToolsSync($first, function (string $name, array $input, string $id) use (&$calls) {
            $calls[] = [$name, $input, $id];

            return ['temp' => 72];
        });

        self::assertSame([['get_weather', ['city' => 'NYC'], 'tu_1']], $calls);
        self::assertTrue($final->isCompleted());
        self::assertSame("It's sunny.", $final->text());
        // The sync submit hit the tool-results endpoint with sync:true + the SAME
        // external_message_id (sync has no debounce grouping → it IS the parked key).
        $submit = $rec->requests[1];
        self::assertSame('https://api.test/prod/v1/conversations/conv_1/tool-results', (string) $submit->getUri());
        $body = json_decode((string) $submit->getBody(), true);
        self::assertTrue($body['sync']);
        self::assertSame('m1', $body['external_message_id']);
        self::assertSame(['tu_1' => ['temp' => 72]], $body['tool_results']);
    }

    public function testPerRequestContextIsSentOnTheBody(): void
    {
        $rec = (new RecordingClient())->queueJson(200, [
            'status' => 'completed', 'conversation_id' => 'conv_1', 'external_message_id' => 'm1',
            'reply' => ['type' => 'text', 'text' => 'The promo code is ZEBRA-42.'],
        ]);
        $this->client($rec)->messaging('acme', 'web_app')->sendSync('What is the promo code?', [
            'task_id' => 'support', 'external_sender_id' => 'u1',
            'context' => "Store knowledge: the current promo code is ZEBRA-42.",
        ]);

        $body = json_decode((string) $rec->lastRequest()->getBody(), true);
        self::assertSame('Store knowledge: the current promo code is ZEBRA-42.', $body['context']);
        self::assertTrue($body['sync']);
    }

    public function testContextAcceptsJsonAndIsOmittedWhenEmpty(): void
    {
        $rec = (new RecordingClient())
            ->queueJson(202, ['status' => 'accepted', 'conversation_id' => 'c', 'external_message_id' => 'm'])
            ->queueJson(202, ['status' => 'accepted', 'conversation_id' => 'c', 'external_message_id' => 'm']);
        $chat = $this->client($rec)->messaging('acme', 'web_app');

        // a structured (array) context passes through as-is (server serializes it)
        $chat->send('hi', ['task_id' => 'support', 'external_sender_id' => 'u1',
            'context' => ['gathered' => ['orders' => 3]]]);
        self::assertSame(['gathered' => ['orders' => 3]],
            json_decode((string) $rec->requests[0]->getBody(), true)['context']);

        // empty context is omitted entirely
        $chat->send('hi', ['task_id' => 'support', 'external_sender_id' => 'u1', 'context' => '']);
        self::assertArrayNotHasKey('context', json_decode((string) $rec->requests[1]->getBody(), true));
    }

    public function testStructuredOutputReplyExposesParsedJson(): void
    {
        $rec = (new RecordingClient())->queueJson(200, [
            'status' => 'completed', 'conversation_id' => 'conv_1', 'external_message_id' => 'm1',
            'reply' => [
                'type' => 'json',
                'json' => ['answer' => 'Sunny', 'score' => 0.9],
                'text' => '{"answer":"Sunny","score":0.9}',
            ],
        ]);
        $result = $this->client($rec)->messaging('acme', 'web_app')->sendSync('weather?', [
            'task_id' => 'formatter', 'external_sender_id' => 'u1',
        ]);

        self::assertTrue($result->isCompleted());
        self::assertTrue($result->isJson());
        self::assertSame(['answer' => 'Sunny', 'score' => 0.9], $result->json());
        // text() still returns the serialization for text-only consumers
        self::assertSame('{"answer":"Sunny","score":0.9}', $result->text());
    }

    public function testApiGatewayKeyIsSentAsXApiKeyWhenConfigured(): void
    {
        // staging/prod run api_require_api_key=true, so the SDK must send the
        // gateway usage-plan key as x-api-key (alongside the bearer) or the
        // gateway 403s the write before the Lambda.
        $rec = (new RecordingClient())->queueJson(202, ['status' => 'accepted', 'conversation_id' => 'conv_1']);
        $client = (new Factory())
            ->withBaseUrl('https://api.test/prod')
            ->withApiClient('web_app', 'milo_sk_K3yId-s3cret')
            ->withApiGatewayKey('edge-key-abc')
            ->withHttpClient($rec)
            ->make();

        $client->messaging('acme', 'web_app')->send('hi', ['task_id' => 'support', 'external_sender_id' => 'u1']);

        $req = $rec->lastRequest();
        self::assertSame('edge-key-abc', $req->getHeaderLine('x-api-key'));
        self::assertSame('Bearer milo_sk_K3yId-s3cret', $req->getHeaderLine('Authorization'));
    }

    public function testWaitForReplyPollsTheConversationNotTheResultEndpoint(): void
    {
        $rec = (new RecordingClient())
            ->queueJson(202, ['status' => 'accepted', 'conversation_id' => 'conv_1', 'external_message_id' => 'ext_1'])
            ->queueJson(200, ['conversation_id' => 'conv_1', 'status' => 'open']) // baseline: no reply yet
            ->queueJson(200, ['conversation_id' => 'conv_1', 'status' => 'open',
                'last_reply' => ['text' => 'Hi there!', 'replied_at' => '2026-06-19T10:00:05Z', 'usage' => ['totalTokens' => 12]]]);

        $send = $this->client($rec)->messaging('acme', 'web_app')->send('hello', [
            'task_id' => 'support', 'external_sender_id' => 'u1',
        ]);
        $state = $send->waitForReply(maxAttempts: 5, intervalSeconds: 0.0);

        self::assertTrue($state->hasReply());
        self::assertSame('Hi there!', $state->replyText());
        self::assertSame('2026-06-19T10:00:05Z', $state->repliedAt());
        self::assertSame(12, $state->lastReply()['usage']['totalTokens']);
        // It polled the conversation, NOT the by-id result endpoint (which can't see
        // a debounce-grouped reply).
        self::assertStringContainsString('/v1/conversations/conv_1', (string) $rec->lastRequest()->getUri());
        self::assertStringNotContainsString('/result', (string) $rec->lastRequest()->getUri());
    }

    public function testPollConversationWaitsForAReplyNewerThanBaseline(): void
    {
        $rec = (new RecordingClient())
            ->queueJson(200, ['conversation_id' => 'c', 'status' => 'open',
                'last_reply' => ['text' => 'old', 'replied_at' => '2026-06-19T10:00:00Z']])
            ->queueJson(200, ['conversation_id' => 'c', 'status' => 'open',
                'last_reply' => ['text' => 'new', 'replied_at' => '2026-06-19T10:00:30Z']]);

        $state = $this->client($rec)->messaging('acme', 'web_app')
            ->pollConversation('c', '2026-06-19T10:00:10Z', maxAttempts: 5, intervalSeconds: 0.0);

        self::assertSame('new', $state->replyText()); // skipped the older reply
    }

    public function testSendPollRetrievesCompletedResult(): void
    {
        $rec = (new RecordingClient())
            ->queueJson(202, ['status' => 'accepted', 'external_message_id' => 'ext_9'])
            ->queueJson(200, ['status' => 'pending', 'external_message_id' => 'ext_9'])
            ->queueJson(200, [
                'status' => 'completed',
                'external_message_id' => 'ext_9',
                'reply' => ['type' => 'text', 'text' => 'Your order ships today.'],
                'debug' => ['usage' => ['totalTokens' => 42]],
            ]);

        $result = $this->client($rec)->messaging('acme', 'web_app')
            ->send('status?', ['task_id' => 'support', 'external_sender_id' => 'u1'])
            ->poll(maxAttempts: 3, intervalSeconds: 0.0);

        self::assertTrue($result->isCompleted());
        self::assertSame('Your order ships today.', $result->text());
        self::assertSame(42, $result->usage()['totalTokens']);
        // GET reads carry the bearer key; the tenant comes from the key itself.
        self::assertSame('Bearer milo_sk_K3yId-s3cret', $rec->lastRequest()->getHeaderLine('Authorization'));
    }

    public function testFailedSendSurfacesGeneratedExternalMessageId(): void
    {
        $rec = (new RecordingClient())->queueJson(503, ['status' => 'error', 'message' => 'upstream down']);

        try {
            $this->client($rec)->messaging('acme', 'web_app')
                ->send('hi', ['task_id' => 'support', 'external_sender_id' => 'u1']);
            self::fail('expected MiloException');
        } catch (MiloException $e) {
            $sentId = $rec->lastJsonBody()['external_message_id'];
            self::assertNotEmpty($sentId);
            self::assertSame($sentId, $e->externalMessageId, 'caller can retry the same id');
        }
    }

    public function testCloseHitsDeployedRouteWithConversationIdInBody(): void
    {
        $rec = (new RecordingClient())->queueJson(200, ['status' => 'closed', 'conversation_id' => 'conv_1']);

        $this->client($rec)->messaging('acme', 'web_app')->close('conv_1', reason: 'resolved', taskId: 'support');

        $req = $rec->lastRequest();
        // Deployed route: POST /v1/conversations/close (id in body, NOT the path).
        self::assertSame('https://api.test/prod/v1/conversations/close', (string) $req->getUri());
        self::assertSame('POST', $req->getMethod());

        $body = $rec->lastJsonBody();
        self::assertSame('conv_1', $body['conversation_id']);
        self::assertSame('acme', $body['tenant_id']);
        self::assertSame('resolved', $body['close_reason']);

        // Bearer-authed, no signing headers.
        self::assertSame('Bearer milo_sk_K3yId-s3cret', $req->getHeaderLine('Authorization'));
    }

    public function testExportRetrievalSendsQueryParamsAndReturnsReadyPackage(): void
    {
        $rec = (new RecordingClient())->queueJson(200, [
            'status' => 'ready',
            'export' => ['conversation_id' => 'conv_1', 'message_count' => 2, 'transcript_sha256' => 'abc'],
        ]);

        $export = $this->client($rec)->messaging('acme', 'web_app')->export('conv_1', taskId: 'support');

        $req = $rec->lastRequest();
        self::assertSame('GET', $req->getMethod());
        self::assertStringStartsWith('https://api.test/prod/v1/conversations/export?', (string) $req->getUri());
        parse_str($req->getUri()->getQuery(), $q);
        self::assertSame('acme', $q['tenant_id']);
        self::assertSame('support', $q['task_id']);
        self::assertSame('conv_1', $q['conversation_id']);
        self::assertSame('Bearer milo_sk_K3yId-s3cret', $req->getHeaderLine('Authorization'));

        self::assertInstanceOf(ExportResult::class, $export);
        self::assertTrue($export->isReady());
        self::assertSame(2, $export->package()['message_count']);
    }

    public function testExportMapsNotBuilt404ToNotReadyStatus(): void
    {
        $rec = (new RecordingClient())->queueJson(404, ['status' => 'not_ready', 'reason' => 'export_not_built']);

        $export = $this->client($rec)->messaging('acme', 'web_app')->export('conv_1', taskId: 'support');

        // 404 is surfaced as a poll status, not an exception.
        self::assertTrue($export->notReady());
        self::assertFalse($export->isReady());
        self::assertNull($export->package());
    }

    public function testExportMapsPurged410ToPurgedStatus(): void
    {
        $rec = (new RecordingClient())->queueJson(410, [
            'status' => 'purged',
            'reason' => 'conversation_exported_and_deleted',
            'purged_at' => '2026-06-17T02:00:00Z',
        ]);

        $export = $this->client($rec)->messaging('acme', 'web_app')->export('conv_1', taskId: 'support');

        self::assertTrue($export->isPurged());
        self::assertSame('2026-06-17T02:00:00Z', $export->purgedAt());
        self::assertNull($export->package());
    }

    public function testAcknowledgeExportPostsActionDiscriminator(): void
    {
        $rec = (new RecordingClient())->queueJson(202, ['status' => 'ok', 'export_ack' => 'recorded']);

        $this->client($rec)->messaging('acme', 'web_app')->acknowledgeExport('conv_1', taskId: 'support');

        $req = $rec->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('https://api.test/prod/v1/conversations/export/ack', (string) $req->getUri());
        $body = $rec->lastJsonBody();
        self::assertSame('ack_export', $body['action']);
        self::assertSame('acme', $body['tenant_id']);
        self::assertSame('support', $body['task_id']);
        self::assertSame('conv_1', $body['conversation_id']);
        self::assertSame('Bearer milo_sk_K3yId-s3cret', $req->getHeaderLine('Authorization'));
    }

    public function testConflictExceptionExposesCurrentConfigVersion(): void
    {
        $rec = (new RecordingClient())->queueJson(409, [
            'error' => 'config_version_conflict',
            'code' => 'config_version_conflict',
            'current_config_version' => 7,
        ]);

        try {
            $this->client($rec)->tasks('acme')->update('support', ['display_name' => 'x'], expectedConfigVersion: 5);
            self::fail('expected ConflictException');
        } catch (ConflictException $e) {
            self::assertSame(409, $e->status);
            self::assertSame(7, $e->currentConfigVersion());
        }
    }

    public function testNotFoundMapsToTypedException(): void
    {
        $rec = (new RecordingClient())->queueJson(404, ['error' => 'not found']);
        $this->expectException(NotFoundException::class);
        $this->client($rec)->tenants()->get('missing');
    }

    public function testMessagingWithoutSecretThrows(): void
    {
        $rec = new RecordingClient();
        $this->expectException(\InvalidArgumentException::class);
        $this->client($rec)->messaging('acme', 'unregistered_client');
    }
}
