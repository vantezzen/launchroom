<?php

declare(strict_types=1);

namespace App\Data\ConfigAsCode;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * `projects.settings.configFile` (docs/18 §3.1): whether and where the
 * project's launchroom.json is read. `repositoryId` is the numeric GitHub
 * repository id; null = the one repository of the project's apps.
 */
final class ConfigFileSettingsData extends Data
{
    public const string DEFAULT_PATH = 'launchroom.json';

    public function __construct(
        public bool $enabled = true,
        #[Max(1024), Regex('/^(?:[A-Za-z0-9_.@+=,-]+\/)*[A-Za-z0-9_.@+=,-]+\.json$/D')]
        public string $path = self::DEFAULT_PATH,
        public ?int $repositoryId = null,
    ) {}
}
