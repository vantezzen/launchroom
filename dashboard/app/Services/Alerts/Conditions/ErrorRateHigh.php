<?php

declare(strict_types=1);

namespace App\Services\Alerts\Conditions;

use App\Data\Alerts\AlertSignal;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\Alerts\AlertStatus;
use App\Enums\EnvironmentKind;
use App\Models\Alerts\Alert;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Support\Links;
use App\Support\Metrics\Cast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class ErrorRateHigh extends AlertCondition
{
    /** Requests needed in the window before an error rate means anything. */
    public const int MIN_REQUESTS = 50;

    /** An error-rate alert names a deploy that went live this recently. */
    public const int RECENT_DEPLOY_MINUTES = 30;

    public function rule(): AlertRule
    {
        return AlertRule::ErrorRateHigh;
    }

    public function resolvedReason(): string
    {
        return 'Requests succeed again.';
    }

    public function failing(array $rules, array $firing, CarbonImmutable $now): array
    {
        $rows = DB::table('request_metric_samples as r')
            ->join('service_instances as si', 'si.id', '=', 'r.service_instance_id')
            ->join('environments as e', 'e.id', '=', 'si.environment_id')
            ->where('r.resolution', 1)
            ->where('r.at', '>=', $now->subMinutes(self::WINDOW_MINUTES)->toIso8601String())
            ->whereNull('si.removed_at')
            ->where('e.kind', '!=', EnvironmentKind::Preview->value)
            ->groupBy('r.service_instance_id', 'si.team_id', 'r.at')
            ->selectRaw('r.service_instance_id, si.team_id, r.at, sum(r.requests) as requests, sum(r.status_5xx) as errors')
            ->get();

        /** @var array<int, array{team: int, requests: int, errors: int}> $totals */
        $totals = [];

        foreach ($rows as $row) {
            $team = Cast::id($row->team_id ?? null);
            $rule = $rules[$team][AlertRule::ErrorRateHigh->value] ?? null;
            $at = CarbonImmutable::parse(Cast::string($row->at ?? null));

            if ($rule === null || $at->lessThan($now->subMinutes($rule->minutes()))) {
                continue;
            }

            $id = Cast::id($row->service_instance_id ?? null);
            $totals[$id] ??= ['team' => $team, 'requests' => 0, 'errors' => 0];
            $totals[$id]['requests'] += Cast::id($row->requests ?? null);
            $totals[$id]['errors'] += Cast::id($row->errors ?? null);
        }

        $out = [];

        foreach ($totals as $id => $total) {
            if ($total['requests'] < self::MIN_REQUESTS) {
                continue;
            }

            $rule = $rules[$total['team']][AlertRule::ErrorRateHigh->value];
            $threshold = $rule->thresholdValue();
            $rate = 100 * $total['errors'] / $total['requests'];
            $instance = ServiceInstance::query()->whereKey($id)->with(['service', 'environment.project'])->first();

            if (! $instance instanceof ServiceInstance) {
                continue;
            }

            $key = $total['team'].'|service:'.$instance->public_id;

            if ($rate < (isset($firing[$key]) ? $threshold / 2 : $threshold)) {
                continue;
            }

            $out[$key] = function () use ($instance, $total, $rate, $rule, $now): AlertSignal {
                $environment = $instance->environment;
                $minutes = $rule->minutes();
                $facts = ['Errors' => number_format($total['errors']).' of '.number_format($total['requests']).' requests'];
                // Measured from when the alert opened, so the fact and the
                // link stay the same while it fires.
                $opened = Alert::query()
                    ->where('team_id', $instance->team_id)
                    ->where('rule', AlertRule::ErrorRateHigh->value)
                    ->where('subject_key', 'service:'.$instance->public_id)
                    ->where('status', AlertStatus::Firing->value)
                    ->first(['opened_at'])?->opened_at;
                $start = $opened !== null ? CarbonImmutable::instance($opened) : $now;
                $deploy = $this->recentDeploy($instance, $start);

                if ($deploy instanceof Deployment) {
                    $after = max(0, (int) floor(CarbonImmutable::instance($deploy->went_live_at ?? $start)->diffInMinutes($start)));
                    $facts['Started'] = ($after === 1 ? '1 minute' : "{$after} minutes").' after deploy '.($deploy->commit_sha !== null ? substr($deploy->commit_sha, 0, 7) : $deploy->public_id);
                }

                return new AlertSignal(
                    team: $this->team($instance->team_id),
                    rule: AlertRule::ErrorRateHigh,
                    subject: 'service:'.$instance->public_id,
                    title: "{$instance->service->name} is answering with errors in {$environment->name}",
                    summary: $this->percent($rate)." of requests failed with a server error in the last {$minutes} minutes.",
                    severity: $rate >= 25 ? AlertSeverity::Critical : AlertSeverity::Warning,
                    href: $deploy instanceof Deployment
                        ? Links::deployment($deploy)
                        : Links::serviceLogs($instance, 'level:error', $now->subMinutes($minutes), $now),
                    project: $environment->project,
                    environment: $environment,
                    serviceInstance: $instance,
                    facts: $facts,
                    value: $rate,
                );
            };
        }

        return $out;
    }

    /**
     * The instance's newest deployment that went live within
     * RECENT_DEPLOY_MINUTES: an error spike right after it is most likely its doing.
     */
    private function recentDeploy(ServiceInstance $instance, CarbonImmutable $now): ?Deployment
    {
        return Deployment::query()
            ->where('service_instance_id', $instance->id)
            ->wentLive()
            ->where('went_live_at', '>=', $now->subMinutes(self::RECENT_DEPLOY_MINUTES))
            ->where('went_live_at', '<=', $now)
            ->latest('went_live_at')
            ->first();
    }
}
