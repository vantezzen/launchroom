<?php

declare(strict_types=1);

namespace App\Data\Instance;

use App\Models\Membership;
use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * Public Reverb client settings for the browser (docs/08 §8), shared as an
 * Inertia prop so the production image (built without VITE_REVERB_*) can
 * connect. Only public values: the app key identifies the Reverb app, it is
 * not a secret (the secret signs server-side broadcasts and channel auth).
 * Null host/port/scheme mean "the dashboard's own origin".
 */
final class RealtimeConfigData extends Data
{
    public function __construct(
        public string $key,
        public ?string $host,
        public ?int $port,
        /** `http` or `https`. */
        public ?string $scheme,
        /** The signed-in member's own channel id (`members.<id>`) in the current team: deployments they started. */
        public ?string $member = null,
    ) {}

    public static function current(?User $user = null): ?self
    {
        $key = config('broadcasting.connections.reverb.key');

        if (config('broadcasting.default') !== 'reverb' || ! is_string($key) || $key === '') {
            return null;
        }

        $host = config('launchroom.realtime.host');
        $port = config('launchroom.realtime.port');
        $scheme = config('launchroom.realtime.scheme');

        return new self(
            key: $key,
            host: is_string($host) && $host !== '' ? $host : null,
            port: is_numeric($port) && (int) $port > 0 && (int) $port < 65536 ? (int) $port : null,
            scheme: in_array($scheme, ['http', 'https'], true) ? $scheme : null,
            member: self::member($user),
        );
    }

    private static function member(?User $user): ?string
    {
        $teamId = $user?->getAttributes()['current_team_id'] ?? null;

        if (! $user instanceof User || ! is_int($teamId)) {
            return null;
        }

        $member = Membership::query()->where('team_id', $teamId)->where('user_id', $user->id)->value('public_id');

        return is_string($member) ? $member : null;
    }
}
