<?php

declare(strict_types=1);

namespace App\Data\Security;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * Settings → Security → Server certificates (docs/03 §4.3): the instance
 * mode (off | verify | require), the CA and every server's readiness.
 */
final class ClientCertificatesOverviewData extends Data
{
    /**
     * @param  list<ServerCertificateReadinessData>  $servers
     */
    public function __construct(
        public string $mode,
        public ?CarbonInterface $changedAt,
        public ?string $caFingerprint,
        public ?CarbonInterface $caExpiresAt,
        public int $lifetimeDays,
        public array $servers,
        public int $readyCount,
        /** Servers that would be refused if certificates were required now. */
        public int $blockingCount,
    ) {}
}
