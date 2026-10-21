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
 * Labels longer than 63 characters are truncated with a hash suffix.
 */
final class GeneratedHostname
{
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
        return Hostname::label($service, $environment, $project).'.'.str_replace('.', '-', $ipv4).'.sslip.io';
    }
}
