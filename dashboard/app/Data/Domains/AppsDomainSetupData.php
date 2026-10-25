<?php

declare(strict_types=1);

namespace App\Data\Domains;

use Spatie\LaravelData\Data;

/**
 * The platform domain as Settings → Network shows it (docs/05 §5): the
 * domain, the addresses its wildcard record points at, the free sslip.io
 * wildcard used without one, and whether the owner's last confirmation
 * found DNS reaching the edges. Onboarding shows the same picker from
 * `OnboardingData`.
 */
final class AppsDomainSetupData extends Data
{
    /**
     * @param  list<string>  $addresses  public addresses the wildcard record points at
     */
    public function __construct(
        public ?string $domain,
        public array $addresses,
        /** "*.203-0-113-7.sslip.io", null without a public IPv4 address. */
        public ?string $freeWildcard,
        public string $dashboardHost,
        /** Null until the owner confirmed this domain (Get started or here). */
        public ?bool $verified,
    ) {}
}
