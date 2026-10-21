<?php

declare(strict_types=1);

namespace App\Data\Audit;

use App\Enums\ActorType;
use App\Models\Server;
use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * Who did something, and from where. Built at the entry point (controller,
 * daemon middleware, console) and passed into actions, which never read the
 * request themselves.
 */
final class AuditActor extends Data
{
    public function __construct(
        public ActorType $type,
        public ?int $id = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
        /** Public id of the API token the user acted through (docs/16 §1.1). */
        public ?string $token = null,
    ) {}

    public static function user(User $user, ?string $ip = null, ?string $userAgent = null): self
    {
        $token = $user->currentApiToken();

        return new self(ActorType::User, $user->id, $ip, self::truncate($userAgent), $token?->public_id);
    }

    public static function server(Server $server, ?string $ip = null): self
    {
        return new self(ActorType::Server, $server->id, $ip);
    }

    public static function system(): self
    {
        return new self(ActorType::System);
    }

    private static function truncate(?string $userAgent): ?string
    {
        return $userAgent === null ? null : mb_substr($userAgent, 0, 512);
    }
}
