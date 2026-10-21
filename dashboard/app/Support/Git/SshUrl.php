<?php

declare(strict_types=1);

namespace App\Support\Git;

/**
 * An SSH git remote (docs/19 §4.2), parsed from what users paste
 * (`git@host:group/app.git`, `ssh://git@host:2222/srv/app.git`) and stored
 * in one canonical form: `ssh://user@host[:port]/path`, where a home-relative
 * scp path becomes `/~/path` (git's own convention).
 *
 * The grammar is strict on purpose: the path ends up inside the remote's
 * `git-upload-pack '<path>'` request, so it may not contain quotes, spaces,
 * `%` or `..` segments.
 */
final readonly class SshUrl
{
    private const string USER = '/^[a-z_][a-z0-9_.-]{0,31}$/D';

    private const string HOST = '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D';

    private const string PATH = '/^[A-Za-z0-9._~\/-]{1,1024}$/D';

    private function __construct(
        public string $user,
        public string $host,
        public int $port,
        /** `/srv/app.git` (absolute) or `~/repos/app.git` (home-relative). */
        public string $path,
    ) {}

    public static function parse(string $input): ?self
    {
        $input = trim($input);

        if (preg_match('#^ssh://([^@/]+)@([^/:]+)(?::([0-9]{1,5}))?(/.*)$#D', $input, $m) === 1) {
            $path = $m[4];

            if (str_starts_with($path, '/~/')) {
                $path = substr($path, 1);
            }

            return self::make($m[1], $m[2], $m[3] === '' ? 22 : (int) $m[3], $path);
        }

        // scp-like: user@host:path (no port; relative paths are home-relative).
        if (preg_match('#^([^@/]+)@([^/:]+):(?!//)(.+)$#D', $input, $m) === 1) {
            $path = $m[3];

            if (! str_starts_with($path, '/') && ! str_starts_with($path, '~/')) {
                $path = '~/'.$path;
            }

            return self::make($m[1], $m[2], 22, $path);
        }

        return null;
    }

    private static function make(string $user, string $host, int $port, string $path): ?self
    {
        $host = strtolower(rtrim($host, '.'));

        if (preg_match(self::USER, $user) !== 1 || preg_match(self::HOST, $host) !== 1 || $port < 1 || $port > 65535
            || preg_match(self::PATH, $path) !== 1 || in_array('..', explode('/', $path), true) || preg_match('#^(~/|/)[^/]#', $path) !== 1) {
            return null;
        }

        return new self($user, $host, $port, $path);
    }

    /**
     * The canonical URL stored as the clone URL.
     */
    public function toUrl(): string
    {
        $port = $this->port === 22 ? '' : ':'.$this->port;
        $path = str_starts_with($this->path, '~/') ? '/'.$this->path : $this->path;

        return "ssh://{$this->user}@{$this->host}{$port}{$path}";
    }

    /**
     * `host/group/app` for display and `GitSource::fullName`.
     */
    public function displayName(): string
    {
        $path = preg_replace('#\.git$#', '', ltrim(str_starts_with($this->path, '~/') ? substr($this->path, 2) : $this->path, '/'));

        return $this->host.'/'.$path;
    }
}
