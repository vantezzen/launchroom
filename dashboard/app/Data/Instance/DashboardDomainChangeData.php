<?php

declare(strict_types=1);

namespace App\Data\Instance;

use App\Data\Domains\DnsCheckData;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A dashboard domain being set up (pending or ready, docs/05 §4.1).
 */
final class DashboardDomainChangeData extends Data
{
    /**
     * @param  list<DashboardDomainStepData>  $steps  dns, certificate, https
     */
    public function __construct(
        public string $hostname,
        public string $url,
        /** `pending` or `ready`. */
        public string $status,
        public array $steps,
        public ?DnsCheckData $dns,
        public ?DashboardProbeData $probe,
        public ?DashboardCertificateData $certificate,
        public DashboardSwitchImpactData $impact,
        public ?CarbonInterface $checkedAt,
        public ?CarbonInterface $readyAt,
        public ?CarbonInterface $createdAt,
    ) {}
}
