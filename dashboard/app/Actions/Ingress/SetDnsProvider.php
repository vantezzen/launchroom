<?php

declare(strict_types=1);

namespace App\Actions\Ingress;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\DnsProvider;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Models\InstanceSettings;
use App\Services\Ingress\DnsCredentials;
use App\Services\Ingress\EdgeIngress;
use App\Services\Ingress\EdgeServers;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Sets (or with null removes) the DNS provider whose credentials edges use
 * for DNS-01 wildcard certificates of the platform domain (docs/05 §5, D47).
 * The credentials (the provider's fields, DnsProvider::fields) are
 * envelope-encrypted (DnsCredentials) and only ever leave the dashboard
 * sealed to edge servers. A provider change needs every field; keeping the
 * provider and leaving a field empty keeps its stored value. Every edge
 * re-renders (new `ingress.dns`, routes switch to `tls: wildcard`).
 */
final readonly class SetDnsProvider
{
    public function __construct(
        private DnsCredentials $credentials,
        private EdgeIngress $ingress,
        private EdgeServers $edges,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array<string, string|null>  $credentials  field key => value (empty = keep the stored one)
     */
    public function handle(?DnsProvider $provider, #[SensitiveParameter] array $credentials, AuditActor $actor): InstanceSettings
    {
        $given = $provider instanceof DnsProvider ? $this->validated($provider, $credentials) : [];

        return DB::transaction(function () use ($provider, $given, $actor): InstanceSettings {
            $settings = InstanceSettings::lockedForUpdate();
            $before = $settings->dns_provider;
            $replaced = $given !== [];

            if ($provider instanceof DnsProvider) {
                // Stored values only carry over while the provider stays.
                $stored = $before === $provider ? ($this->credentials->get() ?? []) : [];
                $values = [];

                foreach ($provider->fields() as $field) {
                    $value = $given[$field->key] ?? $stored[$field->key] ?? '';

                    if ($value === '') {
                        throw DomainRuleViolation::on('credentials.'.$field->key, "Enter the {$field->label} for {$provider->label()}.");
                    }

                    $values[$field->key] = $value;
                }

                if ($replaced) {
                    $this->credentials->put($values);
                }
            } else {
                $this->credentials->forget();
            }

            $settings->forceFill([
                'dns_provider' => $provider,
                // New credentials or provider need a new check.
                'dns_verified_at' => $replaced || ! $provider instanceof DnsProvider ? null : $settings->dns_verified_at,
                'dns_verification' => $replaced || ! $provider instanceof DnsProvider ? null : $settings->dns_verification,
            ])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DnsProviderChanged,
                actor: $actor,
                subjectType: 'instance',
                subjectId: 'instance',
                // Names of the replaced fields only, never values.
                meta: ['from' => $before?->value, 'to' => $provider?->value, 'tokenReplaced' => $replaced, 'fieldsReplaced' => array_keys($given)],
            ));

            event(new NodeStateInvalidated($this->edges->ids()));

            return $settings;
        });
    }

    /**
     * The non-empty values for the provider's fields, checked by grammar.
     *
     * @param  array<string, string|null>  $credentials
     * @return array<string, string>
     */
    private function validated(DnsProvider $provider, #[SensitiveParameter] array $credentials): array
    {
        if (! $this->ingress->dnsProviderAvailable($provider)) {
            throw DomainRuleViolation::on('provider', "The edge Caddy image has no {$provider->label()} module.");
        }

        $out = [];

        foreach ($provider->fields() as $field) {
            $value = trim((string) ($credentials[$field->key] ?? ''));

            if ($value === '') {
                continue;
            }

            $valid = strlen($value) <= 512 && preg_match('/^[\x21-\x7e]+$/D', $value) === 1
                && ($field->pattern === null || preg_match('/'.$field->pattern.'/D', $value) === 1)
                && ($field->options === null || array_key_exists($value, $field->options));

            if (! $valid) {
                throw DomainRuleViolation::on('credentials.'.$field->key, $field->options === null
                    ? "Paste the {$field->label} exactly as {$provider->label()} shows it (no spaces)."
                    : "Choose the {$field->label}.");
            }

            $out[$field->key] = $value;
        }

        return $out;
    }
}
