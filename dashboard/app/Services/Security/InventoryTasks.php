<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Data\Protocol\V1\Tasks\InventorySource as RepositorySource;
use App\Data\Protocol\V1\Tasks\InventorySpec;
use App\Data\Protocol\V1\Tasks\RepoSpec;
use App\Data\Services\GitSource;
use App\Data\Tasks\CloneSeal;
use App\Data\Tasks\TaskSeal;
use App\Enums\Security\InventorySource;
use App\Enums\ServerRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Placement;
use App\Services\Builds\BuildTasks;
use App\Services\Tasks\DaemonTasks;
use App\Support\Git\GitRef;
use App\Support\Images\AppImage;
use App\Support\Protocol\Grammar;
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
        private DaemonTasks $tasks,
    ) {}

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
     * @return array{spec: InventorySpec, seal: TaskSeal|null, source: InventorySource, built: bool}|null
     */
    public function spec(Deployment $deployment, string $image, string $platform): ?array
    {
        $built = AppImage::isBuilt($image);
        $spec = new InventorySpec($image, $platform);
        $seal = null;

        if ($built) {
            // apps/<service>: the task's pull token covers exactly that repository (docs/03 §4.5).
            $service = $deployment->serviceInstance->service;
            if (AppImage::parse($image)?->belongsTo($service->public_id) !== true) {
                return null;
            }

            $spec->service = $service->public_id;
            $source = $service->source;
            $sha = $deployment->commit_sha;

            if ($source instanceof GitSource && is_string($sha) && GitRef::isSha($sha)) {
                try {
                    $cloneUrl = $this->builds->cloneUrl($deployment->team_id, $source);
                } catch (DomainRuleViolation) {
                    $cloneUrl = null;
                }

                $root = trim($source->rootDirectory, '/');

                if (is_string($cloneUrl) && preg_match(GitSource::CLONE_URL_PATTERN, $cloneUrl) === 1
                    && ($root === '' || (preg_match(Grammar::REL_PATH, $root) === 1 && array_intersect(explode('/', $root), ['.', '..']) === []))) {
                    $spec->source = new RepositorySource(new RepoSpec($source->provider->value, $cloneUrl, $sha), $root === '' ? null : $root);
                    $seal = new TaskSeal(sourceCloneToken: new CloneSeal($source));
                }
            }
        }

        return ['spec' => $spec, 'seal' => $seal, 'source' => $built ? InventorySource::Built : InventorySource::Registry, 'built' => $built];
    }

    public function create(int $teamId, InventorySpec $spec, ?TaskSeal $seal): DaemonTask
    {
        return $this->tasks->create(
            $teamId,
            $spec,
            config()->integer('launchroom.security.inventory_timeout_seconds', 900),
            role: ServerRole::Build,
            seal: $seal,
        );
    }
}
