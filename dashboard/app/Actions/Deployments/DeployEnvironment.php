<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Deployments\DeploymentCommit;
use App\Data\Deployments\DeployResult;
use App\Data\Git\CommitData;
use App\Enums\AuditAction;
use App\Enums\Backups\DeletionRequestStatus;
use App\Enums\ChangeEffect;
use App\Enums\DeploymentTrigger;
use App\Events\CanvasChanged;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\UnresolvedReferences;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\EnvironmentPromotion;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Backups\FinalBackups;
use App\Services\Deployments\DeploymentFactory;
use App\Services\Environments\EnvironmentRevisions;
use App\Services\Ingress\EdgeServers;
use App\Services\Variables\ProductionSecretPolicy;
use App\Services\Variables\SpecVariableResolver;
use App\Support\Changes\SpecDiff;
use Illuminate\Support\Facades\DB;

/**
 * Deploy (docs/06 §4): snapshot the environment's intent into an immutable
 * revision, start a deployment for every service whose staged changes need
 * one (new / restart / rebuild, including restarts propagated through
 * references), tear down removed or disabled services, and re-render routes.
 *
 * Broken references block the deploy with every offending reference listed
 * (docs/06 §3.1), as does a violation of "production secrets must differ
 * from preview" (production and previews).
 *
 * Commits pinned by a promotion with "Also promote images" (docs/06 §6.4) are
 * deployed for their services (trigger `promote`), which reuses the source's
 * image when the build inputs match. `$holdTraffic` stages the deployments of
 * stateless services (docs/06 §6.5): built and started, routed on Promote.
 */
final readonly class DeployEnvironment
{
    public function __construct(
        private CurrentEnvironmentSpec $currentSpec,
        private SpecVariableResolver $resolver,
        private DeploymentFactory $factory,
        private TearDownInstance $teardown,
        private RecordAuditEvent $audit,
        private ProductionSecretPolicy $secretPolicy,
        private FinalBackups $finalBackups,
    ) {}

    /**
     * @param  array<string, array{commit: CommitData, ref: string}>  $commits  service public id → the pushed commit it deploys (a push with launchroom.json changes, docs/18 §3.4)
     */
    public function handle(Environment $environment, ?User $user, AuditActor $actor, ?string $message = null, DeploymentTrigger $trigger = DeploymentTrigger::Config, bool $holdTraffic = false, array $commits = []): DeployResult
    {
        [$result, $servers] = DB::transaction(function () use ($environment, $user, $actor, $message, $trigger, $holdTraffic, $commits): array {
            $environment = Environment::query()->whereKey($environment->id)->with(['project', 'lastAppliedRevision'])->lockForUpdate()->firstOrFail();
            $project = $environment->project;

            $spec = $this->currentSpec->get($environment);
            $base = $environment->lastAppliedRevision?->spec;
            $changes = SpecDiff::between($base, $spec);

            $pinned = EnvironmentPromotion::query()
                ->where('to_environment_id', $environment->id)
                ->whereNull('images_applied_at')
                ->whereRaw("images <> '[]'::jsonb AND images <> '{}'::jsonb")
                ->latest('id')
                ->lockForUpdate()
                ->first();
            $pins = $pinned instanceof EnvironmentPromotion ? $pinned->images->items() : [];

            if ($base !== null && $changes->isEmpty() && $pins === [] && $commits === []) {
                throw DomainRuleViolation::on('environment', 'Nothing to apply: there are no staged changes.');
            }

            $this->secretPolicy->assertFor($environment);

            try {
                $envs = $this->resolver->resolveAll($project, $spec);
            } catch (UnresolvedReferences $e) {
                throw $e->toDomainRuleViolation();
            }

            $revision = EnvironmentRevisions::apply($environment, $spec, $message, $user?->id);

            /** @var array<string, ServiceInstance> $instances */
            $instances = ServiceInstance::query()
                ->where('environment_id', $environment->id)
                ->with('service')
                ->get()
                ->keyBy('public_id')
                ->all();

            $deployments = [];
            $servers = [];
            $routesChanged = false;

            foreach ($spec->services as $id => $service) {
                $effect = $changes->effectFor($id);
                $instance = $instances[$service->instanceId] ?? null;

                if ($instance === null) {
                    continue;
                }

                if (! $service->enabled) {
                    if ($effect === ChangeEffect::Remove) {
                        $servers = [...$servers, ...$this->teardown->handle($instance)];
                    }

                    continue;
                }

                $routesChanged = $routesChanged || $effect !== ChangeEffect::None;

                $pin = $pins[$id] ?? null;
                $pushed = $commits[$id] ?? null;

                if ((! $effect->needsDeployment() && $pin === null && $pushed === null) || ! isset($envs[$id])) {
                    continue;
                }

                [$commit, $deployTrigger] = match (true) {
                    $pushed !== null => [DeploymentCommit::pushed($pushed['commit'], $pushed['ref']), $trigger],
                    $pin === null => [null, $trigger],
                    default => [new DeploymentCommit($pin->commitSha, $pin->gitRef), DeploymentTrigger::Promote],
                };

                $deployments[] = $this->factory->create($project, $instance, $revision, $spec, $service, $envs[$id], $deployTrigger, $user, $commit, $holdTraffic);
            }

            // Services gone from the intent (staged removal or deleted); a
            // removal waits for its final backup (docs/14 §9.13).
            foreach ($base->services ?? [] as $id => $service) {
                if (! isset($spec->services[$id]) && isset($instances[$service->instanceId])) {
                    $finalBackup = $this->finalBackups->assertCleared($instances[$service->instanceId]);
                    $servers = [...$servers, ...$this->teardown->handle($instances[$service->instanceId])];
                    $finalBackup?->transitionTo(DeletionRequestStatus::Completed);
                    $routesChanged = true;
                }
            }

            if ($routesChanged || $servers !== []) {
                // Domains apply with the revision (no deployment): edges re-render.
                $servers = [...$servers, ...EdgeServers::ids($environment->team_id)];
            }

            $pinned?->forceFill(['images_applied_at' => now()])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::EnvironmentDeployed,
                actor: $actor,
                teamId: $environment->team_id,
                subjectType: 'environment',
                subjectId: $environment->public_id,
                meta: [
                    'revision' => $revision->number,
                    'deployments' => array_map(static fn (Deployment $deployment): string => $deployment->public_id, $deployments),
                    'changes' => count($changes->items),
                    ...($pins === [] ? [] : ['promotedImages' => array_keys($pins)]),
                    ...($holdTraffic ? ['staged' => true] : []),
                ],
            ));

            return [new DeployResult($revision, $deployments), array_values(array_unique($servers))];
        });

        NodeStateInvalidated::servers($servers);
        event(new CanvasChanged($environment->public_id));

        return $result;
    }
}
