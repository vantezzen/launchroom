<?php

declare(strict_types=1);

namespace App\Data\Blueprints;

use Spatie\LaravelData\Data;

/**
 * The common target of Compose import, templates and (D45) launchroom.json
 * (docs/17 §1): the services of one environment with their settings,
 * variables and connections, applied by App\Actions\Blueprints\ApplyBlueprint.
 * Validated by App\Support\Blueprints\BlueprintValidator before it is applied.
 */
final class ProjectBlueprint extends Data
{
    public const int VERSION = 1;

    /**
     * @param  list<BlueprintService>  $services
     * @param  list<BlueprintVariable>  $sharedVariables  project-level variables (`${{shared.KEY}}`)
     */
    public function __construct(
        public string $name,
        public array $services,
        public array $sharedVariables = [],
        public int $version = self::VERSION,
    ) {}

    public function service(string $key): ?BlueprintService
    {
        foreach ($this->services as $service) {
            if ($service->key === $key) {
                return $service;
            }
        }

        return null;
    }
}
