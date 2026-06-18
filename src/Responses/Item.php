<?php

declare(strict_types=1);

namespace Milo\Sdk\Responses;

/**
 * A generic config item (tenant, task, tool, api-client, channel, ...). Config
 * shapes are deep and vary, so this exposes the raw body plus the two fields
 * that matter for the control-plane workflow: the optimistic-locking
 * {@see configVersion()} and the item {@see id()}.
 */
final class Item extends Response
{
    /** The item's `config_version`, for `expected_config_version` on the next write. */
    public function configVersion(): ?int
    {
        $v = $this->attributes['config_version'] ?? null;

        return is_numeric($v) ? (int) $v : null;
    }

    /** Best-effort primary id across item families. */
    public function id(): ?string
    {
        foreach (['tenant_id', 'task_id', 'tool_id', 'client_id', 'conversation_id'] as $key) {
            if (isset($this->attributes[$key]) && is_string($this->attributes[$key])) {
                return $this->attributes[$key];
            }
        }

        return null;
    }
}
