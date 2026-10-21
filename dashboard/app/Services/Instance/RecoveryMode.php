<?php

declare(strict_types=1);

namespace App\Services\Instance;

use App\Models\InstanceSettings;
use App\Models\Server;

/**
 * Recovery mode (docs/14 §5.2): after `install.sh --restore` the dashboard
 * opens with a checklist until an instance owner finishes it. Entered by
 * `launchroom:servers:bootstrap-local --recovery` (the installer enrolls the
 * new server's launchroomd with it); the previous control-plane server stays
 * in the restored database (no longer marked control plane) until the
 * checklist replaces it.
 */
final class RecoveryMode
{
    public static function active(): bool
    {
        return InstanceSettings::current()->recovery_state !== null;
    }

    /**
     * @return array{startedAt: string, previousControlPlaneServerId?: int|null, replacementId?: int|null}|null
     */
    public static function state(): ?array
    {
        return InstanceSettings::current()->recovery_state;
    }

    public static function enter(?Server $previousControlPlane): void
    {
        $settings = InstanceSettings::current();
        $state = $settings->recovery_state ?? ['startedAt' => now()->toIso8601String()];
        $settings->forceFill(['recovery_state' => [
            ...$state,
            'previousControlPlaneServerId' => $previousControlPlane instanceof Server ? $previousControlPlane->id : ($state['previousControlPlaneServerId'] ?? null),
        ]])->save();
    }

    public static function previousControlPlane(): ?Server
    {
        $id = self::state()['previousControlPlaneServerId'] ?? null;

        return is_int($id) ? Server::query()->whereKey($id)->first() : null;
    }
}
