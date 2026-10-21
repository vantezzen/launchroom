<?php

declare(strict_types=1);

namespace App\Actions\Domains;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\DomainKind;
use App\Exceptions\DomainRuleViolation;
use App\Models\Domain;
use App\Models\ServiceInstance;
use App\Support\Hostname;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * "Redirect www" for a custom domain (docs/05 §5): adds `www.<host>` with
 * `redirect_to = <host>` to the same service (rendered as one route with a
 * www redirect), or removes it. Applies immediately like any domain change.
 */
final readonly class SetWwwRedirect
{
    public function __construct(
        private RecordAuditEvent $audit,
        private ApplyDomainRoutes $routes,
    ) {}

    public function handle(Domain $domain, bool $enabled, AuditActor $actor): void
    {
        if ($domain->kind !== DomainKind::Custom || $domain->redirect_to !== null || str_starts_with($domain->hostname, 'www.')) {
            throw DomainRuleViolation::on('wwwRedirect', 'Only a custom domain without www can redirect www to itself.');
        }

        $www = 'www.'.$domain->hostname;

        if (! Hostname::isValid($www)) {
            throw DomainRuleViolation::on('wwwRedirect', "{$www} is too long for a domain name.");
        }

        try {
            DB::transaction(function () use ($domain, $enabled, $actor, $www): void {
                $existing = Domain::query()
                    ->where('service_instance_id', $domain->service_instance_id)
                    ->where('hostname', $www)
                    ->lockForUpdate()
                    ->first();

                if ($enabled === ($existing instanceof Domain)) {
                    return;
                }

                if ($existing instanceof Domain) {
                    $existing->delete();
                } else {
                    Domain::query()->create([
                        'team_id' => $domain->team_id,
                        'service_instance_id' => $domain->service_instance_id,
                        'hostname' => $www,
                        'kind' => DomainKind::Custom,
                        'redirect_to' => $domain->hostname,
                    ]);
                }

                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::DomainWwwRedirectChanged,
                    actor: $actor,
                    teamId: $domain->team_id,
                    subjectType: 'domain',
                    subjectId: $domain->public_id,
                    meta: ['hostname' => $domain->hostname, 'enabled' => $enabled],
                ));

                $instance = ServiceInstance::query()->find($domain->service_instance_id);

                if ($instance instanceof ServiceInstance) {
                    $this->routes->handle($instance, $actor, $enabled ? "Redirected {$www}" : "Removed the redirect of {$www}");
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw DomainRuleViolation::on('wwwRedirect', "{$www} is already in use by another service.");
        }
    }
}
