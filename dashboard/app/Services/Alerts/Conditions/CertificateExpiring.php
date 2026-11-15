<?php

declare(strict_types=1);

namespace App\Services\Alerts\Conditions;

use App\Data\Alerts\AlertSignal;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\EnvironmentKind;
use App\Models\Domain;
use App\Support\Hostname;
use App\Support\Links;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Domains of lasting environments whose certificate expires within the
 * team's threshold (Caddy renews 30 days ahead, so renewal is failing).
 */
final readonly class CertificateExpiring extends AlertCondition
{
    public function rule(): AlertRule
    {
        return AlertRule::CertificateExpiring;
    }

    public function resolvedReason(): string
    {
        return 'The certificate was renewed.';
    }

    public function failing(array $rules, array $firing, CarbonImmutable $now): array
    {
        $domains = Domain::query()
            ->whereNotNull('cert_expires_at')
            ->where('cert_expires_at', '<', $now->addDays(29))
            ->whereHas('serviceInstance', static fn (Builder $query) => $query->whereNull('removed_at')
                ->whereHas('environment', static fn (Builder $env) => $env->where('kind', '!=', EnvironmentKind::Preview->value)))
            ->with(['serviceInstance.service', 'serviceInstance.environment.project'])
            ->get();

        $out = [];

        foreach ($domains as $domain) {
            // *.localhost is served by Caddy's internal CA, whose certificates
            // live 12 hours by design: never an expiry problem.
            if (Hostname::isLocal($domain->hostname)) {
                continue;
            }

            $expires = CarbonImmutable::instance($domain->cert_expires_at ?? $now);
            $threshold = $rules[$domain->team_id][AlertRule::CertificateExpiring->value]->thresholdValue();

            if ($expires->greaterThanOrEqualTo($now->addSeconds((int) round($threshold * 86_400)))) {
                continue;
            }

            $out[$domain->team_id.'|domain:'.$domain->public_id] = function () use ($domain, $expires, $now): AlertSignal {
                $instance = $domain->serviceInstance;
                $environment = $instance->environment;
                $days = (int) floor($now->diffInSeconds($expires, false) / 86_400);
                $when = $expires->lessThanOrEqualTo($now) ? 'expired on '.$expires->format('j M Y') : 'expires on '.$expires->format('j M Y').($days >= 1 ? " ({$days} ".($days === 1 ? 'day' : 'days').')' : ' (today)');
                $facts = ['Expires' => $expires->format('j M Y')];

                if (is_string($domain->cert_error) && $domain->cert_error !== '') {
                    $facts['Last error'] = mb_substr($domain->cert_error, 0, 200);
                }

                return new AlertSignal(
                    team: $this->team($domain->team_id),
                    rule: AlertRule::CertificateExpiring,
                    subject: 'domain:'.$domain->public_id,
                    title: "The certificate for {$domain->hostname} is about to expire",
                    summary: "It {$when} and renewing it keeps failing. Check that the domain still points at your servers.",
                    severity: $expires->lessThan($now->addDays(3)) ? AlertSeverity::Critical : AlertSeverity::Warning,
                    href: Links::serviceSettings($instance, 'domains', $domain->hostname),
                    project: $environment->project,
                    environment: $environment,
                    serviceInstance: $instance,
                    facts: $facts,
                );
            };
        }

        return $out;
    }
}
