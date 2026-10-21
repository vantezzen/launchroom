<?php

declare(strict_types=1);

namespace App\Actions\Domains;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\DomainKind;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Models\Domain;
use App\Services\Ingress\EdgeServers;
use Illuminate\Support\Facades\DB;

/**
 * HSTS for a custom domain (docs/05 §5, docs/09 R26): on by default; edges
 * send `Strict-Transport-Security: max-age=31536000` (no includeSubDomains,
 * no preload) once the domain's certificate is issued. Takes effect at once
 * (a response header, not a staged change): the team's edges re-render.
 */
final readonly class SetDomainHsts
{
    public function __construct(
        private EdgeServers $edges,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Domain $domain, bool $enabled, AuditActor $actor): void
    {
        if ($domain->kind !== DomainKind::Custom || $domain->redirect_to !== null) {
            throw DomainRuleViolation::on('hsts', 'HSTS is set on custom domains (a www redirect follows its domain).');
        }

        DB::transaction(function () use ($domain, $enabled, $actor): void {
            $locked = Domain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();

            if ($locked->hsts === $enabled) {
                return;
            }

            $locked->forceFill(['hsts' => $enabled])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DomainHstsChanged,
                actor: $actor,
                teamId: $domain->team_id,
                subjectType: 'domain',
                subjectId: $domain->public_id,
                meta: ['hostname' => $domain->hostname, 'enabled' => $enabled],
            ));

            event(new NodeStateInvalidated($this->edges->ids($domain->team_id)));
        });
    }
}
