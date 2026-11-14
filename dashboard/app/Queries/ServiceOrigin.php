<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Canvas\ServiceOriginData;
use App\Data\Git\RepositoryRef;
use App\Data\Services\DatabaseSource;
use App\Data\Services\GitSource;
use App\Data\Services\ImageSource;
use App\Models\ServiceInstance;
use App\Services\Git\GitProviders;
use App\Support\Canvas\ServiceGlyph;
use App\Support\Images\ImageReference;
use Throwable;

/**
 * The service panel's source link (docs/08 §13): what a service is built or
 * pulled from, with a link to the provider's or registry's page when there
 * is one. Reads no remote API.
 */
final readonly class ServiceOrigin
{
    public function __construct(private GitProviders $providers) {}

    public function for(ServiceInstance $instance): ServiceOriginData
    {
        $instance->loadMissing(['service.parent', 'environment']);
        $service = $instance->service;
        $source = $service->source;

        if ($service->kind->requiresParent()) {
            return new ServiceOriginData('process', ServiceGlyph::subtitle($service));
        }

        return match (true) {
            $source instanceof GitSource => $this->repository($instance, $source),
            $source instanceof ImageSource => $this->image($source),
            $source instanceof DatabaseSource => new ServiceOriginData('database', $source->engine->label().' '.$source->version),
            default => new ServiceOriginData('process', ServiceGlyph::subtitle($service)),
        };
    }

    private function repository(ServiceInstance $instance, GitSource $source): ServiceOriginData
    {
        $branch = $this->branch($instance, $source);
        $href = null;

        if (! $source->isUnconnectedPlainGit()) {
            try {
                $href = $this->providers->forSource($source)->repositoryUrl(RepositoryRef::fromSource($instance->team_id, $source), $branch);
            } catch (Throwable) {
                // A missing connection: the name stays, without a link.
            }
        }

        return new ServiceOriginData('repository', $source->fullName, $href, $branch, $source->kind()->label());
    }

    /**
     * The branch it deploys, as far as it is known without asking the
     * provider: the override, the environment's branch (not a pattern),
     * then the source's own.
     */
    private function branch(ServiceInstance $instance, GitSource $source): ?string
    {
        foreach ([$instance->branch_override, $instance->environment->triggers->branch, $source->branch] as $branch) {
            if (is_string($branch) && $branch !== '' && strpbrk($branch, '*?[') === false) {
                return $branch;
            }
        }

        return null;
    }

    private function image(ImageSource $source): ServiceOriginData
    {
        $label = ServiceGlyph::shortImage($source->reference);
        $parsed = ImageReference::tryParse($source->reference);

        if (! $parsed instanceof ImageReference) {
            return new ServiceOriginData('image', $label);
        }

        return match ($parsed->registry) {
            'docker.io' => new ServiceOriginData('image', $label, str_starts_with($parsed->repository, 'library/')
                ? 'https://hub.docker.com/_/'.substr($parsed->repository, strlen('library/'))
                : 'https://hub.docker.com/r/'.$parsed->repository, host: 'Docker Hub'),
            'quay.io' => new ServiceOriginData('image', $label, 'https://quay.io/repository/'.$parsed->repository, host: 'Quay'),
            default => new ServiceOriginData('image', $label, host: $parsed->registry),
        };
    }
}
