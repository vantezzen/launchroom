<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\InstanceSettings;

/**
 * Client certificates for daemon transport (mTLS, D50, docs/03 §4.3), per
 * instance: the rollout goes Off → Verify ("Check") → Require.
 */
enum ClientCertificateMode: string
{
    /** The edge doesn't ask for certificates; servers still get one. */
    case Off = 'off';

    /** The edge verifies certificates that are presented; nothing is refused for lacking one. */
    case Verify = 'verify';

    /** Daemon requests without a verified certificate are refused (except enroll, issuance and the control plane's loopback). */
    case Require = 'require';

    /** The instance's mode (Off when never set). */
    public static function current(): self
    {
        return InstanceSettings::current()->daemon_client_certificates ?? self::Off;
    }

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Verify => 'Check',
            self::Require => 'Require',
        };
    }

    /** Whether the edge asks for certificates and the dashboard reads the identity. */
    public function checks(): bool
    {
        return $this !== self::Off;
    }
}
