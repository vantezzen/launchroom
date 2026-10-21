<?php

declare(strict_types=1);

namespace App\Services\ConfigAsCode;

use App\Data\ConfigAsCode\ConfigFileProblemData;
use App\Enums\ConfigAsCode\ConfigFileStatus;
use App\Models\ConfigAsCode\EnvironmentConfigFile;

/**
 * What ApplyConfigFile did with one environment (docs/18 §3.4): the status
 * recorded, whether the intent changed, and whether other (dashboard)
 * changes were already staged, in which case a push must not deploy.
 */
final readonly class ConfigApplyOutcome
{
    /**
     * @param  list<ConfigFileProblemData>  $errors
     */
    public function __construct(
        public ConfigFileStatus $status,
        public EnvironmentConfigFile $record,
        public bool $changed = false,
        public bool $otherStaged = false,
        public array $errors = [],
        public string $summary = '',
    ) {}

    public function invalid(): bool
    {
        return $this->status === ConfigFileStatus::Invalid;
    }
}
