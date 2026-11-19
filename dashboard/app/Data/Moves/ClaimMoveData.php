<?php

declare(strict_types=1);

namespace App\Data\Moves;

use App\Data\Attributes\PublicIdOf;
use App\Support\Crypto\OpaqueToken;
use SensitiveParameter;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * The new machine of a control-plane move claims it (docs/14 §13.4): the
 * move token (`move` + `secret`), its x25519 key and what it runs on, and
 * when its launchroomd is already a server of this dashboard (a cancelled
 * move left it connected), that server with proof (D103).
 */
final class ClaimMoveData extends Data
{
    public function __construct(
        #[PublicIdOf('cpm')]
        public string $move,
        #[Hidden, SensitiveParameter, Regex('/^'.OpaqueToken::BODY.'$/D')]
        public string $secret,
        #[Max(64)]
        public string $publicKey,
        #[Max(64), Regex('/^[A-Za-z0-9._-]+$/')]
        public string $hostname,
        #[Max(16), Regex('/^[a-z0-9_]+$/')]
        public string $arch,
        #[Max(32), Regex('/^[A-Za-z0-9.+-]+$/')]
        public ?string $version = null,
        /** The machine is already a server of this dashboard (its launchroomd enrolled earlier). */
        #[PublicIdOf('srv')]
        public ?string $serverId = null,
        /** Ed25519 signature over MoveToken::serverProofMessage() by that server's identity key, base64. */
        #[Max(128), Regex('/^[A-Za-z0-9+\/]+={0,2}$/D')]
        public ?string $serverProof = null,
    ) {}
}
