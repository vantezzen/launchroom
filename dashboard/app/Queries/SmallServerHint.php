<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Servers\SmallServerHintData;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Whether to show "This server is small" (docs/08 §14): exactly one server
 * of the team can build, and it has fewer CPUs or less memory than
 * `launchroom.server_health.small_build_server_*`. Hidden once the user
 * dismissed it, another build server exists or the server grew, and for
 * users who can't add servers.
 */
final readonly class SmallServerHint
{
    public function for(User $user, ?Team $team): ?SmallServerHintData
    {
        if (! $team instanceof Team || Gate::forUser($user)->denies('create', [Server::class, $team])) {
            return null;
        }

        if (in_array($user->id, InstanceSettings::current()->onboarding['smallServerHintDismissed'] ?? [], true)) {
            return null;
        }

        $builders = Server::query()
            ->where('team_id', $team->id)
            ->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value])
            ->whereJsonContains('roles', ServerRole::Build->value)
            ->limit(2)
            ->get();

        if ($builders->count() !== 1) {
            return null;
        }

        $server = $builders->first();

        $cpus = $server instanceof Server ? (int) $server->facts->cpus : 0;
        $memoryMb = $server instanceof Server ? (int) $server->facts->memoryMb : 0;

        if (! $server instanceof Server || ! self::isSmall($cpus, $memoryMb)) {
            return null;
        }

        return new SmallServerHintData($server->public_id, $server->name, $cpus, $memoryMb);
    }

    /**
     * Unknown values (0) never make a server small.
     */
    public static function isSmall(int $cpus, int $memoryMb): bool
    {
        return ($cpus > 0 && $cpus < config()->integer('launchroom.server_health.small_build_server_cpus'))
            || ($memoryMb > 0 && $memoryMb < config()->integer('launchroom.server_health.small_build_server_memory_mb'));
    }
}
