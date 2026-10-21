<?php

declare(strict_types=1);

namespace App\Support\ConfigAsCode;

/**
 * One service of a launchroom.json as it applies to one environment, with
 * the JSON pointers of where each setting came from (base or override).
 */
final readonly class EffectiveService
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $override
     */
    public function __construct(
        public string $slug,
        public array $config,
        public string $pointer,
        public ?string $overridePointer = null,
        public array $override = [],
    ) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->config);
    }

    public function get(string $key): mixed
    {
        return $this->config[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function group(string $key): array
    {
        return Arrays::assoc($this->config[$key] ?? null);
    }

    /**
     * The pointer of a setting (`deploy`, `healthcheck`, `path`): in the
     * override when the override names it, else in the base.
     */
    public function pointerFor(string ...$segments): string
    {
        $value = $this->override;

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $this->pointer.JsonDocument::pointer(...$segments);
            }

            $value = $value[$segment];
        }

        return ($this->overridePointer ?? $this->pointer).JsonDocument::pointer(...$segments);
    }

    public function kind(): string
    {
        $kind = $this->config['kind'] ?? null;

        return is_string($kind) ? $kind : 'app';
    }
}
