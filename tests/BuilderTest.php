<?php

declare(strict_types=1);

namespace Milo\Sdk\Tests;

use Milo\Sdk\Factory;
use Milo\Sdk\Tests\Support\RecordingClient;
use PHPUnit\Framework\TestCase;

final class BuilderTest extends TestCase
{
    private function client(RecordingClient $rec): \Milo\Sdk\Client
    {
        return (new Factory())->withBaseUrl('https://api.test/prod')->withAdminToken('t')->withHttpClient($rec)->make();
    }

    public function testTaskBuilderAssemblesNestedConfig(): void
    {
        $config = $this->client(new RecordingClient())
            ->tasks('acme')->builder('support')
            ->displayName('Support')
            ->inlinePrompt('You are {{brand}} support.')
            ->model('eu.amazon.nova-micro-v1:0', temperature: 0.2)
            ->history(true, 20)
            ->withClientTool('order_lookup', 'Look up an order', ['type' => 'object'])
            ->withClientTool('refund', 'Issue a refund')
            ->toArray();

        self::assertSame('support', $config['task_id']);
        self::assertSame('inline', $config['prompt']['provider']);
        self::assertSame('You are {{brand}} support.', $config['prompt']['system_template']);
        self::assertSame('eu.amazon.nova-micro-v1:0', $config['model']['model_id']);
        self::assertSame(0.2, $config['model']['temperature']);
        self::assertTrue($config['tools']['enabled']);
        self::assertSame(['order_lookup', 'refund'], array_column($config['tools']['tools'], 'name'));
        self::assertSame(['type' => 'object'], $config['tools']['tools'][0]['input_schema']);
        self::assertArrayNotHasKey('input_schema', $config['tools']['tools'][1]); // omitted when empty
        self::assertSame(20, $config['memory_access']['max_history_messages']);
    }

    public function testOutputSchemaForStructuredOutput(): void
    {
        $schema = ['type' => 'object', 'properties' => ['answer' => ['type' => 'string']]];
        $config = $this->client(new RecordingClient())
            ->tasks('acme')->builder('formatter')
            ->inlinePrompt('Answer as JSON.')
            ->model('eu.amazon.nova-micro-v1:0')
            ->outputSchema($schema)
            ->toArray();

        self::assertSame($schema, $config['output_schema']);
        // structured output stands alone — no tools block forced on
        self::assertArrayNotHasKey('tools', $config);
    }

    public function testClientToolsWholesale(): void
    {
        $config = $this->client(new RecordingClient())
            ->tasks('acme')->builder('support')
            ->clientTools([
                ['name' => 'order_lookup', 'description' => 'Look up an order',
                 'input_schema' => ['type' => 'object']],
            ], maxCallsPerTurn: 4)
            ->toArray();

        self::assertTrue($config['tools']['enabled']);
        self::assertSame('order_lookup', $config['tools']['tools'][0]['name']);
        self::assertSame(4, $config['tools']['max_tool_calls_per_turn']);
        // No server-tool fields leak in — tools are client-executed.
        self::assertArrayNotHasKey('enabled_tool_ids', $config['tools']);
        self::assertArrayNotHasKey('execution_policy', $config['tools']);
    }

    public function testTaskBuilderConversationExportShape(): void
    {
        $config = $this->client(new RecordingClient())
            ->tasks('acme')->builder('support')
            ->conversationExport('webhook', 'https://hook.acme.test/export', 'ssm:/milo/dev/acme/export', true, 48)
            ->toArray();

        self::assertSame('webhook', $config['conversation_export']['mode']);
        self::assertSame('https://hook.acme.test/export', $config['conversation_export']['url']);
        self::assertSame('ssm:/milo/dev/acme/export', $config['conversation_export']['secret_ref']);
        self::assertTrue($config['conversation_export']['delete_after_ack']);
        self::assertSame(48, $config['conversation_export']['max_retention_after_close_hours']);
    }

    public function testTaskBuilderPublishCreatesThenPublishes(): void
    {
        $rec = (new RecordingClient())
            ->queueJson(201, ['task' => ['task_id' => 'support', 'config_version' => 1]])
            ->queueJson(200, ['task' => ['task_id' => 'support', 'published_version' => 1]]);

        $this->client($rec)->tasks('acme')->builder('support')->inlinePrompt('hi')->enable()->publish();

        self::assertCount(2, $rec->requests);
        self::assertSame('https://api.test/prod/admin/tenants/acme/tasks', (string) $rec->requests[0]->getUri());
        self::assertStringEndsWith('/tasks/support/publish', (string) $rec->requests[1]->getUri());
    }

}
