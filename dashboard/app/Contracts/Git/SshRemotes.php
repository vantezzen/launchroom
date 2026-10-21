<?php

declare(strict_types=1);

namespace App\Contracts\Git;

use App\Services\Git\Exceptions\GitConnectionUnusable;
use App\Services\Git\Ssh\PhpseclibSshRemotes;
use App\Support\Git\HostKey;
use App\Support\Git\SshUrl;
use Illuminate\Container\Attributes\Bind;
use SensitiveParameter;

/**
 * The dashboard's SSH client for plain git servers (docs/19 §4.2): reads a
 * server's host keys (key exchange only) and its ref advertisement
 * (`git-upload-pack` over an exec channel, authenticated with the deploy
 * key, host key pinned). Nothing runs on the dashboard's own host.
 */
#[Bind(PhpseclibSshRemotes::class)]
interface SshRemotes
{
    /**
     * @return list<HostKey>
     *
     * @throws GitConnectionUnusable when the server can't be reached
     */
    public function hostKeys(SshUrl $url, bool $privateNetwork): array;

    /**
     * `refs/heads/main` => sha, from the advertisement.
     *
     * @param  list<HostKey>  $pinned
     * @return array<string, string>
     *
     * @throws GitConnectionUnusable on an unreachable server, a changed host key or refused key
     */
    public function refs(SshUrl $url, array $pinned, #[SensitiveParameter] string $secretKey, bool $privateNetwork): array;
}
