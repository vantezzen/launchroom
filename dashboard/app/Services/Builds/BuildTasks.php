<?php

declare(strict_types=1);

namespace App\Services\Builds;

use App\Data\Git\RepositoryRef;
use App\Data\Services\BuildSettings;
use App\Data\Services\GitSource;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Data\Specs\VariableSpec;
use App\Enums\DaemonTaskType;
use App\Enums\ServerRole;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Scheduling\BuildRoutingFailed;
use App\Services\Dockerfiles\GeneratedDockerfiles;
use App\Services\Git\GitProviders;
use App\Support\BuildKey;
use App\Support\PublicId;
use App\Support\Variables\SecretTaint;

/**
 * Creates `build` and `plan` daemon tasks (docs/04 §5, §12–13, protocol
 * BuildSpec/PlanSpec). Specs are templates: build variables and clone tokens
 * are only referenced in `daemon_tasks.seal` and sealed to the leasing server
 * at lease time (App\Services\Tasks\TaskSecrets).
 *
 * - required role `build`; routed by BuildRouting (App\Scheduling\BuildRouter):
 *   pinned to the server that built the service last when it is online, of
 *   the platform and has a free slot (BuildKit mount caches are local), else
 *   to the least-loaded build server of the platform with a free slot, else
 *   left to the pool; a pin nobody leases in time is released
 *   (App\Actions\Daemons\ReleaseStuckBuildPins);
 * - platform = the platform of the runtime servers the service may run on
 *   (one platform per image; the scheduler places it on that platform only);
 * - every value is grammar-checked here, because one task the daemon can't
 *   decode would break its whole lease response.
 */
final readonly class BuildTasks
{
    public function __construct(
        private BuildRouting $routing,
        private GitProviders $providers,
        private GeneratedDockerfiles $generated,
    ) {}

    private const string REL_PATH = '/^[A-Za-z0-9._@+=,-]+(?:\/[A-Za-z0-9._@+=,-]+)*$/D';

    private const string SEMVER = '/^v?[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D';

    private const string GIT_URL = GitSource::CLONE_URL_PATTERN;

    private const string GIT_REF = '#^refs/(?:heads|tags)/[A-Za-z0-9._/-]{1,240}$#D';

    private const string ENV_NAME = '/^[A-Za-z_][A-Za-z0-9_]{0,255}$/D';

    /**
     * @throws DomainRuleViolation when the settings can't be turned into a valid spec
     */
    public function build(Deployment $deployment, EnvironmentSpec $environment, ServiceSpec $service, GitSource $source, string $sha): DaemonTask
    {
        try {
            $platform = $this->routing->platformFor($deployment, $service);
        } catch (BuildRoutingFailed $e) {
            throw DomainRuleViolation::on('build', $e->getMessage());
        }

        $inputs = $this->inputs($deployment->team_id, $service->id, $environment->environment->id, $service->build, $this->buildArgs($environment, $service), $source, $sha, $deployment->public_id, $platform, $deployment->git_ref);
        $keys = BuildKey::buildScopedKeys($environment, $service);
        $sticky = Deployment::query()
            ->where('service_instance_id', $deployment->service_instance_id)
            ->whereNotNull('build_server_id')
            ->latest('id')
            ->value('build_server_id');

        return $this->create(
            $deployment->team_id,
            DaemonTaskType::Build,
            [...$inputs['spec'], 'image' => config()->string('launchroom.images.apps_prefix').'/'.strtolower($service->id)],
            [
                ...$inputs['seal'],
                ...($keys === [] ? [] : ['env' => ['deployment' => $deployment->id, 'keys' => $keys]]),
            ],
            $this->routing->serverFor($deployment->team_id, $platform, is_int($sticky) ? $sticky : null),
            config()->integer('launchroom.builds.timeout_seconds'),
            $deployment->id,
        );
    }

    /**
     * A dry-run plan ("we detected…"): build variables are sent as names only.
     *
     * @throws DomainRuleViolation
     */
    public function plan(ServiceInstance $instance, EnvironmentSpec $environment, ServiceSpec $service, GitSource $source, string $sha): DaemonTask
    {
        $inputs = $this->inputs($instance->team_id, $service->id, $environment->environment->id, $service->build, $this->buildArgs($environment, $service), $source, $sha, null, $this->routing->teamPlatform($instance->team_id));
        $keys = BuildKey::buildScopedKeys($environment, $service);

        return $this->create(
            $instance->team_id,
            DaemonTaskType::Plan,
            $inputs['spec'],
            [...$inputs['seal'], ...($keys === [] ? [] : ['env' => ['names' => $keys]])],
            null,
            config()->integer('launchroom.builds.plan_timeout_seconds'),
            null,
        );
    }

    /**
     * A plan for a repository that is not a service yet (the "We detected…"
     * step of adding a service): default build settings, no variables, and a
     * throwaway service id (plan tasks build and push nothing, so the id only
     * namespaces the daemon's scratch space).
     *
     * @throws DomainRuleViolation
     */
    public function previewPlan(int $teamId, string $environmentId, GitSource $source, string $sha): DaemonTask
    {
        $inputs = $this->inputs($teamId, PublicId::generate('svc'), $environmentId, new BuildSettings, [], $source, $sha, null, $this->routing->teamPlatform($teamId));

        return $this->create(
            $teamId,
            DaemonTaskType::Plan,
            $inputs['spec'],
            $inputs['seal'],
            null,
            config()->integer('launchroom.builds.plan_timeout_seconds'),
            null,
        );
    }

    /**
     * Reading a repository for "Generate a Dockerfile" (docs/20 §5 step 1): a
     * plan of the branch head with `inspect`, so launchroomd also reports the
     * file listing and the manifest files (TaskCompletion.inspection), even
     * when planning fails. Build variables go as names only.
     *
     * @throws DomainRuleViolation
     */
    public function inspectPlan(ServiceInstance $instance, EnvironmentSpec $environment, ServiceSpec $service, GitSource $source, string $sha): DaemonTask
    {
        // The repository as it is, whatever builder the service uses now.
        $inputs = $this->inputs($instance->team_id, $service->id, $environment->environment->id, new BuildSettings(railpackVersion: $service->build->railpackVersion), $this->buildArgs($environment, $service), $source, $sha, null, $this->routing->teamPlatform($instance->team_id));
        $keys = BuildKey::buildScopedKeys($environment, $service);

        return $this->create(
            $instance->team_id,
            DaemonTaskType::Plan,
            [...$inputs['spec'], 'inspect' => true],
            [...$inputs['seal'], ...($keys === [] ? [] : ['env' => ['names' => $keys]])],
            null,
            config()->integer('launchroom.builds.plan_timeout_seconds'),
            null,
        );
    }

    /**
     * The verification build of a generated Dockerfile (docs/20 §5 step 3):
     * a normal build with the Dockerfile inline (never in the repository),
     * build variables as names only, tagged with a deployment-shaped id that
     * never becomes a deployments row.
     *
     * @throws DomainRuleViolation
     */
    public function verificationBuild(ServiceInstance $instance, EnvironmentSpec $environment, ServiceSpec $service, GitSource $source, string $sha, string $dockerfile, string $buildId): DaemonTask
    {
        try {
            $platform = $this->routing->teamPlatform($instance->team_id);
        } catch (BuildRoutingFailed $e) {
            throw DomainRuleViolation::on('build', $e->getMessage());
        }

        $inputs = $this->inputs($instance->team_id, $service->id, $environment->environment->id, new BuildSettings(builder: 'dockerfile'), $this->buildArgs($environment, $service), $source, $sha, $buildId, $platform, null, $dockerfile);
        $keys = BuildKey::buildScopedKeys($environment, $service);
        $sticky = Deployment::query()
            ->where('service_instance_id', $instance->id)
            ->whereNotNull('build_server_id')
            ->latest('id')
            ->value('build_server_id');

        return $this->create(
            $instance->team_id,
            DaemonTaskType::Build,
            [...$inputs['spec'], 'image' => config()->string('launchroom.images.apps_prefix').'/'.strtolower($service->id)],
            [...$inputs['seal'], ...($keys === [] ? [] : ['env' => ['names' => $keys]])],
            $this->routing->serverFor($instance->team_id, $platform, is_int($sticky) ? $sticky : null),
            config()->integer('launchroom.builds.timeout_seconds'),
            null,
        );
    }

    /**
     * The clone URL of a source: the plain remote, or what its provider clones.
     *
     * @throws DomainRuleViolation
     */
    public function cloneUrl(int $teamId, GitSource $source): string
    {
        $source->assertUsable();

        if ($source->isUnconnectedPlainGit()) {
            return (string) $source->cloneUrl;
        }

        return $this->providers->forSource($source)->cloneUrl(RepositoryRef::fromSource($teamId, $source));
    }

    /**
     * @param  list<string>  $buildArgs
     * @return array{spec: array<string, mixed>, seal: array<string, mixed>}
     *
     * @throws DomainRuleViolation
     */
    private function inputs(int $teamId, string $serviceId, string $environmentId, BuildSettings $build, array $buildArgs, GitSource $source, string $sha, ?string $deploymentId, string $platform, ?string $ref = null, ?string $dockerfile = null): array
    {
        $cloneUrl = $this->cloneUrl($teamId, $source);

        if (preg_match(self::GIT_URL, $cloneUrl) !== 1 || preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1) {
            throw DomainRuleViolation::on('source', 'The repository URL or commit is not something launchroom can clone.');
        }

        $spec = [
            'service' => $serviceId,
            'environment' => $environmentId,
            ...($deploymentId === null ? [] : ['deployment' => $deploymentId]),
            'repo' => [
                'provider' => $source->provider,
                'cloneUrl' => $cloneUrl,
                'sha' => $sha,
                // Plain git servers may refuse unadvertised commits; launchroomd
                // then fetches this ref instead (docs/19 §6).
                ...($source->provider === GitSource::PROVIDER_GIT && ! $source->isUnconnectedPlainGit() ? $this->fallbackRef($ref, $source) : []),
            ],
            ...($source->rootDirectory === '' ? [] : ['rootDir' => $this->relPath($source->rootDirectory, 'source.rootDirectory')]),
            'builder' => $this->builder($build, $dockerfile ?? $this->generatedDockerfile($teamId, $serviceId, $build)),
            ...($buildArgs === [] ? [] : ['buildArgs' => $buildArgs]),
            'platform' => $platform,
            'cacheKey' => strtolower($serviceId),
        ];

        return [
            'spec' => $spec,
            // Credentials (and SSH host keys) are resolved at lease time.
            'seal' => $source->isUnconnectedPlainGit() ? [] : ['cloneToken' => ['source' => $source->toArray()]],
        ];
    }

    /**
     * @return array{ref?: string}
     */
    private function fallbackRef(?string $ref, GitSource $source): array
    {
        $ref ??= $source->branch === null ? null : 'refs/heads/'.$source->branch;

        return $ref !== null && preg_match(self::GIT_REF, $ref) === 1 && ! str_contains($ref, '..') ? ['ref' => $ref] : [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws DomainRuleViolation
     */
    private function builder(BuildSettings $build, ?string $dockerfile = null): array
    {
        $kind = match ($build->builder) {
            'dockerfile' => 'dockerfile',
            'railpack', 'static' => 'railpack',
            default => 'auto',
        };

        $builder = ['kind' => $kind];

        // A Dockerfile stored in launchroom (docs/20 §5) replaces the repository's.
        if ($kind === 'dockerfile' && $dockerfile !== null) {
            if ($dockerfile === '' || strlen($dockerfile) > 65536 || ! mb_check_encoding($dockerfile, 'UTF-8') || str_contains($dockerfile, "\0")) {
                throw DomainRuleViolation::on('build.generatedDockerfile', 'The stored Dockerfile is not something launchroom can build.');
            }

            return [...$builder, 'dockerfile' => $dockerfile];
        }

        if ($kind !== 'railpack' && $build->dockerfilePath !== null && $build->dockerfilePath !== '') {
            $builder['dockerfilePath'] = $this->relPath($build->dockerfilePath, 'build.dockerfilePath');
        }

        if ($kind === 'dockerfile') {
            return $builder;
        }

        if ($build->builder === 'static') {
            $builder['preset'] = 'static-spa';
        }

        $decisions = array_values(array_unique(array_filter($build->disabledDecisions, static fn (string $id): bool => preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $id) === 1)));

        if ($decisions !== []) {
            $builder['disabledDecisions'] = array_slice($decisions, 0, 64);
        }

        $version = $build->railpackVersion ?? config()->string('launchroom.builds.railpack_version');

        if (preg_match(self::SEMVER, $version) !== 1) {
            throw DomainRuleViolation::on('build.railpackVersion', "{$version} is not a Railpack version.");
        }

        $builder['railpackVersion'] = $version;

        foreach (['installCommand' => $build->installCommand, 'buildCommand' => $build->buildCommand] as $field => $command) {
            if ($command !== null && $command !== '') {
                if (strlen($command) > 4096 || str_contains($command, "\0")) {
                    throw DomainRuleViolation::on("build.{$field}", 'Commands are at most 4096 bytes.');
                }

                $builder[$field] = $command;
            }
        }

        return $builder;
    }

    /**
     * Non-secret build-scoped variables, which may also be Dockerfile build
     * args (docs/09 R15: secrets never are). A config variable whose value
     * references a secret (directly or transitively) counts as a secret: it
     * stays available as a BuildKit secret, never as a build arg (image
     * history would keep it).
     *
     * @return list<string>
     */
    private function buildArgs(EnvironmentSpec $environment, ServiceSpec $service): array
    {
        $taint = SecretTaint::fromSpec($environment);

        $variables = [...array_values($service->variables), ...array_values($service->connectionVariables)];

        foreach ($service->sharedVariableIds as $id) {
            if (isset($environment->sharedVariables[$id])) {
                $variables[] = $environment->sharedVariables[$id];
            }
        }

        $secret = [];
        $config = [];

        foreach ($variables as $variable) {
            /** @var VariableSpec $variable */
            if (! $variable->exposure->includesBuild() || preg_match(self::ENV_NAME, $variable->key) !== 1) {
                continue;
            }

            if ($variable->type === VariableType::Secret || $taint->isTainted($service->slug, $variable->key)) {
                $secret[$variable->key] = true;
            } else {
                $config[$variable->key] = true;
            }
        }

        $names = array_keys(array_diff_key($config, $secret));
        sort($names);

        return array_slice(array_map(strval(...), $names), 0, 256);
    }

    /**
     * The content of the generated Dockerfile the settings reference, when
     * the builder uses it.
     *
     * @throws DomainRuleViolation
     */
    private function generatedDockerfile(int $teamId, string $serviceId, BuildSettings $build): ?string
    {
        if ($build->builder !== 'dockerfile' || $build->generatedDockerfile === null) {
            return null;
        }

        return $this->generated->contentFor($teamId, $serviceId, $build->generatedDockerfile);
    }

    private function relPath(string $path, string $field): string
    {
        $path = trim($path, '/');

        if (preg_match(self::REL_PATH, $path) !== 1 || array_intersect(explode('/', $path), ['.', '..']) !== []) {
            throw DomainRuleViolation::on($field, "{$path} is not a path inside the repository.");
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $seal
     */
    private function create(int $teamId, DaemonTaskType $type, array $spec, array $seal, ?int $pinnedServerId, int $timeout, ?int $deploymentId): DaemonTask
    {
        return DaemonTask::query()->create([
            'team_id' => $teamId,
            'server_id' => $pinnedServerId,
            'pinned_to_server' => $pinnedServerId !== null,
            'required_role' => ServerRole::Build,
            'type' => $type,
            // Objects keep empty maps as `{}` (protocol/README.md).
            'spec' => json_decode(json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR),
            'seal' => $seal === [] ? null : $seal,
            'max_attempts' => config()->integer('launchroom.builds.max_attempts'),
            'timeout_seconds' => $timeout,
            'deployment_id' => $deploymentId,
        ]);
    }
}
