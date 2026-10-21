<?php

declare(strict_types=1);

namespace App\Data\Sessions;

use App\Enums\Sessions\InteractiveSessionKind;
use Spatie\LaravelData\Data;

/**
 * The answer to opening a session: everything the browser needs to join
 * once. `ticket` and `key` are shown only here (docs/03 §11.2).
 */
final class OpenedSessionData extends Data
{
    public function __construct(
        public string $id,
        public InteractiveSessionKind $kind,
        /** Pusher channel name without the `private-` prefix. */
        public string $channel,
        public string $ticket,
        /** 32-byte session key, base64url without padding. */
        public string $key,
        public int $idleSeconds,
        public int $maxSeconds,
        public bool $record,
        public SessionReplicaData $replica,
        public ?string $shell,
        public ?string $engine,
    ) {}
}
