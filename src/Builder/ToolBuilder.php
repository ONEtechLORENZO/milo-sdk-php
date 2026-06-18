<?php

declare(strict_types=1);

namespace Milo\Sdk\Builder;

use Milo\Sdk\Resources\Tools;
use Milo\Sdk\Responses\Item;

/**
 * Fluent builder for a self-contained tenant tool. For a catalog binding use
 * {@see Tools::bindCatalog()} instead — a binding may not set the
 * catalog-owned security fields this builder writes.
 *
 *   $milo->tools('acme')->builder('order_lookup')
 *       ->http('GET', 'https://api.acme.test/orders/{{order_id}}')
 *       ->capability('read')->sideEffect('none')
 *       ->inputSchema([...])->enable()->create();
 */
final class ToolBuilder
{
    /** @var array<string,mixed> */
    private array $config;

    public function __construct(
        private readonly Tools $tools,
        private readonly string $toolId,
    ) {
        $this->config = ['tool_id' => $toolId, 'capability' => 'read', 'side_effect_level' => 'none'];
    }

    public function displayName(string $name): self
    {
        $this->config['display_name'] = $name;

        return $this;
    }

    public function description(string $description): self
    {
        $this->config['description'] = $description;

        return $this;
    }

    public function enable(bool $enabled = true): self
    {
        $this->config['enabled'] = $enabled;

        return $this;
    }

    public function capability(string $capability): self
    {
        $this->config['capability'] = $capability; // read|write

        return $this;
    }

    public function sideEffect(string $level): self
    {
        $this->config['side_effect_level'] = $level; // none|write|external|destructive

        return $this;
    }

    /** Closed-whitelist builtin handler (`name.vN`). */
    public function builtin(string $handler): self
    {
        $this->config['tool_type'] = 'builtin';
        $this->config['spec'] = ['handler' => $handler];

        return $this;
    }

    /** @param array<string,mixed> $extra method/url/headers/query/body_template/auth extras */
    public function http(string $method, string $url, array $extra = []): self
    {
        $this->config['tool_type'] = 'http';
        $this->config['spec'] = ['method' => $method, 'url' => $url] + $extra;

        return $this;
    }

    public function lambda(string $functionArn): self
    {
        $this->config['tool_type'] = 'lambda';
        $this->config['spec'] = ['function_arn' => $functionArn];

        return $this;
    }

    public function mcp(string $endpoint, string $toolName, string $transport = 'streamable_http'): self
    {
        $this->config['tool_type'] = 'mcp';
        $this->config['spec'] = ['transport' => $transport, 'endpoint' => $endpoint, 'tool_name' => $toolName];

        return $this;
    }

    /** @param array<string,mixed> $schema JSON Schema */
    public function inputSchema(array $schema): self
    {
        $this->config['input_schema'] = $schema;

        return $this;
    }

    /** @param array<string,mixed> $schema JSON Schema */
    public function outputSchema(array $schema): self
    {
        $this->config['output_schema'] = $schema;

        return $this;
    }

    public function secret(string $secretRef): self
    {
        $this->config['secret_ref'] = $secretRef;

        return $this;
    }

    public function limits(?int $timeoutMs = null, ?int $maxRetries = null): self
    {
        if ($timeoutMs !== null) {
            $this->config['timeout_ms'] = $timeoutMs;
        }
        if ($maxRetries !== null) {
            $this->config['max_retries'] = $maxRetries;
        }

        return $this;
    }

    public function set(string $key, mixed $value): self
    {
        $this->config[$key] = $value;

        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->config;
    }

    public function create(): Item
    {
        return $this->tools->create($this->config);
    }

    public function update(?int $expectedConfigVersion = null): Item
    {
        return $this->tools->update($this->toolId, $this->config, $expectedConfigVersion);
    }
}
