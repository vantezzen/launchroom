<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Spatie\LaravelData\Data;

/**
 * `instance_settings.recovery_state`: recovery mode after `install --restore`
 * (docs/14 §5.2), with the previous control-plane server and the
 * replacement planned for it.
 */
final class RecoveryStateData extends Data
{
    public function __construct(
        public string $startedAt,
        public ?int $previousControlPlaneServerId = null,
        public ?int $replacementId = null,
    ) {}
}
