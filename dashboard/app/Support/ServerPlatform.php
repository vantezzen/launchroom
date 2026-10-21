<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Server;

/**
 * The container platform of a server (`linux/amd64`, `linux/arm64`) from the
 * architecture its daemon reported, or null when unknown/unsupported, and
 * the platforms its BuildKit emulates (D53).
 */
final class ServerPlatform
{
    public static function of(Server $server): ?string
    {
        return match (strtolower($server->facts->arch ?? '')) {
            'amd64', 'x86_64', 'x86-64' => 'linux/amd64',
            'arm64', 'aarch64' => 'linux/arm64',
            default => null,
        };
    }

    /**
     * Platforms the server's BuildKit builds besides its own, through the
     * engine's emulation (Report.host.runtime.buildPlatforms, D53).
     *
     * @return list<string>
     */
    public static function emulated(Server $server): array
    {
        $own = self::of($server);
        $out = [];

        foreach ($server->runtime->buildPlatforms ?? [] as $platform) {
            if ($platform !== $own) {
                $out[] = $platform;
            }
        }

        return $out;
    }

    /**
     * Every platform the server builds for: its own first, then emulated ones.
     *
     * @return list<string>
     */
    public static function buildable(Server $server): array
    {
        $own = self::of($server);

        return $own === null ? [] : [$own, ...self::emulated($server)];
    }
}
