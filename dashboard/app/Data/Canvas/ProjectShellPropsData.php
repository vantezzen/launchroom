<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Data\Ingress\EdgeOverviewData;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * The props every project page shares (the top bar), as the TS type pages
 * extend. `App\Queries\ProjectShell::props()` shares them as closures so
 * partial reloads skip them; a test checks the keys and values match this.
 */
final class ProjectShellPropsData extends Data
{
    /**
     * @param  list<EnvironmentData>  $environments
     * @param  list<ProjectLinkData>  $projects
     * @param  list<ProjectStatusData>|Optional  $projectStatuses
     * @param  list<EnvironmentStatusData>|Optional  $environmentStatuses
     */
    public function __construct(
        public ProjectData $project,
        public ?EnvironmentData $environment,
        public array $environments,
        public array $projects,
        public ProjectAbilitiesData $abilities,
        /** Single point of failure badge (docs/02 §5). */
        public EdgeOverviewData $edgeOverview,
        /** Loaded when the project switcher opens (a partial reload). */
        public array|Optional $projectStatuses = new Optional,
        /** Loaded when the environment switcher opens (a partial reload). */
        public array|Optional $environmentStatuses = new Optional,
        /** The open environment's live status (D108): seeds the store every status surface reads; deferred, so the page doesn't wait for it. */
        public EnvironmentLiveStatusData|Optional|null $liveStatus = new Optional,
    ) {}
}
