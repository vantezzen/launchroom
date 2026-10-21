<?php

declare(strict_types=1);

namespace App\Actions\Domains;

use App\Data\Domains\DnsCheckData;
use App\Enums\DomainKind;
use App\Models\Domain;
use App\Services\Dns\DnsCheck;
use App\Services\Dns\EdgeAddresses;

/**
 * Live verification of a custom domain (docs/05 §5): resolves it publicly
 * and compares with the team's edge servers. The result is cached on the
 * domain (`verification`) for the Networking section; it never changes
 * routing (on-demand TLS only issues for names that reach us anyway).
 */
final readonly class VerifyDomain
{
    public function __construct(
        private DnsCheck $check,
        private EdgeAddresses $edges,
    ) {}

    public function handle(Domain $domain): ?DnsCheckData
    {
        if ($domain->kind !== DomainKind::Custom) {
            return null;
        }

        $result = $this->check->hostname($domain->hostname, $this->edges->addresses($domain->team_id));

        $domain->forceFill(['verification' => $result->toArray()])->save();

        return $result;
    }
}
