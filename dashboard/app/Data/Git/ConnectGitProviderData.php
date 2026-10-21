<?php

declare(strict_types=1);

namespace App\Data\Git;

use SensitiveParameter;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Connecting GitLab or Gitea/Forgejo (docs/19 §4.1): the server's web
 * address and either an OAuth application (client id + secret, then the
 * browser authorizes) or an access token.
 */
final class ConnectGitProviderData extends Data
{
    public function __construct(
        #[In(['gitlab', 'gitea'])]
        public string $provider,
        #[Regex('/^https?:\/\/[A-Za-z0-9.-]+(?::[0-9]{1,5})?(?:\/[A-Za-z0-9._~-]+)*\/?$/D'), Max(255)]
        public string $baseUrl,
        #[In(['oauth', 'token'])]
        public string $method = 'oauth',
        #[Regex('/^[A-Za-z0-9._~-]{1,255}$/D')]
        public ?string $clientId = null,
        #[Max(512)]
        #[SensitiveParameter] public ?string $clientSecret = null,
        #[Max(512)]
        #[SensitiveParameter] public ?string $token = null,
        public bool $allowPrivateNetwork = false,
    ) {}
}
