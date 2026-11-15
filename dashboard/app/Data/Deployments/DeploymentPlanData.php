<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Data\Protocol\V1\BuildSummary;
use App\Enums\Builds\ChosenBuilder;
use Spatie\LaravelData\Data;

/**
 * What the build of a deployment produced (`deployments.plan`): written when
 * the build succeeds, fails with a summary or reuses an image, and carried
 * along when an image runs again.
 */
final class DeploymentPlanData extends Data
{
    /**
     * @param  list<string>|null  $attestations
     */
    public function __construct(
        public ?string $task = null,
        /** The image's platform: the scheduler only places it on servers of it. */
        public ?string $platform = null,
        public ?int $durationMs = null,
        public ?BuildSummary $summary = null,
        public ?string $secretsHash = null,
        public ?array $attestations = null,
        public ?string $reusedFrom = null,
    ) {}

    public function reusedBy(string $sourcePublicId): self
    {
        $copy = clone $this;
        $copy->reusedFrom = $sourcePublicId;

        return $copy;
    }

    /**
     * Capabilities the built image needs at runtime besides the service's
     * own: NET_BIND_SERVICE for images Railpack built with its PHP provider.
     * Their FrankenPHP binary carries that file capability, and with every
     * capability dropped the kernel refuses to exec it ("Operation not
     * permitted"), so the app could never start (docs/04 §3.1).
     *
     * @return list<string>
     */
    public function runtimeCapabilities(): array
    {
        $summary = $this->summary;

        return $summary?->builder === ChosenBuilder::Railpack && $summary->provider === 'php' ? ['NET_BIND_SERVICE'] : [];
    }

    public function framework(): ?string
    {
        $framework = $this->summary?->framework;

        return $framework !== null && $framework !== '' ? $framework : null;
    }
}
