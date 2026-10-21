<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

final class ProjectSummaryData extends Data
{
    /**
     * @param  list<EnvironmentSummaryData>  $environments
     * @param  list<string>  $logos  glyph keys of the first services
     * @param  list<DeployDayData>  $deploys  the last 14 days (UTC), oldest first, always 14 entries
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public array $environments,
        public array $logos,
        public ?CarbonInterface $updatedAt,
        /** Slot 0–7 of the categorical data palette (direction.md §8.4). */
        public int $colorSlot = 0,
        /** Production hostname: the first custom domain, else the generated one. */
        public ?string $primaryDomain = null,
        public ?ProjectLastDeploymentData $lastDeployment = null,
        public array $deploys = [],
        /** Mini canvas of production; null when it has no services. */
        public ?CanvasThumbnailData $canvas = null,
        /** The project icon (docs/08 §13); null → the card shows no icon. */
        public ?string $iconUrl = null,
    ) {}
}
