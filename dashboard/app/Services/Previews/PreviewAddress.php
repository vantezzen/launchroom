<?php

declare(strict_types=1);

namespace App\Services\Previews;

/**
 * The preview address a forward-auth check or a sign-in start is about
 * (docs/09 R20a): host and port, the origin-relative path, and the
 * single-use exchange token ({@see PreviewAccess::TOKEN_PARAMETER}) split
 * off the path's query.
 */
final readonly class PreviewAddress
{
    private function __construct(
        /** Null when the host is malformed. */
        public ?string $host,
        /** Null for 443. */
        public ?int $port,
        public string $path,
        public ?string $token,
    ) {}

    public static function parse(string $hostWithPort, string $uri): self
    {
        [$host, $port] = self::splitHost(strtolower(trim($hostWithPort)));
        [$path, $token] = self::extractToken(self::path($uri) ?? '/');

        return new self($host, $port, $path, $token);
    }

    public function origin(): string
    {
        return 'https://'.$this->host.($this->port === null ? '' : ':'.$this->port);
    }

    public function hostWithPort(): string
    {
        return $this->port === null ? (string) $this->host : "{$this->host}:{$this->port}";
    }

    public function urlWithToken(string $token): string
    {
        $separator = str_contains($this->path, '?') ? '&' : '?';

        return $this->origin().$this->path.$separator.PreviewAccess::TOKEN_PARAMETER.'='.$token;
    }

    /**
     * @return array{string|null, int|null} hostname and optional port
     */
    private static function splitHost(string $value): array
    {
        if (preg_match('/^([a-z0-9.-]{1,253})(?::([0-9]{1,5}))?$/D', $value, $m) !== 1) {
            return [null, null];
        }

        $port = isset($m[2]) ? (int) $m[2] : null;

        if ($port !== null && ($port < 1 || $port > 65535)) {
            return [null, null];
        }

        return [$m[1], $port === 443 ? null : $port];
    }

    /**
     * An origin-relative path (with query), or null: no scheme, no `//`
     * (protocol-relative), no backslashes or control characters.
     */
    private static function path(string $value): ?string
    {
        if ($value === '' || $value[0] !== '/' || str_starts_with($value, '//') || strlen($value) > 4096
            || preg_match('/[\\\\\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    /**
     * @return array{string, string|null}
     */
    private static function extractToken(string $uri): array
    {
        $parts = explode('?', $uri, 2);

        if (count($parts) < 2) {
            return [$uri, null];
        }

        $token = null;
        $kept = [];

        foreach (explode('&', $parts[1]) as $pair) {
            if (str_starts_with($pair, PreviewAccess::TOKEN_PARAMETER.'=')) {
                $token = urldecode(substr($pair, strlen(PreviewAccess::TOKEN_PARAMETER) + 1));

                continue;
            }

            if ($pair !== '') {
                $kept[] = $pair;
            }
        }

        return [$parts[0].($kept === [] ? '' : '?'.implode('&', $kept)), $token];
    }
}
