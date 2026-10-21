<?php

declare(strict_types=1);

namespace App\Support\Deployments;

use App\Data\Protocol\V1\BuildSummary;
use App\Data\Services\HealthcheckSettings;
use App\Data\Specs\ServiceSpec;
use App\Enums\HealthcheckType;
use App\Enums\ServiceKind;
use App\Models\Deployment;
use Throwable;

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
 *
 * Variables always win over summary defaults (they are applied underneath).
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

    public static function for(ServiceSpec $spec, ?Deployment $deployment): self
    {
        return self::from($spec, $deployment instanceof Deployment ? self::summaryOf($deployment) : null);
    }

    public static function from(ServiceSpec $spec, ?BuildSummary $summary): self
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

        return new self($port, $health, $start, $release === [] ? null : $release, $env, $summary?->builder === 'railpack');
    }

    /**
     * The build summary stored with a built deployment (`deployments.plan.summary`).
     */
    public static function summaryOf(Deployment $deployment): ?BuildSummary
    {
        $summary = $deployment->plan['summary'] ?? null;

        if (! is_array($summary)) {
            return null;
        }

        try {
            return BuildSummary::from($summary);
        } catch (Throwable) {
            return null;
        }
    }
}
