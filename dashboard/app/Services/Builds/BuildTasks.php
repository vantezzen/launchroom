<?php

declare(strict_types=1);

namespace App\Services\Builds;

use App\Data\Git\RepositoryRef;
use App\Data\Protocol\V1\PhpExtension;
use App\Data\Protocol\V1\Tasks\BuilderSpec;
use App\Data\Protocol\V1\Tasks\BuildInputs;
use App\Data\Protocol\V1\Tasks\BuildSpec;
use App\Data\Protocol\V1\Tasks\PlanSpec;
use App\Data\Protocol\V1\Tasks\RepoSpec;
use App\Data\Services\BuildSettings;
use App\Data\Services\GitSource;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Data\Specs\VariableSpec;
use App\Data\Tasks\CloneSeal;
use App\Data\Tasks\EnvSeal;
use App\Data\Tasks\TaskSeal;
use App\Enums\ServerRole;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Scheduling\BuildRoutingFailed;
use App\Services\Dockerfiles\GeneratedDockerfiles;
use App\Services\Git\GitProviders;
use App\Services\Git\Providers\PlainGitProvider;
use App\Services\Tasks\DaemonTasks;
use App\Services\Variables\SpecVariableResolver;
use App\Support\BuildKey;
use App\Support\Builds\PhpExtensions;
use App\Support\Git\GitRef;
use App\Support\Images\AppImage;
use App\Support\Protocol\Grammar;
use App\Support\PublicId;
use App\Support\Variables\VariableKey;

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
        private DaemonTasks $tasks,
        private SpecVariableResolver $variables,
    ) {}

    private const string GIT_URL = GitSource::CLONE_URL_PATTERN;

    private const string GIT_REF = '#^refs/(?:heads|tags)/[A-Za-z0-9._/-]{1,240}$#D';

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

        $inputs = $this->inputs($deployment->team_id, $service->id, $environment->environment->id, $service->build, $this->buildArgs($deployment->environment->project, $environment, $service), $source, $sha, $deployment->public_id, $platform, $deployment->git_ref, phpExtensions: $service->phpExtensions);
        $keys = BuildKey::buildScopedKeys($environment, $service);

        return $this->tasks->create(
            $deployment->team_id,
            new BuildSpec($inputs, $this->image($service->id)),
            config()->integer('launchroom.builds.timeout_seconds'),
            pin: $this->buildServer($deployment->team_id, $platform, $this->sticky($deployment->service_instance_id)),
            role: ServerRole::Build,
            seal: new TaskSeal(
                env: $keys === [] ? null : EnvSeal::deployment($deployment->id, $keys),
                cloneToken: new CloneSeal($source),
            ),
            deploymentId: $deployment->id,
        );
    }

    /**
     * A dry-run plan ("we detected…"): build variables are sent as names only.
     *
     * @throws DomainRuleViolation
     */
    public function plan(ServiceInstance $instance, EnvironmentSpec $environment, ServiceSpec $service, GitSource $source, string $sha): DaemonTask
    {
        $inputs = $this->inputs($instance->team_id, $service->id, $environment->environment->id, $service->build, $this->buildArgs($instance->environment->project, $environment, $service), $source, $sha, null, $this->routing->teamPlatform($instance->team_id), phpExtensions: $service->phpExtensions);

        return $this->plannedBy($instance->team_id, new PlanSpec($inputs), $source, BuildKey::buildScopedKeys($environment, $service));
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

        return $this->plannedBy($teamId, new PlanSpec($inputs), $source, []);
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
        $inputs = $this->inputs($instance->team_id, $service->id, $environment->environment->id, new BuildSettings(railpackVersion: $service->build->railpackVersion), $this->buildArgs($instance->environment->project, $environment, $service), $source, $sha, null, $this->routing->teamPlatform($instance->team_id));

        return $this->plannedBy($instance->team_id, new PlanSpec($inputs, inspect: true), $source, BuildKey::buildScopedKeys($environment, $service));
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

        $inputs = $this->inputs($instance->team_id, $service->id, $environment->environment->id, new BuildSettings(builder: 'dockerfile'), $this->buildArgs($instance->environment->project, $environment, $service), $source, $sha, $buildId, $platform, null, $dockerfile);
        $keys = BuildKey::buildScopedKeys($environment, $service);

        return $this->tasks->create(
            $instance->team_id,
            new BuildSpec($inputs, $this->image($service->id)),
            config()->integer('launchroom.builds.timeout_seconds'),
            pin: $this->buildServer($instance->team_id, $platform, $this->sticky($instance->id)),
            role: ServerRole::Build,
            seal: new TaskSeal(env: $keys === [] ? null : EnvSeal::names($keys), cloneToken: new CloneSeal($source)),
        );
    }

    /**
     * @throws DomainRuleViolation
     */
    public function cloneUrl(int $teamId, GitSource $source): string
    {
        $source->assertUsable();

        return $this->providers->forSource($source)->cloneUrl(RepositoryRef::fromSource($teamId, $source));
    }

    /**
     * @param  list<string>  $buildArgs
     * @param  list<PhpExtension>  $phpExtensions
     *
     * @throws DomainRuleViolation
     */
    private function inputs(int $teamId, string $serviceId, string $environmentId, BuildSettings $build, array $buildArgs, GitSource $source, string $sha, ?string $deploymentId, string $platform, ?string $ref = null, ?string $dockerfile = null, array $phpExtensions = []): BuildInputs
    {
        $cloneUrl = $this->cloneUrl($teamId, $source);

        if (preg_match(self::GIT_URL, $cloneUrl) !== 1 || ! GitRef::isSha($sha)) {
            throw DomainRuleViolation::on('source', 'The repository URL or commit is not something launchroom can clone.');
        }

        $builder = $this->builder($build, $dockerfile ?? $this->generatedDockerfile($teamId, $serviceId, $build));
        $builder->phpExtensions = $this->phpExtensions($phpExtensions);

        return new BuildInputs(
            service: $serviceId,
            environment: $environmentId,
            repo: new RepoSpec(
                provider: $source->provider->value,
                cloneUrl: $cloneUrl,
                sha: $sha,
                // Plain git servers may refuse unadvertised commits; launchroomd
                // then fetches this ref instead (docs/19 §6).
                ref: $this->providers->forSource($source) instanceof PlainGitProvider ? $this->fallbackRef($ref, $source) : null,
            ),
            builder: $builder,
            platform: $platform,
            cacheKey: strtolower($serviceId),
            deployment: $deploymentId,
            rootDir: $source->rootDirectory === '' ? null : $this->relPath($source->rootDirectory, 'source.rootDirectory'),
            buildArgs: $buildArgs === [] ? null : $buildArgs,
        );
    }

    /**
     * @param  list<string>  $keys
     */
    private function plannedBy(int $teamId, PlanSpec $spec, GitSource $source, array $keys): DaemonTask
    {
        return $this->tasks->create(
            $teamId,
            $spec,
            config()->integer('launchroom.builds.plan_timeout_seconds'),
            role: ServerRole::Build,
            seal: new TaskSeal(env: $keys === [] ? null : EnvSeal::names($keys), cloneToken: new CloneSeal($source)),
        );
    }

    /**
     * The build server routing picked (BuildRouting), null: left to the pool.
     */
    private function buildServer(int $teamId, string $platform, ?int $stickyId): ?Server
    {
        $id = $this->routing->serverFor($teamId, $platform, $stickyId);

        return $id === null ? null : Server::query()->find($id);
    }

    private function image(string $serviceId): string
    {
        return AppImage::nameFor($serviceId);
    }

    /**
     * The server that built the instance last: BuildKit mount caches are local.
     */
    private function sticky(int $instanceId): ?int
    {
        $server = Deployment::query()
            ->where('service_instance_id', $instanceId)
            ->whereNotNull('build_server_id')
            ->latest('id')
            ->value('build_server_id');

        return is_int($server) ? $server : null;
    }

    private function fallbackRef(?string $ref, GitSource $source): ?string
    {
        $ref ??= $source->branch === null ? null : 'refs/heads/'.$source->branch;

        return $ref !== null && preg_match(self::GIT_REF, $ref) === 1 && ! str_contains($ref, '..') ? $ref : null;
    }

    /**
     * @throws DomainRuleViolation
     */
    private function builder(BuildSettings $build, ?string $dockerfile = null): BuilderSpec
    {
        $kind = match ($build->builder) {
            'dockerfile' => 'dockerfile',
            'railpack', 'static' => 'railpack',
            default => 'auto',
        };

        // A Dockerfile stored in launchroom (docs/20 §5) replaces the repository's.
        if ($kind === 'dockerfile' && $dockerfile !== null) {
            if ($dockerfile === '' || strlen($dockerfile) > 65536 || ! mb_check_encoding($dockerfile, 'UTF-8') || str_contains($dockerfile, "\0")) {
                throw DomainRuleViolation::on('build.generatedDockerfile', 'The stored Dockerfile is not something launchroom can build.');
            }

            return new BuilderSpec($kind, dockerfile: $dockerfile);
        }

        $builder = new BuilderSpec(
            $kind,
            dockerfilePath: $kind !== 'railpack' && $build->dockerfilePath !== null && $build->dockerfilePath !== '' ? $this->relPath($build->dockerfilePath, 'build.dockerfilePath') : null,
        );

        if ($kind === 'dockerfile') {
            return $builder;
        }

        if ($build->builder === 'static') {
            $builder->preset = 'static-spa';
        }

        $decisions = array_values(array_unique(array_filter($build->disabledDecisions, static fn (string $id): bool => preg_match(Grammar::SLUG, $id) === 1)));
        $builder->disabledDecisions = $decisions === [] ? null : array_slice($decisions, 0, 64);

        $version = $build->railpackVersion ?? config()->string('launchroom.builds.railpack_version');

        if (preg_match(Grammar::SEMVER, $version) !== 1) {
            throw DomainRuleViolation::on('build.railpackVersion', "{$version} is not a Railpack version.");
        }

        $builder->railpackVersion = $version;

        foreach (['installCommand' => $build->installCommand, 'buildCommand' => $build->buildCommand] as $field => $command) {
            if ($command !== null && $command !== '') {
                if (strlen($command) > 4096 || str_contains($command, "\0")) {
                    throw DomainRuleViolation::on("build.{$field}", 'Commands are at most 4096 bytes.');
                }

                $builder->{$field} = $command;
            }
        }

        return $builder;
    }

    /**
     * PHP database drivers for the connected databases (docs/04 §3.1): every
     * builder kind carries them (Dockerfile builds only warn). Checked against
     * the allow-list again: a name launchroomd refuses would break its lease.
     *
     * @param  list<PhpExtension>  $extensions
     * @return list<PhpExtension>|null
     *
     * @throws DomainRuleViolation
     */
    private function phpExtensions(array $extensions): ?array
    {
        $list = [];

        foreach ($extensions as $extension) {
            if (! in_array($extension->name, PhpExtensions::NAMES, true) || ! in_array($extension->for, PhpExtensions::ENGINES, true) || isset($list[$extension->name])) {
                throw DomainRuleViolation::on('build.phpExtensions', "{$extension->name} is not a PHP extension launchroom adds.");
            }

            $list[$extension->name] = new PhpExtension($extension->name, $extension->for);
        }

        return $list === [] ? null : array_values(array_slice($list, 0, 8));
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
    private function buildArgs(Project $project, EnvironmentSpec $environment, ServiceSpec $service): array
    {
        $taint = $this->variables->taint($project, $environment);

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
            if (! $variable->exposure->includesBuild() || ! VariableKey::isValid($variable->key)) {
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

        if (preg_match(Grammar::REL_PATH, $path) !== 1 || array_intersect(explode('/', $path), ['.', '..']) !== []) {
            throw DomainRuleViolation::on($field, "{$path} is not a path inside the repository.");
        }

        return $path;
    }
}
