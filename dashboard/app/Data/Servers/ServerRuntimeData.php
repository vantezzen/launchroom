<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Data\Protocol\V1\HostStatus;
use App\Enums\Servers\CapabilityReason;
use App\Enums\Servers\CapabilityState;
use App\Enums\Servers\DockerEngine;
use App\Enums\Servers\DockerMode;
use App\Enums\Servers\HostOs;
use App\Enums\Servers\RuntimeCapability;
use App\Support\ServerPlatform;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * `servers.runtime`: how Docker runs on the server and what launchroomd can
 * do there (Report.host.runtime, D49). The latest report wins; a report
 * without it keeps the stored value.
 */
final class ServerRuntimeData extends Data
{
    /**
     * @param  list<RuntimeCapabilityData>  $capabilities  known capabilities only, in report order
     * @param  list<string>|null  $buildPlatforms  what its BuildKit can build, emulated platforms included (D53); null = not reported (no buildkitd running, older launchroomd)
     */
    public function __construct(
        public DockerMode $docker,
        /** launchroomd reaches Docker through dockerd-guard. */
        public bool $guard,
        #[DataCollectionOf(RuntimeCapabilityData::class)]
        public array $capabilities,
        public string $reportedAt,
        /** The machine's OS (D53); null from older launchroomd. */
        public ?HostOs $hostOs = null,
        /** The Docker distribution (D53); null from older launchroomd. */
        public ?DockerEngine $engine = null,
        public ?array $buildPlatforms = null,
    ) {}

    public function capability(RuntimeCapability $name): ?CapabilityState
    {
        foreach ($this->capabilities as $capability) {
            if ($capability->name === $name) {
                return $capability->state;
            }
        }

        return null;
    }

    /**
     * The capability rows of the Network tab. A row without a reason (older
     * launchroomd, or one this dashboard doesn't know) shows the message as sent.
     *
     * @return list<CapabilityRowData>
     */
    public function rows(): array
    {
        return array_map(fn (RuntimeCapabilityData $capability): CapabilityRowData => new CapabilityRowData(
            $capability->name,
            $capability->state,
            $capability->reason?->explain($capability->name, $this->engine, $capability->message) ?? $capability->message,
        ), $this->capabilities);
    }

    /**
     * Whitelists a report's runtime, validated by {@see HostStatus::rules()}:
     * unknown capability names (newer servers) are dropped, unknown reasons
     * are kept as facts only, the first entry of a name wins.
     *
     * @param  array<string, mixed>  $reported
     */
    public static function fromReport(array $reported, string $reportedAt): ?self
    {
        $docker = $reported['docker'] ?? null;
        $docker = is_string($docker) ? DockerMode::tryFrom($docker) : null;

        if ($docker === null) {
            return null;
        }

        $capabilities = [];
        $seen = [];
        $items = $reported['capabilities'] ?? null;

        foreach (array_slice(is_array($items) ? $items : [], 0, 16) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $item['name'] ?? null;
            $name = is_string($name) ? RuntimeCapability::tryFrom($name) : null;
            $state = $item['state'] ?? null;
            $state = is_string($state) ? CapabilityState::tryFrom($state) : null;

            if ($name === null || $state === null || isset($seen[$name->value])) {
                continue;
            }

            $seen[$name->value] = true;
            $message = $item['message'] ?? null;
            $reason = $item['reason'] ?? null;
            $capabilities[] = new RuntimeCapabilityData(
                $name,
                $state,
                is_string($message) && $message !== '' ? mb_substr($message, 0, 512) : null,
                is_string($reason) ? CapabilityReason::tryFrom($reason) : null,
            );
        }

        $hostOs = $reported['hostOs'] ?? null;
        $engine = $reported['engine'] ?? null;
        $platforms = $reported['buildPlatforms'] ?? null;

        return new self(
            $docker,
            ($reported['guard'] ?? false) === true,
            $capabilities,
            $reportedAt,
            is_string($hostOs) ? HostOs::tryFrom($hostOs) : null,
            is_string($engine) ? DockerEngine::tryFrom($engine) : null,
            is_array($platforms) ? array_values(array_unique(array_filter($platforms, static fn (mixed $p): bool => in_array($p, ServerPlatform::ALL, true)))) : null,
        );
    }
}
