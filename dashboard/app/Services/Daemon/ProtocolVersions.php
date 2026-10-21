<?php

declare(strict_types=1);

namespace App\Services\Daemon;

/**
 * Daemon protocol versions the dashboard speaks (docs/03 §10): the current
 * one and N-1, newest first (`launchroom.daemon.protocol_versions`).
 */
final class ProtocolVersions
{
    /**
     * @return list<string>
     */
    public static function supported(): array
    {
        $versions = config('launchroom.daemon.protocol_versions');

        return is_array($versions) ? array_values(array_filter($versions, is_string(...))) : [];
    }

    public static function isSupported(?string $version): bool
    {
        return $version !== null && in_array($version, self::supported(), true);
    }

    /**
     * Versions offered in an `X-Lr-Protocol-Versions` header (at most 8).
     *
     * @return list<string>
     */
    public static function parse(string $header): array
    {
        $versions = [];

        foreach (array_slice(preg_split('/[\s,]+/', $header, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 8) as $token) {
            if (preg_match('/^v[1-9]\d{0,2}$/D', $token) === 1) {
                $versions[] = $token;
            }
        }

        return $versions;
    }

    /**
     * The newest version both sides speak, or null.
     *
     * @param  list<string>  $offered
     */
    public static function negotiate(array $offered): ?string
    {
        foreach (self::supported() as $version) {
            if (in_array($version, $offered, true)) {
                return $version;
            }
        }

        return null;
    }
}
