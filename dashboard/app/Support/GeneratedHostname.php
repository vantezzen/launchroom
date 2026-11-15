<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Generated hostnames (docs/05 §5), pure:
 *
 * - local dev:          `<svc>-<env>.<project>.localhost`
 * - platform wildcard:  `<svc>-<env>.<project>.<apps domain>`
 * - no domain yet:      `<svc>-<env>-<project>.<ip-dashed>.sslip.io`
 *
 * Immutable per-deployment URLs put the deployment's short id where the
 * environment goes (`web-01jb7z6x`); `deploymentQualifier()` builds it and
 * `deploymentQualifierIn()` reads it back from a routed host.
 *
 * Labels longer than 63 characters are truncated with a hash suffix.
 */
final class GeneratedHostname
{
    private const int SHORT_ID_LENGTH = 8;

    /** The lowercased last 8 characters of the deployment's public id. */
    public static function deploymentQualifier(string $deploymentPublicId): string
    {
        return strtolower(substr($deploymentPublicId, -self::SHORT_ID_LENGTH));
    }

    /**
     * The deployment short id in the first label of $host (`<svc>-<shortid>`,
     * or `<svc>-<shortid>-<project>` on sslip.io), or null. A candidate
     * only: callers rebuild the hostname to confirm it.
     */
    public static function deploymentQualifierIn(string $host): ?string
    {
        $first = explode('.', strtolower($host))[0];

        return preg_match('/-([0-9a-z]{'.self::SHORT_ID_LENGTH.'})(?:-|$)/', $first, $m) === 1 ? $m[1] : null;
    }

    public static function localhost(string $service, string $environment, string $project): string
    {
        return Hostname::label($service, $environment).'.'.Hostname::label($project).'.localhost';
    }

    public static function wildcard(string $service, string $environment, string $project, string $appsDomain): string
    {
        return Hostname::label($service, $environment).'.'.Hostname::label($project).'.'.strtolower($appsDomain);
    }

    public static function sslip(string $service, string $environment, string $project, string $ipv4): string
    {
        return Hostname::label($service, $environment, $project).'.'.self::sslipZone($ipv4);
    }

    /** "*.203-0-113-7.sslip.io": every address sslip.io answers with this IP. */
    public static function sslipWildcard(string $ipv4): string
    {
        return '*.'.self::sslipZone($ipv4);
    }

    private static function sslipZone(string $ipv4): string
    {
        return str_replace('.', '-', $ipv4).'.sslip.io';
    }
}
