<?php

declare(strict_types=1);

namespace App\Data\Servers;

/**
 * What the join-token console commands print: one JSON line read by
 * `deploy/install/install.sh` (json_field) and the root Makefile (sed), field
 * by field. `serverId` is set when the control-plane server is already
 * enrolled and no token was issued. The token is a secret: printed once to
 * stdout, never logged.
 */
final readonly class JoinTokenOutput
{
    public function __construct(
        public ?string $serverId,
        #[\SensitiveParameter] public ?string $token,
        public string $cpKey,
        public string $kid,
    ) {}

    public function toJson(): string
    {
        return json_encode([
            'serverId' => $this->serverId,
            'token' => $this->token,
            'cpKey' => $this->cpKey,
            'kid' => $this->kid,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
