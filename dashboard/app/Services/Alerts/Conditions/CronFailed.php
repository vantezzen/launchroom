<?php

declare(strict_types=1);

namespace App\Services\Alerts\Conditions;

use App\Data\Alerts\AlertSignal;
use App\Data\Services\ScheduleSettings;
use App\Enums\Alerts\AlertRule;
use App\Enums\EnvironmentKind;
use App\Enums\ServerStatus;
use App\Enums\ServiceKind;
use App\Models\ServiceInstance;
use App\Support\Deployments\ExitMeaning;
use App\Support\Links;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `cron_failed` (opt-in, G19) for scheduled cron services of lasting
 * environments (`cron_runs`, recorded from Report.events):
 *
 * - **failed**: its newest finished run exited with an error, until a later
 *   run succeeds;
 * - **missed**: a due run within the last hour didn't start within
 *   GRACE_MINUTES while its server was online and nothing of it was still
 *   running (launchroomd skips a run while the previous one runs, docs/04 §1).
 *
 * launchroomd starts the runs itself, also while the dashboard is down, so
 * a missed run means the server didn't run it.
 */
final readonly class CronFailed extends AlertCondition
{
    public const int GRACE_MINUTES = 3;

    public const int LOOKBACK_MINUTES = 60;

    public function rule(): AlertRule
    {
        return AlertRule::CronFailed;
    }

    public function resolvedReason(): string
    {
        return 'Its next run succeeded.';
    }

    public function failing(array $rules, array $firing, CarbonImmutable $now): array
    {
        $instances = ServiceInstance::query()
            ->whereNull('removed_at')
            ->whereNull('stopped_at')
            ->whereNotNull('active_deployment_id')
            ->whereHas('service', static fn (Builder $query) => $query->where('kind', ServiceKind::Cron->value))
            ->whereHas('environment', static fn (Builder $query) => $query->where('kind', '!=', EnvironmentKind::Preview->value))
            ->with(['service', 'environment.project', 'activeDeployment.revision', 'placements.server'])
            ->get();

        $out = [];

        foreach ($instances as $instance) {
            $problem = $this->failedRun($instance) ?? $this->missedRun($instance, $now);

            if ($problem === null) {
                continue;
            }

            $out[$instance->team_id.'|cron:'.$instance->public_id] = function () use ($instance, $problem, $now): AlertSignal {
                $environment = $instance->environment;

                return new AlertSignal(
                    team: $this->team($instance->team_id),
                    rule: AlertRule::CronFailed,
                    subject: 'cron:'.$instance->public_id,
                    title: $problem['title'],
                    summary: $problem['summary'],
                    href: Links::serviceLogs($instance, null, $now->subMinutes(self::LOOKBACK_MINUTES), $now),
                    project: $environment->project,
                    environment: $environment,
                    serviceInstance: $instance,
                    facts: $problem['facts'],
                );
            };
        }

        return $out;
    }

    /**
     * @return array{title: string, summary: string, facts: array<string, string>}|null
     */
    private function failedRun(ServiceInstance $instance): ?array
    {
        $run = DB::table('cron_runs')
            ->where('service_instance_id', $instance->id)
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->first(['finished_at', 'exit_code', 'oom_killed']);

        if ($run === null) {
            return null;
        }

        $code = is_numeric($run->exit_code ?? null) ? (int) $run->exit_code : null;
        $oom = (bool) ($run->oom_killed ?? false);

        if (! $oom && ($code === null || $code === 0)) {
            return null;
        }

        $name = $instance->service->name;

        return [
            'title' => "{$name} failed in {$instance->environment->name}",
            'summary' => mb_substr(ExitMeaning::summary($code, $oom).' '.(ExitMeaning::advice($code, $oom) ?? 'Its logs usually say why.'), 0, 1000),
            'facts' => ['Finished' => CarbonImmutable::parse(is_string($run->finished_at ?? null) ? $run->finished_at : 'now')->format('j M H:i').' UTC'],
        ];
    }

    /**
     * @return array{title: string, summary: string, facts: array<string, string>}|null
     */
    private function missedRun(ServiceInstance $instance, CarbonImmutable $now): ?array
    {
        $deployment = $instance->activeDeployment;

        if ($deployment === null) {
            return null;
        }

        $schedule = $deployment->revision?->spec->service($instance->service->public_id)?->deploy->schedule;
        $online = $instance->placements->contains(static fn ($placement): bool => $placement->server->status === ServerStatus::Online);

        if (! $schedule instanceof ScheduleSettings || ! $online || $deployment->went_live_at === null) {
            return null;
        }

        try {
            $due = CarbonImmutable::instance(new CronExpression($schedule->cron)->getPreviousRunDate($now->subMinutes(self::GRACE_MINUTES), 0, true, $schedule->timeZone))->utc();
        } catch (Throwable) {
            return null;
        }

        if ($due->lessThan($now->subMinutes(self::LOOKBACK_MINUTES)) || $due->lessThanOrEqualTo(CarbonImmutable::instance($deployment->went_live_at)->addMinute())) {
            return null;
        }

        $ran = DB::table('cron_runs')
            ->where('service_instance_id', $instance->id)
            ->where(static fn (Builder $query) => $query
                ->whereBetween('started_at', [$due->subMinute(), $due->addMinutes(self::GRACE_MINUTES)])
                // Still running from before: launchroomd skips the due run on purpose.
                ->orWhere(static fn (Builder $running) => $running->where('started_at', '<', $due)->where(static fn (Builder $end) => $end->whereNull('finished_at')->orWhere('finished_at', '>', $due))))
            ->exists();

        if ($ran) {
            return null;
        }

        $name = $instance->service->name;
        $when = $due->format('H:i').' UTC';

        return [
            'title' => "{$name} didn’t run at {$when} in {$instance->environment->name}",
            'summary' => 'Its server was online but didn’t start the scheduled run. Check the server page for problems with the server.',
            'facts' => ['Due' => $due->format('j M H:i').' UTC', 'Schedule' => $schedule->cron],
        ];
    }
}
