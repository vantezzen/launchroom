<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\Sessions\InteractiveSession;
use App\Support\PublicId;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Every private channel, named `<value>.<public id>` (Pusher adds
 * "private-" on the wire). routes/channels.php authorizes the browser ones;
 * daemons join theirs only through the signed broadcasting auth (docs/03 §4,
 * §11.2). The TS twin is lib/realtime.ts over the generated type.
 */
enum Channel: string
{
    case Environment = 'environments';
    case Deployment = 'deployments';
    /** Server usage nudges of one team. */
    case TeamServers = 'servers';
    /** Security findings nudges of one team. */
    case TeamSecurity = 'security';
    /** Backup runs and destinations of one team (and launchroom's own backups, for instance owners). */
    case TeamBackups = 'backups';
    /** One member's own nudges (the deployments they started), keyed by the membership's public id. */
    case Member = 'members';
    case Daemon = 'daemon';
    case Session = 'session';

    public function name(string $publicId): string
    {
        return $this->value.'.'.$publicId;
    }

    public function private(string $publicId): PrivateChannel
    {
        return new PrivateChannel($this->name($publicId));
    }

    /** The channel as Pusher signs it. */
    public function wire(string $publicId): string
    {
        return 'private-'.$this->name($publicId);
    }

    /** The routes/channels.php pattern; the parameter resolves like the route parameter of the same name. */
    public function pattern(): string
    {
        return $this->value.'.{'.$this->parameter().'}';
    }

    /** The session id of a `private-session.<ses>` wire name, or null. */
    public static function sessionFromWire(string $channel): ?string
    {
        $prefix = 'private-'.self::Session->value.'.';
        $id = str_starts_with($channel, $prefix) ? substr($channel, strlen($prefix)) : '';

        return PublicId::isValid($id, InteractiveSession::publicIdPrefix()) ? $id : null;
    }

    private function parameter(): string
    {
        return match ($this) {
            self::Environment => 'environment',
            self::Deployment => 'deployment',
            self::TeamServers, self::TeamSecurity, self::TeamBackups => 'team',
            self::Member => 'member',
            self::Daemon, self::Session => 'id',
        };
    }
}
