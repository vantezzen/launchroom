<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\Wire;
use App\Data\Services\HealthcheckSettings;
use App\Enums\HealthcheckType;
use Spatie\LaravelData\Data;

/**
 * NodeState workload `health`: the daemon's external HTTP/TCP probe.
 */
final class HealthSpec extends Data
{
    public function __construct(
        public string $type,
        public string $startPeriod,
        public string $interval,
        public string $timeout,
        public int $failureThreshold,
        public ?string $path = null,
        public ?string $expectStatus = null,
    ) {}

    public static function fromSettings(HealthcheckSettings $settings): ?self
    {
        return match ($settings->type) {
            HealthcheckType::None => null,
            HealthcheckType::Tcp => new self('tcp', "{$settings->startPeriodSeconds}s", "{$settings->intervalSeconds}s", "{$settings->timeoutSeconds}s", $settings->failureThreshold),
            HealthcheckType::Http => new self('http', "{$settings->startPeriodSeconds}s", "{$settings->intervalSeconds}s", "{$settings->timeoutSeconds}s", $settings->failureThreshold, $settings->path, $settings->expectStatus),
        };
    }

    public static function fromProtocol(object $wire): self
    {
        return new self(
            Wire::string($wire, 'type'),
            Wire::string($wire, 'startPeriod'),
            Wire::string($wire, 'interval'),
            Wire::string($wire, 'timeout'),
            Wire::int($wire, 'failureThreshold'),
            Wire::nullableString($wire, 'path'),
            Wire::nullableString($wire, 'expectStatus'),
        );
    }

    /**
     * @return array<string, string|int>
     */
    public function toProtocol(): array
    {
        return array_filter([
            'type' => $this->type,
            'path' => $this->path,
            'expectStatus' => $this->expectStatus,
            'startPeriod' => $this->startPeriod,
            'interval' => $this->interval,
            'timeout' => $this->timeout,
            'failureThreshold' => $this->failureThreshold,
        ], static fn (string|int|null $value): bool => $value !== null);
    }
}
