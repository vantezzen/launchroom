<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Data\Services\GitSource;
use App\Enums\DaemonTaskType;
use App\Enums\Security\InventorySource;
use App\Enums\ServerRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Placement;
use App\Services\Builds\BuildTasks;
use App\Support\ServerPlatform;

/**
 * Builds `inventory` tasks (docs/22 §3.2, docs/03 §13.1). Specs are
 * templates: the clone credential of the build's repository is only
 * referenced in `daemon_tasks.seal` (`sourceCloneToken`) and sealed to the
 * leasing server (App\Services\Tasks\TaskSecrets). Required role `build`,
 * leased only by servers of the image's platform that announce the
 * `inventory` feature; 3 attempts, deadline 15 minutes.
 */
final readonly class InventoryTasks
{
    public function __construct(
        private BuildTasks $builds,
    ) {}

    /**
     * The platform of the servers running the deployment.
     */
    public function platform(Deployment $deployment): ?string
    {
        $placement = Placement::query()
            ->where('deployment_id', $deployment->id)
            ->with('server')
            ->orderBy('id')
            ->first();

        return $placement?->server !== null ? ServerPlatform::of($placement->server) : null;
    }

    /**
     * @return array{spec: array<string, mixed>, seal: array<string, mixed>, source: InventorySource, built: bool}|null
     */
    public function spec(Deployment $deployment, string $image, string $platform): ?array
    {
        $apps = rtrim(config()->string('launchroom.images.apps_prefix'), '/').'/';
        $built = str_starts_with($image, $apps);
        $spec = ['image' => $image, 'platform' => $platform];
        $seal = [];

        if ($built) {
            // apps/<service>: the task's pull token covers exactly that repository (docs/03 §4.5).
            $service = $deployment->serviceInstance->service;
            $repository = substr($image, strlen($apps), (int) strpos($image, '@') - strlen($apps));

            if ($repository !== strtolower($service->public_id)) {
                return null;
            }

            $spec['service'] = $service->public_id;
            $source = $service->source;
            $sha = $deployment->commit_sha;

            if ($source instanceof GitSource && is_string($sha) && preg_match('/^[a-f0-9]{40}$/D', $sha) === 1) {
                try {
                    $cloneUrl = $this->builds->cloneUrl($deployment->team_id, $source);
                } catch (DomainRuleViolation) {
                    $cloneUrl = null;
                }

                $root = trim($source->rootDirectory, '/');

                if (is_string($cloneUrl) && preg_match(GitSource::CLONE_URL_PATTERN, $cloneUrl) === 1
                    && ($root === '' || (preg_match('#^[A-Za-z0-9._@+=,-]+(?:/[A-Za-z0-9._@+=,-]+)*$#D', $root) === 1 && array_intersect(explode('/', $root), ['.', '..']) === []))) {
                    $spec['source'] = [
                        'repo' => ['provider' => $source->provider, 'cloneUrl' => $cloneUrl, 'sha' => $sha],
                        ...($root === '' ? [] : ['rootDir' => $root]),
                    ];
                    $seal = $source->isUnconnectedPlainGit() ? [] : ['sourceCloneToken' => ['source' => $source->toArray()]];
                }
            }
        }

        return ['spec' => $spec, 'seal' => $seal, 'source' => $built ? InventorySource::Built : InventorySource::Registry, 'built' => $built];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $seal
     */
    public function create(int $teamId, array $spec, array $seal): DaemonTask
    {
        return DaemonTask::query()->create([
            'team_id' => $teamId,
            'server_id' => null,
            'pinned_to_server' => false,
            'required_role' => ServerRole::Build,
            'type' => DaemonTaskType::Inventory,
            'spec' => json_decode(json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR),
            'seal' => $seal === [] ? null : $seal,
            'max_attempts' => 3,
            'timeout_seconds' => config()->integer('launchroom.security.inventory_timeout_seconds', 900),
        ]);
    }
}
