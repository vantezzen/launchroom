<?php

declare(strict_types=1);

namespace App\Actions\Domains;

use App\Data\Audit\AuditActor;
use App\Data\Specs\DomainSpec;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Enums\ActorType;
use App\Events\CanvasChanged;
use App\Events\NodeStateInvalidated;
use App\Jobs\Security\EvaluateSecurityFindingsJob;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\ServiceInstance;
use App\Services\Environments\EnvironmentRevisions;
use Illuminate\Support\Facades\DB;

/**
 * Domain additions and removals apply immediately (maintainer decision
 * 2026-10-19, docs/06 §4): routes are rendered from the environment's last
 * applied revision, so the service's current domains are written into a new
 * revision on top of it (every other staged change stays staged) and the
 * edges re-render. No deployment is needed for a route.
 *
 * Nothing happens while the environment was never deployed or the service
 * is itself still staged (new): its domains go live with that first deploy.
 */
final readonly class ApplyDomainRoutes
{
    public function handle(ServiceInstance $instance, AuditActor $actor, string $message): bool
    {
        return DB::transaction(function () use ($instance, $actor, $message): bool {
            $environment = Environment::query()->whereKey($instance->environment_id)->with('lastAppliedRevision')->lockForUpdate()->firstOrFail();
            $base = $environment->lastAppliedRevision?->spec;
            $instance->loadMissing('service');
            $serviceId = $instance->service->public_id;
            $applied = $base?->service($serviceId);

            if (! $base instanceof EnvironmentSpec || ! $applied instanceof ServiceSpec || $applied->instanceId !== $instance->public_id) {
                return false;
            }

            $domains = [];

            foreach (Domain::query()->where('service_instance_id', $instance->id)->orderBy('hostname')->get() as $domain) {
                $domains[$domain->hostname] = new DomainSpec($domain->public_id, $domain->hostname, $domain->kind, $domain->redirect_to);
            }

            if ($this->same($applied->domains, $domains)) {
                return false;
            }

            $service = clone $applied;
            $service->domains = $domains;
            $spec = clone $base;
            $spec->services = [...$base->services, $serviceId => $service];

            EnvironmentRevisions::apply($environment, $spec, $message, $actor->type === ActorType::User ? $actor->id : null);

            NodeStateInvalidated::edges($environment->team_id);
            CanvasChanged::forInstance($instance);
            // Findings mark components reachable through a public domain (docs/22 §5.5).
            dispatch(new EvaluateSecurityFindingsJob($environment->team_id))->afterCommit();

            return true;
        });
    }

    /**
     * @param  array<string, DomainSpec>  $a
     * @param  array<string, DomainSpec>  $b
     */
    private function same(array $a, array $b): bool
    {
        if (array_keys($a) !== array_keys($b)) {
            return false;
        }

        return array_all($a, fn (DomainSpec $domain, $hostname): bool => $domain->toArray() === $b[$hostname]->toArray());
    }
}
