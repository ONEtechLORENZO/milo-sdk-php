<?php

declare(strict_types=1);

namespace Milo\Sdk\Resources;

use Milo\Sdk\Config;
use Milo\Sdk\Responses\Item;
use Milo\Sdk\Transport\Transporter;

/**
 * Admin-side conversation reads for one tenant (the operator view, distinct from
 * the HMAC {@see Messaging} reads). Lists conversations, fetches a transcript +
 * pipeline status, and reads message results. Obtain via `$milo->conversations($tenant)`.
 */
final class Conversations extends Resource
{
    public function __construct(
        Transporter $transporter,
        Config $config,
        private readonly string $tenantId,
    ) {
        parent::__construct($transporter, $config);
    }

    private function base(): string
    {
        return '/tenants/' . rawurlencode($this->tenantId);
    }

    /**
     * @param array<string,scalar> $filters task, status, client, channel, from, to, limit
     * @return array<int,Item>
     */
    public function list(array $filters = []): array
    {
        return $this->items($this->adminGet($this->base() . '/conversations', $filters), 'conversations');
    }

    /** Full transcript + processing/pipeline detail for one conversation. */
    public function get(string $taskId, string $conversationId): Item
    {
        $path = $this->base() . '/tasks/' . rawurlencode($taskId) . '/conversations/' . rawurlencode($conversationId);

        return Item::from($this->adminGet($path)->data);
    }

    /** Lighter poll target: state + last reply, no transcript. */
    public function status(string $taskId, string $conversationId): Item
    {
        $path = $this->base() . '/tasks/' . rawurlencode($taskId) . '/conversations/' . rawurlencode($conversationId) . '/status';

        return Item::from($this->adminGet($path)->data);
    }

    /** Operator close (`operator`+ token). */
    public function close(string $taskId, string $conversationId, ?string $reason = null): Item
    {
        $path = $this->base() . '/tasks/' . rawurlencode($taskId) . '/conversations/' . rawurlencode($conversationId) . '/close';

        return Item::from($this->adminPost($path, array_filter(['close_reason' => $reason], static fn ($v) => $v !== null))->data);
    }

    /** Poll a message result by external id (admin mirror of the `/v1` result poll). */
    public function result(string $taskId, string $externalMessageId): Item
    {
        $path = $this->base() . '/tasks/' . rawurlencode($taskId) . '/results/' . rawurlencode($externalMessageId);

        return Item::from($this->adminGet($path)->data);
    }
}
