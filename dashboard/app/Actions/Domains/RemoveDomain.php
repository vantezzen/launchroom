<?php

declare(strict_types=1);

namespace App\Actions\Domains;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\Domain;
use App\Models\ServiceInstance;
use Illuminate\Support\Facades\DB;

/**
 * Removes a domain; the edges stop routing it right away (ApplyDomainRoutes).
 * Domains of the same service that redirect to it (its www redirect) go too.
 */
final readonly class RemoveDomain
{
    public function __construct(
        private RecordAuditEvent $audit,
        private ApplyDomainRoutes $routes,
    ) {}

    public function handle(Domain $domain, AuditActor $actor): void
    {
        DB::transaction(function () use ($domain, $actor): void {
            Domain::query()
                ->where('service_instance_id', $domain->service_instance_id)
                ->where('redirect_to', $domain->hostname)
                ->delete();
            $domain->delete();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DomainRemoved,
                actor: $actor,
                teamId: $domain->team_id,
                subjectType: 'domain',
                subjectId: $domain->public_id,
                meta: ['hostname' => $domain->hostname],
            ));

            $instance = ServiceInstance::query()->find($domain->service_instance_id);

            if ($instance instanceof ServiceInstance) {
                $this->routes->handle($instance, $actor, "Removed {$domain->hostname}");
            }
        });
    }
}
