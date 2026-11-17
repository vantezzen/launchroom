<?php

declare(strict_types=1);

namespace App\Services\Variables;

use App\Data\Canvas\DetectedPlanData;
use App\Data\Protocol\V1\BuildSummary;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Data\Specs\VariableSpec;
use App\Enums\ServiceKind;
use App\Enums\VariableType;
use App\Models\Project;
use App\Services\Crypto\ProjectKeyring;
use App\Support\Deployments\EffectiveDeploy;

/**
 * What breaks when a service runs more than one replica (Settings → Scaling,
 * G65): state each replica keeps for itself (Laravel `file` sessions and
 * cache), a scheduler that would run every task once per replica, and the
 * build's own multi-replica warnings (Next.js ISR). Shown while the draft
 * has more than one replica.
 *
 * Only config values are read (never secrets), in memory.
 */
final readonly class ReplicaWarnings
{
    private const array PER_REPLICA_DRIVERS = ['file', 'array'];

    public function __construct(
        private ProjectKeyring $keyring,
    ) {}

    /**
     * @return list<string>
     */
    public function for(Project $project, EnvironmentSpec $spec, ServiceSpec $service, ?BuildSummary $summary): array
    {
        $warnings = [];

        if ($summary instanceof BuildSummary && strtolower((string) $summary->framework) === 'laravel') {
            if ($service->kind === ServiceKind::App) {
                $warnings = [...$warnings, ...$this->laravelState($project, $spec, $service)];
            }

            if ($service->kind === ServiceKind::Worker && in_array('schedule:work', EffectiveDeploy::from($service, $summary)->startCommand ?? [], true)) {
                $warnings[] = 'This process runs the Laravel scheduler. With more than one replica, every scheduled task runs once per replica.';
            }
        }

        foreach ($summary->warnings ?? [] as $warning) {
            if (in_array($warning->code, DetectedPlanData::MULTI_REPLICA_WARNINGS, true)) {
                $warnings[] = $warning->message;
            }
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function laravelState(Project $project, EnvironmentSpec $spec, ServiceSpec $service): array
    {
        $session = $this->config($project, $spec, $service, 'SESSION_DRIVER');
        $cache = $this->config($project, $spec, $service, 'CACHE_STORE') ?? $this->config($project, $spec, $service, 'CACHE_DRIVER');
        $warnings = [];

        if ($session === null) {
            $warnings[] = 'SESSION_DRIVER isn’t set. Laravel 10 and older then keep sessions in files on each replica, so visitors get signed out or see “Page expired”. Set SESSION_DRIVER to redis or database.';
        } elseif (in_array($session, self::PER_REPLICA_DRIVERS, true)) {
            $warnings[] = "SESSION_DRIVER is {$session}, so each replica keeps its own sessions and visitors get signed out or see “Page expired”. Use redis or database.";
        }

        if ($cache !== null && in_array($cache, self::PER_REPLICA_DRIVERS, true)) {
            $warnings[] = "The cache store is {$cache}, so each replica has its own cache and rate limits. Use redis or database.";
        }

        return $warnings;
    }

    /**
     * The value of a config (non-secret) variable the service sees, lowercased; null when unset or a secret.
     */
    private function config(Project $project, EnvironmentSpec $spec, ServiceSpec $service, string $key): ?string
    {
        $found = null;

        foreach ($spec->variablesInPrecedence($service) as $variable) {
            if ($variable->key === $key) {
                $found = $variable;
            }
        }

        if (! $found instanceof VariableSpec || $found->type === VariableType::Secret || $found->withheld) {
            return null;
        }

        return strtolower(trim($this->keyring->decryptVariable($project, $found->id, $found->version, $found->ciphertext)));
    }
}
