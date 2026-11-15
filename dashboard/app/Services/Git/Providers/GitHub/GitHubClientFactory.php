<?php

declare(strict_types=1);

namespace App\Services\Git\Providers\GitHub;

use App\Models\Git\GitHubApp;
use App\Services\Crypto\SealedColumns;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\Encrypter;

/**
 * Builds a {@see GitHubAppClient} for one app (the app's secrets are
 * decrypted lazily, per call).
 */
final readonly class GitHubClientFactory
{
    public function __construct(
        private GitHubApi $api,
        private SealedColumns $sealed,
        private GitHubClock $clock,
        private Cache $cache,
        private Encrypter $encrypter,
    ) {}

    public function for(GitHubApp $app): GitHubAppClient
    {
        return new GitHubAppClient($app, $this->api, $this->sealed, $this->clock, $this->cache, $this->encrypter);
    }
}
