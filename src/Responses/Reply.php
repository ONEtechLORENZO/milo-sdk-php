<?php

declare(strict_types=1);

namespace Milo\Sdk\Responses;

/** The assistant reply inside a {@see MessageResult}: `{ type, text }`. */
final class Reply extends Response
{
    public string $type = 'text';
    public ?string $text = null;

    protected function hydrate(): void
    {
        $this->type = (string) ($this->attributes['type'] ?? 'text');
        $this->text = isset($this->attributes['text']) ? (string) $this->attributes['text'] : null;
    }

    public function __toString(): string
    {
        return $this->text ?? '';
    }
}
