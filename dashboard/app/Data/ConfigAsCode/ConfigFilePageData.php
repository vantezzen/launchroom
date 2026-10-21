<?php

declare(strict_types=1);

namespace App\Data\ConfigAsCode;

use Spatie\LaravelData\Data;

/**
 * Settings → General → Config file (docs/18 §3.1).
 */
final class ConfigFilePageData extends Data
{
    /**
     * @param  list<ConfigRepositoryOptionData>  $repositories  the repositories the project's apps are built from
     * @param  list<ConfigFileEnvironmentData>  $environments
     */
    public function __construct(
        public ConfigFileSettingsData $settings,
        public array $repositories,
        /** The repository the file is read from now (null: off, or several to pick from). */
        public ?string $repository,
        public array $environments,
        public string $schemaUrl,
        public bool $canUpdate,
    ) {}
}
