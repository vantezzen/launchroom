<?php

declare(strict_types=1);

namespace App\Support\Deployments;

use App\Data\Protocol\V1\AppliedPreset;
use App\Data\Protocol\V1\BuildSummary;
use App\Data\Protocol\V1\EnvAlias;
use App\Data\Services\HealthcheckSettings;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Enums\Builds\ChosenBuilder;
use App\Enums\EnvAliasSource;
use App\Enums\HealthcheckType;
use App\Enums\ServiceKind;
use App\Models\Deployment;
use App\Support\Variables\SystemVariables;
use Closure;

/**
 * The deploy settings a deployment actually runs with (docs/04 §13
 * "Summary defaults"): the revision's explicit settings win; where the
 * service has none, the build summary of the deployment's image fills in.
 *
 * | Setting         | Explicit (service)          | Default from the build summary                    |
 * |-----------------|-----------------------------|---------------------------------------------------|
 * | port            | `deploy.port` (not null)    | `summary.port` (web kinds)                        |
 * | health path     | `healthcheck.path` ≠ `/`    | `summary.healthPath` (http probes; `/` = unset)   |
 * | release command | `deploy.releaseCommand`     | `summary.releaseCommand` (app services only)      |
 * | start command   | `deploy.startCommand`       | worker/cron: `summary.processes[name = slug]`     |
 * | runtime env     | any variable                | `summary.runtimeEnv`, and `PORT` for a summary port |
 * | listen address  | any variable                | `HOST`/`HOSTNAME=0.0.0.0` where a preset asks       |
 * | web workers     | any variable                | `WEB_CONCURRENCY` from the CPU and memory limits    |
 * | framework names | any variable                | `summary.envAliases` (APP_URL = PUBLIC_URL …)       |
 *
 * Variables always win over summary defaults (they are applied underneath).
 * Framework aliases resolve per environment against the system variables
 * of the deployment's revision (workers and crons: their app's domains), so
 * one image promoted to a preview gets the preview's URL.
 * Commands of Railpack-built images replace the image's shell ENTRYPOINT
 * (see argv()).
 */
final readonly class EffectiveDeploy
{
    /**
     * @param  list<string>|null  $startCommand
     * @param  list<string>|null  $releaseCommand
     * @param  array<string, string>  $envDefaults
     */
    public function __construct(
        public ?int $port,
        public HealthcheckSettings $healthcheck,
        public ?array $startCommand,
        public ?array $releaseCommand,
        public array $envDefaults,
        public bool $argvReplacesEntrypoint = false,
    ) {}

    /**
     * How an argv runs in this image: Railpack images have the shell wrapper
     * ENTRYPOINT `["/bin/bash", "-c"]`, which would treat the argv's first
     * element as a script and the rest as positional parameters, so there the
     * argv replaces the entrypoint (`command`). Other images keep their
     * ENTRYPOINT and get the argv as `args`.
     *
     * @param  list<string>|null  $argv
     * @return array{command: list<string>|null, args: list<string>|null}
     */
    public function argv(?array $argv): array
    {
        if ($argv === null || $argv === []) {
            return ['command' => null, 'args' => null];
        }

        return $this->argvReplacesEntrypoint ? ['command' => $argv, 'args' => null] : ['command' => null, 'args' => $argv];
    }

    /**
     * Presets whose servers read HOST/HOSTNAME to choose the interface they
     * listen on (OD12): the Node servers that got them as system variables
     * before (Astro's standalone server listens on localhost without).
     * Next.js sets HOSTNAME itself.
     */
    public const array LISTEN_ALL_PRESETS = ['node-server', 'nuxt', 'sveltekit', 'astro', 'react-router', 'tanstack-start', 'angular-ssr', 'gatsby'];

    /** Runtimes whose web servers read WEB_CONCURRENCY (gunicorn, uvicorn, Puma). */
    public const array WEB_CONCURRENCY_PROVIDERS = ['python', 'ruby'];

    /** Memory one web worker process gets, for WEB_CONCURRENCY. */
    public const int WEB_WORKER_MEMORY_MB = 256;

    public const int MAX_WEB_CONCURRENCY = 16;

    public static function for(ServiceSpec $spec, ?Deployment $deployment): self
    {
        if (! $deployment instanceof Deployment) {
            return self::from($spec, null);
        }

        return self::from(
            $spec,
            $deployment->plan?->summary,
            $deployment->revision?->spec,
            static fn (): string => $deployment->environment->network_subnet,
        );
    }

    /**
     * @param  EnvironmentSpec|null  $environment  the revision $spec belongs to (framework aliases need it)
     * @param  (Closure(): ?string)|null  $subnet  the environment network's CIDR, read only when an alias needs it
     */
    public static function from(ServiceSpec $spec, ?BuildSummary $summary, ?EnvironmentSpec $environment = null, ?Closure $subnet = null): self
    {
        $deploy = $spec->deploy;
        $port = $deploy->port;
        $env = [];

        if ($summary instanceof BuildSummary) {
            foreach ($summary->runtimeEnv ?? [] as $name => $value) {
                $env[(string) $name] = $value;
            }

            if ($port === null && $summary->port !== null && $spec->kind->isWeb()) {
                $port = $summary->port;
                $env['PORT'] = (string) $port;
            }

            if (self::listensOnAll($summary)) {
                $env['HOST'] ??= '0.0.0.0';
                $env['HOSTNAME'] ??= '0.0.0.0';
            }

            if ($spec->kind->isWeb() && in_array(strtolower((string) $summary->provider), self::WEB_CONCURRENCY_PROVIDERS, true)) {
                $env['WEB_CONCURRENCY'] ??= (string) self::webConcurrency($spec->scaling->cpus, $spec->scaling->memoryMb);
            }

            if ($environment instanceof EnvironmentSpec && ($summary->envAliases ?? []) !== []) {
                $env = [...$env, ...self::aliases($spec, $summary->envAliases ?? [], $environment, $port, $subnet)];
            }
        }

        $health = $deploy->healthcheck;

        if ($summary?->healthPath !== null && $health->type === HealthcheckType::Http && $health->path === '/') {
            $health = HealthcheckSettings::from([...$health->toArray(), 'path' => $summary->healthPath]);
        }

        $release = $deploy->releaseCommand;

        if (($release === null || $release === []) && $spec->kind === ServiceKind::App) {
            $release = $summary?->releaseCommand;
        }

        $start = $deploy->startCommand;

        if ($start === null && $spec->kind->requiresParent()) {
            foreach ($summary->processes ?? [] as $process) {
                if ($process->name === $spec->slug) {
                    $start = $process->args;
                }
            }
        }

        return new self($port, $health, $start, $release === [] ? null : $release, $env, $summary?->builder === ChosenBuilder::Railpack);
    }

    /**
     * Web worker processes for the replica's limits: two per CPU, at most
     * one per 256 MB, between 1 and 16 (gunicorn's and Puma's
     * WEB_CONCURRENCY, as Heroku sets it).
     */
    public static function webConcurrency(float $cpus, int $memoryMb): int
    {
        $byCpu = (int) round($cpus * 2);
        $byMemory = intdiv($memoryMb, self::WEB_WORKER_MEMORY_MB);

        return max(1, min($byCpu, $byMemory, self::MAX_WEB_CONCURRENCY));
    }

    /**
     * Whether a preset of the build asks for HOST/HOSTNAME=0.0.0.0.
     */
    public static function listensOnAll(?BuildSummary $summary): bool
    {
        return array_any($summary->presets ?? [], static fn (AppliedPreset $preset): bool => in_array($preset->name, self::LISTEN_ALL_PRESETS, true));
    }

    /**
     * @param  list<EnvAlias>  $aliases
     * @param  (Closure(): ?string)|null  $subnet
     * @return array<string, string>
     */
    private static function aliases(ServiceSpec $spec, array $aliases, EnvironmentSpec $environment, ?int $port, ?Closure $subnet): array
    {
        $parent = $spec->parentId === null ? null : $environment->service($spec->parentId);
        $system = SystemVariables::for($environment->environment, $spec, $port, $parent);
        $needsSubnet = array_any($aliases, static fn (EnvAlias $alias): bool => $alias->from === EnvAliasSource::TrustedProxyCidrs);

        return SystemVariables::aliases($aliases, $system, $needsSubnet && $subnet instanceof Closure ? $subnet() : null);
    }
}
