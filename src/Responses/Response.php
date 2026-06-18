<?php

declare(strict_types=1);

namespace Milo\Sdk\Responses;

/**
 * Base for typed response objects. Hydrated from the decoded JSON body, it gives
 * three ways to read data — like `openai-php`'s responses:
 *
 *   $r->status;              // magic property access
 *   $r['status'];            // array access
 *   $r->toArray();           // the raw decoded body
 *
 * Concrete subclasses add typed accessors (e.g. {@see MessageResult::$reply}).
 *
 * @implements \ArrayAccess<string,mixed>
 */
abstract class Response implements \ArrayAccess, \JsonSerializable
{
    /** @param array<string,mixed> $attributes */
    final public function __construct(protected array $attributes = [])
    {
        $this->hydrate();
    }

    /** @param array<string,mixed> $attributes */
    public static function from(array $attributes): static
    {
        return new static($attributes);
    }

    /** Hook for subclasses to populate typed properties from {@see $attributes}. */
    protected function hydrate(): void
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->attributes;
    }

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Milo responses are read-only');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Milo responses are read-only');
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->attributes;
    }
}
