<?php

declare(strict_types=1);

namespace App\Actions\Previews;

use App\Actions\ConfigAsCode\ApplyPreviewConfigFile;
use App\Actions\Deployments\DeployCommit;
use App\Data\Git\CommitData;
use App\Data\Services\GitSource;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Data\Specs\VariableSpec;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\Previews\PreviewStatus;
use App\Exceptions\UnresolvedReferences;
use App\Jobs\AdvanceDeploymentJob;
use App\Listeners\Git\DeployOnGitPush;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\EnvironmentRevision;
use App\Models\Previews\Preview;
use App\Models\ServiceInstance;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Deployments\DeploymentFactory;
use App\Services\Previews\PreviewDatabases;
use App\Services\Variables\ProductionSecretPolicy;
use App\Services\Variables\SpecVariableResolver;
use App\Support\Changes\SpecDiff;
use App\Support\Git\GitHubAvatar;
use App\Support\Variables\ResolvedEnvironment;
use Illuminate\Support\Facades\DB;

/**
 * Deploys a preview environment (docs/06 §5), idempotently; called when the
 * environment is created, on every new head commit and by the preview tick:
 *
 * 1. The intent is snapshotted into a revision when it changed.
 * 2. Databases (and other image-based services) first: never-deployed ones get a deployment;
 *    until they run (and clones are restored and sanitized,
 *    {@see PreviewDatabases}) the preview stays `preparing`.
 * 3. Then, once per head commit, the apps ({@see DeployCommit}, trigger `pr`):
 *    services whose watch paths match the PR's changed files (plus their
 *    workers/crons and services whose build-time variables reference them)
 *    build the PR head; the others deploy the commit the base environment
 *    runs, so their build key matches and the build step reuses the base
 *    image ("focused previews", docs/04 §7). Unknown changed files: every
 *    service builds the head.
 */
final readonly class DeployPreview
{
    /** Failed deployments of a preview database before the preview fails. */
    private const int MAX_DATABASE_ATTEMPTS = 3;

    public function __construct(
        private CurrentEnvironmentSpec $currentSpec,
        private SpecVariableResolver $resolver,
        private DeploymentFactory $factory,
        private DeployCommit $deployCommit,
        private PreviewDatabases $databases,
        private ProductionSecretPolicy $secretPolicy,
        private ApplyPreviewConfigFile $configFile,
    ) {}

    public function handle(Preview $preview): void
    {
        // The pull request's launchroom.json first (docs/18 §5).
        $configFailure = $preview->status->isOpen() && $preview->status !== PreviewStatus::AwaitingApproval ? $this->configFile->handle($preview) : null;

        if ($configFailure !== null) {
            $preview->transitionTo(PreviewStatus::Failed, $configFailure);

            return;
        }

        [$deployApps, $started] = DB::transaction(function () use ($preview): array {
            $preview = Preview::query()->whereKey($preview->id)->with('project')->lockForUpdate()->first();

            if (! $preview instanceof Preview || ! $preview->status->isOpen() || $preview->environment_id === null || $preview->status === PreviewStatus::AwaitingApproval) {
                return [false, []];
            }

            $environment = Environment::query()->whereKey($preview->environment_id)->with(['project', 'lastAppliedRevision'])->lockForUpdate()->firstOrFail();
            $revision = $this->revision($environment);

            try {
                $envs = $this->resolver->resolveAll($environment->project, $revision->spec);
            } catch (UnresolvedReferences $e) {
                $preview->transitionTo(PreviewStatus::Failed, 'Broken variable references: '.$e->getMessage());

                return [false, []];
            }

            // "Production secrets must differ" (docs/06 §3.1): PR code never
            // runs with production's secrets.
            $violations = $this->secretPolicy->violations($environment->project);

            if ($violations !== []) {
                $preview->transitionTo(PreviewStatus::Failed, implode(' ', $violations));

                return [false, []];
            }

            [$waiting, $started, $failure] = $this->startDatabases($environment, $revision, $envs);

            if ($failure !== null) {
                $preview->transitionTo(PreviewStatus::Failed, $failure);

                return [false, []];
            }

            if ($waiting) {
                $preview->transitionTo(PreviewStatus::Preparing, 'Starting the databases.');

                return [false, $started];
            }

            $ready = $this->databases->advance($preview, $environment);

            if ($ready === null) {
                $preview->transitionTo(PreviewStatus::Failed, $this->failure($preview));

                return [false, []];
            }

            if (! $ready) {
                $preview->transitionTo(PreviewStatus::Preparing, 'Cloning data into the preview’s database.');

                return [false, []];
            }

            if (($preview->data['deployedSha'] ?? null) === $preview->head_sha) {
                if ($preview->status !== PreviewStatus::Running) {
                    $preview->transitionTo(PreviewStatus::Running);
                }

                return [false, []];
            }

            // Claimed under the lock: a concurrent call won't deploy twice.
            $preview->data = [...$preview->data, 'deployedSha' => $preview->head_sha];
            $preview->transitionTo(PreviewStatus::Running);
            $environment->forceFill(['pr_head_sha' => $preview->head_sha, 'pr_head_ref' => $preview->head_ref])->save();

            return [true, []];
        });

        foreach ($started as $deployment) {
            dispatch(new AdvanceDeploymentJob($deployment->id));
        }

        if ($deployApps) {
            $this->deployApps($preview->refresh());
        }
    }

    private function revision(Environment $environment): EnvironmentRevision
    {
        $spec = $this->currentSpec->get($environment);
        $revision = $environment->lastAppliedRevision;

        if ($revision instanceof EnvironmentRevision && SpecDiff::between($revision->spec, $spec)->isEmpty()) {
            return $revision;
        }

        $max = EnvironmentRevision::query()->where('environment_id', $environment->id)->max('number');
        $revision = EnvironmentRevision::query()->create([
            'team_id' => $environment->team_id,
            'environment_id' => $environment->id,
            'number' => (is_numeric($max) ? (int) $max : 0) + 1,
            'spec' => $spec,
            'message' => 'Preview',
            'created_at' => now(),
        ]);
        $environment->forceFill(['last_applied_revision_id' => $revision->id])->save();
        $environment->setRelation('lastAppliedRevision', $revision);

        return $revision;
    }

    /**
     * @param  array<string, ResolvedEnvironment>  $envs
     * @return array{bool, list<Deployment>, string|null} whether a database isn't running yet, new deployments, and why it gave up
     */
    private function startDatabases(Environment $environment, EnvironmentRevision $revision, array $envs): array
    {
        $instances = ServiceInstance::query()->where('environment_id', $environment->id)->get()->keyBy('public_id');
        $waiting = false;
        $started = [];

        foreach ($revision->spec->services as $id => $service) {
            $instance = $instances->get($service->instanceId);

            if (! $service->enabled || $service->kind->isBuilt() || isset($revision->spec->sharedServices[$id]) || ! $instance instanceof ServiceInstance || $instance->active_deployment_id !== null) {
                continue;
            }

            $waiting = true;
            $failed = Deployment::query()->where('service_instance_id', $instance->id)->where('status', DeploymentStatus::Failed->value)->latest('id')->get(['id', 'failure_message']);

            // A failed start is retried by the tick (e.g. a transient daemon
            // error), but not forever.
            if ($failed->count() >= self::MAX_DATABASE_ATTEMPTS) {
                return [true, $started, "{$service->name} did not start: ".($failed->first()->failure_message ?? 'see its deployments')];
            }

            $pending = Deployment::query()
                ->where('service_instance_id', $instance->id)
                ->whereIn('status', DeploymentStatus::values([DeploymentStatus::Queued, ...DeploymentStatus::inFlight()]))
                ->exists();
            $env = $envs[$id] ?? null;

            if (! $pending && $env !== null) {
                $started[] = $this->factory->create($environment->project, $instance, $revision, $revision->spec, $service, $env, DeploymentTrigger::PullRequest, null);
            }
        }

        return [$waiting, $started, null];
    }

    private function deployApps(Preview $preview): void
    {
        $environment = Environment::query()->whereKey((int) $preview->environment_id)->with(['lastAppliedRevision', 'project'])->first();
        $spec = $environment?->lastAppliedRevision?->spec;

        if (! $environment instanceof Environment || ! $spec instanceof EnvironmentSpec) {
            return;
        }

        $changed = $this->changedServices($spec, $preview->changed_files);
        $baseCommits = $this->baseCommits($environment);
        $instances = ServiceInstance::query()->where('environment_id', $environment->id)->with(['service', 'activeDeployment'])->get()->keyBy('public_id');
        $shas = [];

        foreach ($this->appServices($spec) as $id => $service) {
            $parentSha = $service->parentId === null ? null : ($shas[$service->parentId] ?? null);
            $sha = $parentSha ?? (isset($changed[$id]) ? $preview->head_sha : ($baseCommits[$id] ?? $preview->head_sha));
            $shas[$id] = $sha;
            $instance = $instances->get($service->instanceId);

            if (! $instance instanceof ServiceInstance || $instance->activeDeployment?->commit_sha === $sha) {
                continue;
            }

            $ref = $sha === $preview->head_sha ? 'refs/heads/'.$preview->head_ref : 'refs/heads/'.$preview->base_ref;
            $this->deployCommit->handle($instance, new CommitData($sha, mb_substr($preview->title, 0, 500), $preview->author_login, null, $preview->author_login, GitHubAvatar::fromLogin($preview->author_login)), $ref, DeploymentTrigger::PullRequest);
        }
    }

    /**
     * Built (Git-sourced) services, parents before their workers/crons.
     *
     * @return array<string, ServiceSpec>
     */
    private function appServices(EnvironmentSpec $spec): array
    {
        $services = array_filter($spec->services, static fn (ServiceSpec $service): bool => $service->enabled && $service->kind->isBuilt());
        uasort($services, static fn (ServiceSpec $a, ServiceSpec $b): int => ($a->parentId === null ? 0 : 1) <=> ($b->parentId === null ? 0 : 1));

        return $services;
    }

    /**
     * Services the PR touches: watch paths match a changed file, plus
     * (transitively) workers/crons of those and services whose build-time
     * variables reference them.
     *
     * @param  list<string>|null  $files
     * @return array<string, true>
     */
    private function changedServices(EnvironmentSpec $spec, ?array $files): array
    {
        $changed = [];

        foreach ($spec->services as $id => $service) {
            $source = $service->source();

            if ($service->kind->isBuilt() && (! $source instanceof GitSource || DeployOnGitPush::watched($service->build->watchPaths, $source->rootDirectory, $files))) {
                $changed[$id] = true;
            }
        }

        do {
            $grew = false;
            $slugs = array_map(static fn (string $id): string => $spec->services[$id]->slug, array_keys($changed));

            foreach ($spec->services as $id => $service) {
                if (isset($changed[$id]) || ! $service->kind->isBuilt()) {
                    continue;
                }

                $dependent = ($service->parentId !== null && isset($changed[$service->parentId]))
                    || array_any($service->variables, static fn (VariableSpec $variable): bool => $variable->exposure->includesBuild()
                        && array_any($variable->references, static fn (string $reference): bool => in_array(explode('.', $reference, 2)[0], $slugs, true)));

                if ($dependent) {
                    $changed[$id] = true;
                    $grew = true;
                }
            }
        } while ($grew);

        return $changed;
    }

    /**
     * The commit each service's active deployment runs in the base
     * environment, keyed by service public id.
     *
     * @return array<string, string>
     */
    private function baseCommits(Environment $environment): array
    {
        if ($environment->base_environment_id === null) {
            return [];
        }

        $commits = [];
        $base = ServiceInstance::query()
            ->where('environment_id', $environment->base_environment_id)
            ->with(['service', 'activeDeployment'])
            ->get();

        foreach ($base as $instance) {
            $sha = $instance->activeDeployment?->commit_sha;

            if (is_string($sha)) {
                $commits[$instance->service->public_id] = $sha;
            }
        }

        return $commits;
    }

    private function failure(Preview $preview): string
    {
        $databases = $preview->data['databases'] ?? [];

        foreach (is_array($databases) ? $databases : [] as $state) {
            if (is_array($state) && ($state['state'] ?? null) === 'failed' && is_string($state['message'] ?? null)) {
                return $state['message'];
            }
        }

        return 'Preparing the preview’s data failed.';
    }
}
