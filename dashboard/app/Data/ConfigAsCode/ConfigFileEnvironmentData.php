<?php

declare(strict_types=1);

namespace App\Data\ConfigAsCode;

use App\Enums\ConfigAsCode\ConfigFileStatus;
use App\Enums\EnvironmentKind;
use Spatie\LaravelData\Data;

/**
 * One environment on Settings → General → Config file (docs/18 §3.1): the
 * last read of its launchroom.json.
 */
final class ConfigFileEnvironmentData extends Data
{
    /**
     * @param  list<ConfigFileProblemData>  $errors
     * @param  list<ConfigFileProblemData>  $warnings
     * @param  list<ConfigFileProblemData>  $ignored
     */
    public function __construct(
        public string $id,
        public string $name,
        public EnvironmentKind $kind,
        /** Null: never read. */
        public ?ConfigFileStatus $status,
        public ?string $commitSha,
        public ?string $checkedAt,
        public int $managedServices,
        public int $managedSettings,
        public array $errors,
        public array $warnings,
        public array $ignored,
        public ?string $fileUrl,
        public string $downloadUrl,
        public bool $canSync,
    ) {}
}
