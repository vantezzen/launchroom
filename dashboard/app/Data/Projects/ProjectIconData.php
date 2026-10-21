<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Enums\ProjectIconMode;
use App\Models\Project;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * The project icon card in Settings → General (docs/08 §13).
 */
final class ProjectIconData extends Data
{
    public function __construct(
        public ProjectIconMode $mode,
        public ?string $url,
        /** Host the automatic icon came from ("acme.com"). */
        public ?string $source,
        public ?CarbonInterface $checkedAt,
    ) {}

    public static function fromProject(Project $project): self
    {
        $source = $project->icon_source === null ? null : parse_url($project->icon_source, PHP_URL_HOST);

        return new self(
            mode: $project->icon_mode,
            url: $project->iconUrl(),
            source: is_string($source) ? $source : null,
            checkedAt: $project->icon_checked_at,
        );
    }
}
