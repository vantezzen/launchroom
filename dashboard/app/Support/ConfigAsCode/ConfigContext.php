<?php

declare(strict_types=1);

namespace App\Support\ConfigAsCode;

use App\Data\Services\DatabaseSource;
use App\Enums\ServiceKind;
use App\Models\Project;
use App\Models\Service;

/**
 * What the rules of a launchroom.json are checked against (docs/18 §6): the
 * project's existing services (by slug) and environments.
 */
final readonly class ConfigContext
{
    /**
     * @param  array<string, array{kind: ServiceKind, engine: string|null, version: string|null}>  $services
     * @param  list<string>  $environments  environment slugs (persistent and production)
     */
    public function __construct(
        public array $services = [],
        public array $environments = [],
    ) {}

    public static function forProject(Project $project): self
    {
        $services = [];

        foreach ($project->services()->get(['id', 'slug', 'kind', 'source']) as $service) {
            /** @var Service $service */
            $source = $service->source;
            $services[$service->slug] = [
                'kind' => $service->kind,
                'engine' => $source instanceof DatabaseSource ? $source->engine->value : null,
                'version' => $source instanceof DatabaseSource ? $source->version : null,
            ];
        }

        /** @var list<string> $environments */
        $environments = $project->environments()->where('kind', '!=', 'preview')->pluck('slug')->all();

        return new self($services, $environments);
    }
}
