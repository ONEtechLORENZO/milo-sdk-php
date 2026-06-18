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
            ->withTool('order_lookup')
            ->withTool('order_lookup') // de-duped
            ->withTool('refund')
            ->toArray();

        self::assertSame('support', $config['task_id']);
        self::assertSame('inline', $config['prompt']['provider']);
        self::assertSame('You are {{brand}} support.', $config['prompt']['system_template']);
        self::assertSame('eu.amazon.nova-micro-v1:0', $config['model']['model_id']);
        self::assertSame(0.2, $config['model']['temperature']);
        self::assertTrue($config['tools']['enabled']);
        self::assertSame(['order_lookup', 'refund'], $config['tools']['enabled_tool_ids']);
        self::assertSame(20, $config['memory_access']['max_history_messages']);
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

    public function testToolBuilderHttpShape(): void
    {
        $config = $this->client(new RecordingClient())
            ->tools('acme')->builder('order_lookup')
            ->http('GET', 'https://api.acme.test/orders/{{order_id}}')
            ->capability('read')->sideEffect('none')
            ->inputSchema(['type' => 'object', 'properties' => ['order_id' => ['type' => 'string']]])
            ->enable()
            ->toArray();

        self::assertSame('http', $config['tool_type']);
        self::assertSame('GET', $config['spec']['method']);
        self::assertSame('https://api.acme.test/orders/{{order_id}}', $config['spec']['url']);
        self::assertSame('read', $config['capability']);
        self::assertSame('none', $config['side_effect_level']);
        self::assertTrue($config['enabled']);
    }

    public function testCatalogBindingForbidsSecurityFieldsByConstruction(): void
    {
        // bindCatalog only ever sends binding-safe fields; the catalog owns the rest.
        $rec = (new RecordingClient())->queueJson(201, ['tool' => ['tool_id' => 'shop_search']]);
        $this->client($rec)->tools('acme')->bindCatalog('shop_search', 'mcp_shop_search', ['shop_domain' => 'acme.myshopify.com'], 'ssm:/milo/dev/acme/tools/shop');

        $body = $rec->lastJsonBody();
        self::assertSame('mcp_shop_search', $body['catalog_tool_id']);
        self::assertSame('acme.myshopify.com', $body['variables']['shop_domain']);
        self::assertArrayNotHasKey('tool_type', $body);
        self::assertArrayNotHasKey('input_schema', $body);
        self::assertArrayNotHasKey('side_effect_level', $body);
    }
}
