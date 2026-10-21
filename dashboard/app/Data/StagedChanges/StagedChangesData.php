<?php

declare(strict_types=1);

namespace App\Data\StagedChanges;

use App\Data\ConfigAsCode\StagedConfigFileData;
use App\Enums\EnvironmentKind;
use Spatie\LaravelData\Data;

/**
 * Staged changes of an environment: diff(last applied revision, current
 * intent) grouped by service (docs/06 §4). Never carries variable values.
 * `blockers` lists broken references that would refuse a Deploy (only
 * computed for the review page).
 */
final class StagedChangesData extends Data
{
    /**
     * @param  list<StagedServiceData>  $groups
     * @param  list<string>  $blockers
     * @param  list<PromotedImageData>  $promotedImages  commits pinned by a promotion, built by the next Deploy
     */
    public function __construct(
        public string $projectId,
        public string $projectName,
        public string $environmentId,
        public string $environmentName,
        public int $count,
        public bool $neverDeployed,
        public ?int $revision,
        public array $groups,
        public array $blockers,
        public bool $canDeploy,
        /** Production deploys can be staged (built and started, routed on Promote, docs/06 §6.5). */
        public EnvironmentKind $environmentKind = EnvironmentKind::Persistent,
        public array $promotedImages = [],
        /** launchroom.json's part in these changes (docs/18 §3.4–3.5). */
        public ?StagedConfigFileData $configFile = null,
    ) {}
}
