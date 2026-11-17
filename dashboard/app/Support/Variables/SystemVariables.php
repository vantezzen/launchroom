<?php

declare(strict_types=1);

namespace App\Support\Variables;

use App\Data\Protocol\V1\EnvAlias;
use App\Data\Specs\DomainSpec;
use App\Data\Specs\EnvironmentMetaSpec;
use App\Data\Specs\ServiceSpec;
use App\Enums\DomainKind;
use App\Enums\EnvAliasSource;
use App\Enums\ServiceKind;

/**
 * Variables launchroom injects into every service (docs/06 §3.1, lowest
 * precedence) and exports for references: PORT, LAUNCHROOM_*,
 * PRIVATE_HOST/PRIVATE_URL, PUBLIC_URL/DOMAIN and PUBLIC_URLS/DOMAINS.
 *
 * - Workers and crons have no domains of their own: they get their app's
 *   (pass `$parent`), so mail and links they build point at the app.
 * - `HOST`/`HOSTNAME` are no longer set for everyone: a preset that needs
 *   them to listen on all interfaces asks for them (EffectiveDeploy), so an
 *   app that reads HOST as its public hostname doesn't get `0.0.0.0`.
 * - Framework aliases (`BuildSummary.envAliases`: APP_URL from PUBLIC_URL,
 *   DJANGO_ALLOWED_HOSTS from ALLOWED_HOSTS_LIST) resolve against these
 *   values per environment with aliases(); a variable the user set wins.
 */
final class SystemVariables
{
    /** The private network's range (mesh upstreams, docs/05 §1). */
    public const string MESH_CIDR = '100.64.0.0/10';

    /**
     * @return array<string, string>
     */
    public static function for(EnvironmentMetaSpec $environment, ServiceSpec $service, ?int $port, ?ServiceSpec $parent = null): array
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

        if ($port !== null && $service->kind->isWeb()) {
            $variables['PRIVATE_URL'] = "http://{$service->slug}:{$port}";
        }

        $domains = self::domains(self::domainOwner($service, $parent));

        if ($domains !== []) {
            $variables['DOMAIN'] = $domains[0];
            $variables['PUBLIC_URL'] = "https://{$domains[0]}";
            $variables['DOMAINS'] = implode(',', $domains);
            $variables['PUBLIC_URLS'] = implode(',', array_map(static fn (string $domain): string => "https://{$domain}", $domains));
        }

        return $variables;
    }

    /**
     * Framework aliases resolved against the system variables of the same
     * service (`for()`'s result). Aliases whose source is unset (no domain)
     * are left out, so the framework keeps its own default.
     *
     * @param  list<EnvAlias>  $aliases
     * @param  array<string, string>  $system
     * @param  string|null  $subnet  the environment network's CIDR (TRUSTED_PROXY_CIDRS)
     * @return array<string, string>
     */
    public static function aliases(array $aliases, array $system, ?string $subnet = null): array
    {
        $resolved = [];

        foreach ($aliases as $alias) {
            $value = self::source($alias->from, $system, $subnet);

            if ($value !== null && $value !== '' && ! isset($system[$alias->name])) {
                $resolved[$alias->name] = $value;
            }
        }

        return $resolved;
    }

    /**
     * The value an alias source stands for, null when it has none.
     *
     * @param  array<string, string>  $system
     */
    public static function source(EnvAliasSource $from, array $system, ?string $subnet = null): ?string
    {
        return match ($from) {
            EnvAliasSource::Domain, EnvAliasSource::Domains, EnvAliasSource::PublicUrl, EnvAliasSource::PublicUrls, EnvAliasSource::Port => $system[$from->value] ?? null,
            // Hostnames the app answers to: its domains, its network alias and localhost (probes, a console in the container).
            EnvAliasSource::AllowedHostsList => implode(',', array_values(array_unique(array_filter([
                ...explode(',', $system['DOMAINS'] ?? ''),
                $system['PRIVATE_HOST'] ?? null,
                'localhost',
            ], static fn (?string $host): bool => $host !== null && $host !== '')))),
            EnvAliasSource::TrustedProxyCidrs => implode(',', array_filter([self::gateway($subnet), self::MESH_CIDR])),
        };
    }

    /**
     * The first custom domain, else the generated one.
     */
    public static function primaryDomain(ServiceSpec $service): ?string
    {
        return self::domains($service)[0] ?? null;
    }

    /**
     * Hostnames that serve the service (not redirects): custom domains
     * first, then generated ones, then per-deployment ones, by hostname
     * within each.
     *
     * @return list<string>
     */
    public static function domains(ServiceSpec $service): array
    {
        $rank = static fn (DomainSpec $domain): int => match ($domain->kind) {
            DomainKind::Custom => 0,
            DomainKind::Generated => 1,
            DomainKind::Deployment => 2,
        };
        $domains = array_values(array_filter($service->domains, static fn (DomainSpec $domain): bool => $domain->redirectTo === null));
        usort($domains, static fn (DomainSpec $a, DomainSpec $b): int => [$rank($a), $a->hostname] <=> [$rank($b), $b->hostname]);

        return array_values(array_unique(array_map(static fn (DomainSpec $domain): string => $domain->hostname, $domains)));
    }

    /**
     * Whose domains a service's PUBLIC_URL/DOMAIN come from: its own, or
     * for workers and crons their app's.
     */
    private static function domainOwner(ServiceSpec $service, ?ServiceSpec $parent): ServiceSpec
    {
        return $service->kind->requiresParent() && $parent instanceof ServiceSpec && $parent->kind !== ServiceKind::Database ? $parent : $service;
    }

    /**
     * Docker's gateway of an IPv4 network (its first address) as a /32.
     */
    private static function gateway(?string $subnet): ?string
    {
        if ($subnet === null || preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#D', $subnet, $m) !== 1 || (int) $m[2] > 30) {
            return null;
        }

        $network = ip2long($m[1]);

        if ($network === false) {
            return null;
        }

        $mask = (int) $m[2] === 0 ? 0 : (~0 << (32 - (int) $m[2])) & 0xFFFFFFFF;

        return long2ip((($network & $mask) + 1) & 0xFFFFFFFF).'/32';
    }
}
