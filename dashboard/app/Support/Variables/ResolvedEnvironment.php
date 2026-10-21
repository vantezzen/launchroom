<?php

declare(strict_types=1);

namespace App\Support\Variables;

use LogicException;
use SensitiveParameter;

/**
 * A fully resolved environment (secrets in plaintext). Never serialized,
 * never printed (docs/09 R12): it only exists between resolution and
 * encryption/sealing.
 */
final readonly class ResolvedEnvironment
{
    /**
     * @param  array<string, string>  $values
     */
    public function __construct(
        #[SensitiveParameter]
        private array $values,
    ) {}

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    /**
     * @param  array<string, string>  $values  added on top (they win)
     */
    public function with(#[SensitiveParameter] array $values): self
    {
        return new self([...$this->values, ...$values]);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return array_fill_keys($this->keys(), '[redacted]');
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Resolved environments cannot be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Resolved environments cannot be unserialized.');
    }
}
