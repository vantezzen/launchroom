<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Data\ConfigAsCode\ConfigFileSettingsData;
use Spatie\LaravelData\Data;

/**
 * `projects.settings` (docs/07): grouped project-level settings.
 */
final class ProjectSettingsData extends Data
{
    public function __construct(
        /** Production secrets must differ from preview (docs/06 §3.1); also forced by the team setting. */
        public bool $productionSecretsMustDiffer = false,
        /** Where launchroom.json is read from (docs/18 §3.1). */
        public ConfigFileSettingsData $configFile = new ConfigFileSettingsData,
    ) {}
}
