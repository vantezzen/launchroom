<?php

declare(strict_types=1);

namespace App\Actions\Instance;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\InstanceSettings;
use App\Support\Hostname;
use Illuminate\Support\Facades\DB;

/**
 * Sets (or clears) the platform domain, `instance_settings.apps_domain`
 * (docs/05 §5): services get `<svc>-<env>.<project>.<apps domain>` through a
 * wildcard record. Only new generated domains use it; existing ones keep
 * their hostname. Cleared, new services fall back to sslip.io.
 */
final readonly class SetAppsDomain
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(?string $domain, AuditActor $actor): InstanceSettings
    {
        if ($domain !== null) {
            $domain = Hostname::normalize(preg_replace('/^\*\./', '', trim($domain)) ?? '');

            if (! Hostname::isValid($domain) || Hostname::isLocal($domain) || substr_count($domain, '.') < 1) {
                throw DomainRuleViolation::on('appsDomain', 'Enter a domain such as apps.example.com (without *. and without a scheme).');
            }

            if (str_ends_with($domain, '.sslip.io') || $domain === 'sslip.io' || str_ends_with($domain, '.nip.io')) {
                throw DomainRuleViolation::on('appsDomain', 'sslip.io is the fallback launchroom uses without a platform domain; enter a domain you own.');
            }

            $dashboardHost = parse_url(config()->string('app.url'), PHP_URL_HOST);

            if (is_string($dashboardHost) && strtolower($dashboardHost) === $domain) {
                throw DomainRuleViolation::on('appsDomain', 'Use a different domain than the dashboard’s, such as apps.'.$domain.', so apps can’t read dashboard cookies.');
            }
        }

        return DB::transaction(function () use ($domain, $actor): InstanceSettings {
            $settings = InstanceSettings::lockedForUpdate();
            $before = $settings->apps_domain;

            if ($before === $domain) {
                return $settings;
            }

            $settings->forceFill(['apps_domain' => $domain])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::AppsDomainChanged,
                actor: $actor,
                subjectType: 'instance',
                subjectId: 'instance',
                meta: ['from' => $before, 'to' => $domain],
            ));

            return $settings;
        });
    }
}
