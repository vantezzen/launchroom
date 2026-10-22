<?php

declare(strict_types=1);

namespace App\Actions\Instance;

use App\Data\Domains\DnsCheckData;
use App\Exceptions\DomainRuleViolation;
use App\Models\InstanceSettings;
use App\Services\Dns\DnsCheck;
use App\Services\Dns\EdgeAddresses;
use Illuminate\Support\Facades\DB;

/**
 * "Continue" on the address step of Get started (docs/08 §3.1): checks the
 * wildcard record once more and marks the step done, with whether DNS was
 * verified. "Continue anyway" ($anyway) is allowed while DNS isn't ready;
 * a plain Continue requires a passing check. Changing the domain later
 * reopens the step (the confirmation names the domain).
 */
final readonly class ConfirmAppsDomain
{
    public function __construct(
        private DnsCheck $check,
        private EdgeAddresses $edges,
    ) {}

    public function handle(bool $anyway): DnsCheckData
    {
        $domain = InstanceSettings::current()->apps_domain;

        if (! is_string($domain) || $domain === '') {
            throw DomainRuleViolation::on('appsDomain', 'Save a domain first.');
        }

        // Network calls stay outside the transaction.
        $result = $this->check->wildcard($domain, $this->edges->addresses(null));
        $verified = $result->status === 'ok';

        if (! $verified && ! $anyway) {
            throw DomainRuleViolation::on('appsDomain', 'DNS doesn’t reach your server yet. Check again in a minute, or continue anyway.');
        }

        DB::transaction(static function () use ($domain, $verified): void {
            $settings = InstanceSettings::lockedForUpdate();

            if ($settings->apps_domain !== $domain) {
                return;
            }

            $onboarding = $settings->onboarding ?? [];
            $onboarding['domainConfirmed'] = ['domain' => $domain, 'verified' => $verified, 'at' => now()->toIso8601ZuluString()];
            $settings->forceFill(['onboarding' => $onboarding])->save();
        });

        return $result;
    }
}
