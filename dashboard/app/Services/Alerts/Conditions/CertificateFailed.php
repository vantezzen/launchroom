<?php

declare(strict_types=1);

namespace App\Services\Alerts\Conditions;

use App\Data\Alerts\AlertSignal;
use App\Data\Domains\CertificateData;
use App\Enums\Alerts\AlertRule;
use App\Enums\CertFailureKind;
use App\Enums\CertStatus;
use App\Enums\Domains\DnsCheckStatus;
use App\Enums\EnvironmentKind;
use App\Models\Domain;
use App\Support\Hostname;
use App\Support\Links;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Domains of lasting environments that still have no certificate
 * WAIT_MINUTES after their DNS points at the servers, or right away when the
 * certificate authority's rate limit was hit (Report certificate
 * `failureKind`, G16). Until DNS is right, the domain page's DNS step is
 * the problem, not the certificate. How long DNS has been right lives in the
 * cache (≤ 2 h); losing it only delays an alert.
 */
final readonly class CertificateFailed extends AlertCondition
{
    public const int WAIT_MINUTES = 30;

    public function __construct(
        private Cache $cache,
    ) {}

    public function rule(): AlertRule
    {
        return AlertRule::CertificateFailed;
    }

    public function resolvedReason(): string
    {
        return 'The certificate was issued.';
    }

    public function failing(array $rules, array $firing, CarbonImmutable $now): array
    {
        $domains = Domain::query()
            ->where(static fn (Builder $query) => $query->whereNull('cert_status')->orWhere('cert_status', '!=', CertStatus::Issued->value))
            ->where(static fn (Builder $query) => $query->whereNotNull('cert_error')->orWhereNotNull('cert_failure_kind'))
            ->whereHas('serviceInstance', static fn (Builder $query) => $query->whereNull('removed_at')
                ->whereHas('environment', static fn (Builder $env) => $env->where('kind', '!=', EnvironmentKind::Preview->value)))
            ->with(['serviceInstance.service', 'serviceInstance.environment.project'])
            ->get();

        $out = [];

        foreach ($domains as $domain) {
            if (Hostname::isLocal($domain->hostname)) {
                continue;
            }

            $limited = $domain->cert_failure_kind === CertFailureKind::RateLimited;
            $dnsOk = in_array($domain->verification?->status, [DnsCheckStatus::Ok, DnsCheckStatus::Proxied], true);
            $key = "alerts:cert-dns-ok:{$domain->id}";

            if ($dnsOk) {
                $since = $this->cache->get($key);
                $since = is_int($since) ? $since : $now->getTimestamp();
                $this->cache->put($key, $since, 2 * 3600);
            } else {
                $this->cache->forget($key);
                $since = null;
            }

            $waited = $since !== null && $now->getTimestamp() - $since >= self::WAIT_MINUTES * 60;
            $subject = 'domain:'.$domain->public_id;

            if (! $limited && ! $waited && ! isset($firing[$domain->team_id.'|'.$subject])) {
                continue;
            }

            $out[$domain->team_id.'|'.$subject] = function () use ($domain, $subject): AlertSignal {
                $instance = $domain->serviceInstance;
                $environment = $instance->environment;
                $problem = CertificateData::problem($domain) ?? 'Issuing it keeps failing.';
                $facts = ['Domain' => $domain->hostname];

                if ($domain->cert_retry_after?->isFuture() === true) {
                    $facts['Next try'] = $domain->cert_retry_after->format('j M H:i').' UTC';
                }

                return new AlertSignal(
                    team: $this->team($domain->team_id),
                    rule: AlertRule::CertificateFailed,
                    subject: $subject,
                    title: "{$domain->hostname} has no certificate",
                    summary: mb_substr("Visitors get a certificate warning. {$problem}", 0, 1000),
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
