<?php

declare(strict_types=1);

namespace App\Services\Daemon;

use App\Models\ServerClientCertificate;

/**
 * What a signed daemon request showed at the TLS layer (docs/03 §4.3):
 * whether it came through the control-plane edge and, if so, the verified
 * client certificate of the signing server (or none).
 */
final readonly class ClientCertificatePresence
{
    public function __construct(
        /** The instance checks certificates (mode verify or require). */
        public bool $checked,
        /** Arrived through the control-plane edge (a valid X-Lr-Edge). */
        public bool $throughEdge,
        public ?ServerClientCertificate $certificate = null,
    ) {}

    public static function unchecked(): self
    {
        return new self(false, false);
    }

    public function verified(): bool
    {
        return $this->certificate instanceof ServerClientCertificate;
    }
}
