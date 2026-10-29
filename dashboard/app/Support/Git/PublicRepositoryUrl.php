<?php

declare(strict_types=1);

namespace App\Support\Git;

use InvalidArgumentException;

/**
 * A public git repository pasted as a URL (docs/19 §4.3, D70):
 * `https://github.com/owner/repo`, `github.com/owner/repo`, a browser URL
 * like `…/owner/repo/tree/dev`, or any `https://host/path.git`.
 *
 * Grammar only, no I/O. HTTPS only (no `http://`, `ssh://`, `git@`), no
 * credentials, query or fragment; the host is a DNS name with a dot; path
 * segments are `[A-Za-z0-9._~-]` without `.`/`..`. On GitHub, GitLab and
 * Codeberg the repository part of a browser URL is cut out and `.git` is
 * added, because the dashboard's HTTP client doesn't follow redirects.
 */
final readonly class PublicRepositoryUrl
{
    /** Hosts whose repositories are `owner/name` (browser URLs carry more). */
    private const array TWO_SEGMENT_HOSTS = ['github.com' => 'github', 'codeberg.org' => 'codeberg', 'bitbucket.org' => 'git'];

    private function __construct(
        public string $host,
        /** `owner/name` (GitLab: `group/subgroup/name`), without `.git`. */
        public string $path,
        /** `github`, `gitlab`, `codeberg` or `git` (the mark shown). */
        public string $provider,
        /** A branch named in a browser URL (`/tree/dev`), else null. */
        public ?string $branch,
        private bool $gitSuffix,
    ) {}

    /**
     * @throws InvalidArgumentException with a sentence for the user
     */
    public static function parse(string $input): self
    {
        $url = trim($input);

        if ($url === '' || strlen($url) > 512) {
            throw new InvalidArgumentException('Paste the URL of a git repository.');
        }

        if (preg_match('#^(ssh://|git@|git://)#i', $url) === 1) {
            throw new InvalidArgumentException('Paste the https:// address. SSH addresses need a deploy key: add them in Settings → Git.');
        }

        if (preg_match('#^http://#i', $url) === 1) {
            throw new InvalidArgumentException('Use the https:// address of the repository.');
        }

        $url = (string) preg_replace('#^https://#i', '', $url);

        if (preg_match('#[\s?\#@\\\\]#', $url) === 1) {
            throw new InvalidArgumentException('Paste the repository URL without a query, fragment or credentials.');
        }

        $parts = explode('/', rtrim($url, '/'));
        $host = strtolower(array_shift($parts));
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        if (preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}(?::[0-9]{1,5})?$/D', $host) !== 1) {
            throw new InvalidArgumentException('That doesn’t look like a repository URL, for example github.com/owner/app.');
        }

        foreach ($parts as $segment) {
            if (preg_match('/^[A-Za-z0-9._~-]{1,100}$/D', $segment) !== 1 || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('That doesn’t look like a repository URL, for example github.com/owner/app.');
            }
        }

        $branch = null;
        $provider = self::TWO_SEGMENT_HOSTS[$host] ?? ($host === 'gitlab.com' ? 'gitlab' : 'git');

        if (isset(self::TWO_SEGMENT_HOSTS[$host])) {
            if (count($parts) > 2 && in_array($parts[2], ['tree', 'src', 'branch'], true)) {
                // github.com/o/r/tree/dev, codeberg.org/o/r/src/branch/dev
                $candidate = $parts[2] === 'src' ? ($parts[4] ?? null) : ($parts[3] ?? null);
                $branch = $candidate !== null && GitRef::isValid('refs/heads/'.$candidate) ? $candidate : null;
            }

            $parts = array_slice($parts, 0, 2);
        } else {
            // GitLab browser URLs: everything after `/-/` is a page of the project.
            $cut = array_search('-', $parts, true);

            if ($cut !== false) {
                $rest = array_slice($parts, $cut + 1);
                $branch = ($rest[0] ?? null) === 'tree' && isset($rest[1]) && GitRef::isValid('refs/heads/'.$rest[1]) ? $rest[1] : null;
                $parts = array_slice($parts, 0, $cut);
            }
        }

        $last = count($parts) - 1;

        if ($last >= 0 && str_ends_with($parts[$last], '.git')) {
            $parts[$last] = substr($parts[$last], 0, -4);
        }

        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

        if (count($parts) < (isset(self::TWO_SEGMENT_HOSTS[$host]) || $host === 'gitlab.com' ? 2 : 1) || count($parts) > 10) {
            throw new InvalidArgumentException('Add the repository to the URL, for example github.com/owner/app.');
        }

        return new self($host, implode('/', $parts), $provider, $branch, gitSuffix: $provider !== 'git' || str_ends_with(rtrim($url, '/'), '.git'));
    }

    public static function tryParse(string $input): ?self
    {
        try {
            return self::parse($input);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * What launchroom clones and polls: `https://host/path[.git]`.
     */
    public function cloneUrl(): string
    {
        return "https://{$this->host}/{$this->path}".($this->gitSuffix ? '.git' : '');
    }

    /**
     * The same URL with `.git` (for hosts that only answer there), or null
     * when the clone URL has it already.
     */
    public function withGitSuffix(): ?self
    {
        return $this->gitSuffix ? null : new self($this->host, $this->path, $this->provider, $this->branch, true);
    }

    /**
     * The comparable form: lowercase, no `www.`, no `.git`, no trailing slash
     * (template `repositories:` are written like this, docs/17 §3.1).
     */
    public function canonical(): string
    {
        return strtolower("https://{$this->host}/{$this->path}");
    }

    /** `github.com/owner/name`, the name the picker shows. */
    public function fullName(): string
    {
        return "{$this->host}/{$this->path}";
    }

    public function name(): string
    {
        $slash = strrpos($this->path, '/');

        return $slash === false ? $this->path : substr($this->path, $slash + 1);
    }
}
