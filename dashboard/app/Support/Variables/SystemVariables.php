<?php

declare(strict_types=1);

namespace App\Support\Variables;

use App\Data\Specs\DomainSpec;
use App\Data\Specs\EnvironmentMetaSpec;
use App\Data\Specs\ServiceSpec;
use App\Enums\DomainKind;
use App\Enums\ServiceKind;

/**
 * Variables launchroom injects into every service (docs/06 §3.1, lowest
 * precedence) and exports for references: PORT, HOST/HOSTNAME,
 * LAUNCHROOM_*, PRIVATE_HOST/PRIVATE_URL, PUBLIC_URL/DOMAIN.
 */
final class SystemVariables
{
    /**
     * @return array<string, string>
     */
    public static function for(EnvironmentMetaSpec $environment, ServiceSpec $service, ?int $port): array
    {
        $variables = [
            'LAUNCHROOM' => 'true',
            'LAUNCHROOM_PROJECT_ID' => $environment->projectId,
            'LAUNCHROOM_ENVIRONMENT' => $environment->slug,
            'LAUNCHROOM_ENVIRONMENT_ID' => $environment->id,
            'LAUNCHROOM_ENVIRONMENT_KIND' => $environment->kind->value,
            'LAUNCHROOM_SERVICE' => $service->slug,
            'LAUNCHROOM_SERVICE_ID' => $service->id,
            // Docker DNS alias on the environment network (docs/05 §1).
            'PRIVATE_HOST' => $service->slug,
        ];

        if ($port !== null) {
            $variables['PORT'] = (string) $port;
        }

        if ($service->kind !== ServiceKind::Database) {
            // Listen on all interfaces (Next.js reads HOSTNAME, others HOST).
            $variables['HOST'] = '0.0.0.0';
            $variables['HOSTNAME'] = '0.0.0.0';
        }

        if ($port !== null && $service->kind->isWeb()) {
            $variables['PRIVATE_URL'] = "http://{$service->slug}:{$port}";
        }

        $domain = self::primaryDomain($service);

        if ($domain !== null) {
            $variables['DOMAIN'] = $domain;
            $variables['PUBLIC_URL'] = "https://{$domain}";
        }

        return $variables;
    }

    /**
     * The first custom domain, else the generated one.
     */
    public static function primaryDomain(ServiceSpec $service): ?string
    {
        $domains = array_values($service->domains);
        usort($domains, static fn (DomainSpec $a, DomainSpec $b): int => [$a->kind !== DomainKind::Custom, $a->hostname] <=> [$b->kind !== DomainKind::Custom, $b->hostname]);

        foreach ($domains as $domain) {
            if ($domain->redirectTo === null) {
                return $domain->hostname;
            }
        }

        return null;
    }
}
