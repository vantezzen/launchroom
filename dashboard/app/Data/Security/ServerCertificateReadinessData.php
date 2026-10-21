<?php

declare(strict_types=1);

namespace App\Data\Security;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * One server's client-certificate readiness (docs/03 §4.3), observed from
 * its requests. `state`: ready | control_plane | has_certificate (the
 * instance doesn't check yet, so presenting can't be observed) | waiting
 * (no valid certificate) | not_presenting | update (launchroomd too old) |
 * offline.
 */
final class ServerCertificateReadinessData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $team,
        public string $state,
        /** The state doesn't keep "Require" from being turned on. */
        public bool $ready,
        public ?string $fingerprint,
        public ?CarbonInterface $expiresAt,
        public ?CarbonInterface $lastPresentedAt,
        public ?CarbonInterface $lastMissingAt,
        public ?CarbonInterface $reissueUntil,
        /** Certificates are required and this one has none: an owner can allow a new one. */
        public bool $canAllowReissue,
    ) {}
}
