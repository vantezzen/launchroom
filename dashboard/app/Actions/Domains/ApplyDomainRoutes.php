<?php

declare(strict_types=1);

namespace App\Actions\Domains;

use App\Data\Audit\AuditActor;
use App\Data\Specs\DomainSpec;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Enums\ActorType;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Events\NodeStateInvalidated;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentRevision;
use App\Models\Server;
use App\Models\ServiceInstance;
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

            $max = EnvironmentRevision::query()->where('environment_id', $environment->id)->max('number');
            $revision = EnvironmentRevision::query()->create([
                'team_id' => $environment->team_id,
                'environment_id' => $environment->id,
                'number' => (is_numeric($max) ? (int) $max : 0) + 1,
                'spec' => $spec,
                'message' => mb_substr($message, 0, 500),
                'created_by' => $actor->type === ActorType::User ? $actor->id : null,
                'created_at' => now(),
            ]);

            $environment->forceFill(['last_applied_revision_id' => $revision->id])->save();

            /** @var list<int> $edges */
            $edges = Server::query()
                ->where('team_id', $environment->team_id)
                ->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value, ServerStatus::Offline->value])
                ->whereJsonContains('roles', ServerRole::Edge->value)
                ->pluck('id')
                ->all();

            if ($edges !== []) {
                // ShouldDispatchAfterCommit: rendered once the revision is visible.
                event(new NodeStateInvalidated($edges));
            }

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
