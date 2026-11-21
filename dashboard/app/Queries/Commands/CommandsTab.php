<?php

declare(strict_types=1);

namespace App\Queries\Commands;

use App\Data\Commands\CommandsTabData;
use App\Enums\EnvironmentKind;
use App\Exceptions\DomainRuleViolation;
use App\Http\Api\Cursor;
use App\Models\ServiceInstance;
use App\Services\Commands\CommandPlacement;

/**
 * The Commands tab's data (D105, docs/08 §22): the catalogue, the recent
 * runs and, when nothing can run right now, the one sentence why.
 */
final readonly class CommandsTab
{
    public const int RUNS = 20;

    /** "Stops after": 1, 10, 30 and 60 minutes. */
    private const array TIMEOUTS = [60, 600, 1800, 3600];

    public function __construct(
        private CommandCatalogue $catalogue,
        private CommandRuns $runs,
        private CommandPlacement $placement,
    ) {}

    public function for(ServiceInstance $instance, ?int $before = null): CommandsTabData
    {
        $instance->loadMissing(['service', 'environment', 'activeDeployment.revision']);
        [$runs, $next] = $this->runs->page($instance, $before, self::RUNS);
        $max = config()->integer('launchroom.commands.max_timeout_seconds');

        return new CommandsTabData(
            catalogue: $this->catalogue->for($instance),
            runs: $runs,
            moreRuns: $next === null ? null : Cursor::encode($next),
            blocked: $this->blocked($instance),
            environment: $instance->environment->name,
            production: $instance->environment->kind === EnvironmentKind::Production,
            defaultTimeout: config()->integer('launchroom.commands.default_timeout_seconds'),
            timeouts: array_values(array_filter(self::TIMEOUTS, static fn (int $seconds): bool => $seconds <= $max)),
        );
    }

    private function blocked(ServiceInstance $instance): ?string
    {
        if (config('launchroom.commands.enabled') !== true) {
            return 'Commands are turned off on this launchroom.';
        }

        $live = $instance->activeDeployment;
        $spec = $live?->revision?->spec->service($instance->service->public_id);

        if ($live?->image === null || $spec === null) {
            return 'Commands run in the live version. Deploy this service first.';
        }

        try {
            $this->placement->for($instance, $spec);
        } catch (DomainRuleViolation $e) {
            return $e->getMessage();
        }

        return null;
    }
}
