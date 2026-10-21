<?php

declare(strict_types=1);

namespace App\Support\Images;

/**
 * A parsed, normalized OCI image reference:
 * `postgres:18` → registry `docker.io`, repository `library/postgres`, tag `18`.
 *
 * Workload images are always rendered digest-pinned from the local registry
 * (protocol/README.md "Images"). Upstream images go through the registry's
 * pull-through mirror: `127.0.0.1:5000/mirror/<registry>/<repository>@sha256:…`
 * (docs/04 §5 "Mirrors").
 */
final readonly class ImageReference
{
    private const string COMPONENT = '[a-z0-9]+(?:(?:\.|_|__|-+)[a-z0-9]+)*';

    private function __construct(
        public string $registry,
        public string $repository,
        public ?string $tag,
        public ?string $digest,
    ) {}

    public static function tryParse(string $reference): ?self
    {
        if ($reference === '' || strlen($reference) > 512 || preg_match('/\s/', $reference) === 1) {
            return null;
        }

        $digest = null;

        if (str_contains($reference, '@')) {
            [$reference, $digest] = explode('@', $reference, 2);

            if (preg_match('/^sha256:[a-f0-9]{64}$/D', $digest) !== 1) {
                return null;
            }
        }

        $tag = null;
        $lastSlash = strrpos($reference, '/');
        $lastColon = strrpos($reference, ':');

        if ($lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash)) {
            $tag = substr($reference, $lastColon + 1);
            $reference = substr($reference, 0, $lastColon);

            if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/D', $tag) !== 1) {
                return null;
            }
        }

        $parts = explode('/', $reference);
        $registry = 'docker.io';

        // The first component is a registry host when it looks like one (docker's rule).
        if (count($parts) > 1 && (str_contains($parts[0], '.') || str_contains($parts[0], ':') || $parts[0] === 'localhost')) {
            $registry = strtolower(array_shift($parts));

            if (preg_match('/^(?:localhost|[a-z0-9-]+(?:\.[a-z0-9-]+)*)(?::[0-9]{1,5})?$/D', $registry) !== 1) {
                return null;
            }
        }

        if ($registry === 'index.docker.io' || $registry === 'registry-1.docker.io') {
            $registry = 'docker.io';
        }

        if ($registry === 'docker.io' && count($parts) === 1) {
            array_unshift($parts, 'library');
        }

        foreach ($parts as $part) {
            if (preg_match('/^'.self::COMPONENT.'$/D', $part) !== 1) {
                return null;
            }
        }

        return new self($registry, implode('/', $parts), $tag, $digest);
    }

    public function isPinned(): bool
    {
        return $this->digest !== null;
    }

    public function withDigest(string $digest): self
    {
        return new self($this->registry, $this->repository, null, $digest);
    }

    /**
     * `docker.io/library/postgres:18` or `…@sha256:…` (digest wins over the tag).
     */
    public function canonical(): string
    {
        $name = $this->registry.'/'.$this->repository;

        return match (true) {
            $this->digest !== null => $name.'@'.$this->digest,
            $this->tag !== null => $name.':'.$this->tag,
            default => $name.':latest',
        };
    }

    /**
     * The digest-pinned reference through the local registry's pull-through
     * mirror: `<mirrorPrefix>/<registry>/<repository>@sha256:…`, lowercase.
     */
    public function mirrored(string $mirrorPrefix): string
    {
        if ($this->digest === null) {
            throw new \LogicException('Only digest-pinned images can be rendered into a NodeState.');
        }

        return strtolower(rtrim($mirrorPrefix, '/').'/'.$this->registry.'/'.$this->repository).'@'.$this->digest;
    }
}
