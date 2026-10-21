<?php

declare(strict_types=1);

namespace App\Data\Blueprints;

use App\Data\Services\HealthcheckSettings;
use App\Enums\ServiceKind;
use Spatie\LaravelData\Data;

/**
 * One service of a ProjectBlueprint (docs/17 §1). Exactly one of `image`
 * (image services), `build` (app services from the import's repository) and
 * `database` (managed databases) is set; workers name their app in `parent`.
 */
final class BlueprintService extends Data
{
    /**
     * @param  list<string>|null  $entrypoint  argv
     * @param  list<string>|null  $startCommand  argv
     * @param  list<string>  $capabilities  within launchroomd's allow-list
     * @param  list<string>  $volumes  mount paths
     * @param  list<BlueprintVariable>  $variables
     * @param  list<BlueprintConnection>  $connections
     * @param  list<BlueprintNote>  $notes
     */
    public function __construct(
        public string $key,
        public string $name,
        public ServiceKind $kind,
        public ?string $logo = null,
        public ?string $image = null,
        public ?BlueprintBuild $build = null,
        public ?BlueprintDatabase $database = null,
        public ?string $parent = null,
        public ?int $port = null,
        public bool $public = false,
        public ?string $domain = null,
        public ?array $entrypoint = null,
        public ?array $startCommand = null,
        public ?HealthcheckSettings $healthcheck = null,
        public ?BlueprintScaling $scaling = null,
        public array $capabilities = [],
        public array $volumes = [],
        public array $variables = [],
        public array $connections = [],
        public array $notes = [],
    ) {}
}
