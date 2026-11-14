<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Deployments\DeploymentImpactData;
use App\Data\Deployments\DeploymentImpactWindowData;
use App\Data\Deployments\DeploymentSummaryData;
use App\Enums\Alerts\AlertRule;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Services\Alerts\AlertRuleSettings;
use App\Support\Metrics\Cast;
use App\Support\Metrics\RequestStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Since this deploy" (docs/08 §12): the service's requests, error rate
 * and p95 from when a deployment went live (up to an hour, or until the
 * next one replaced it) against the hour before, from the request metrics
 * (docs/15 §1). Errors that rose past the team's error-rate alert
 * threshold mark the deploy as worse, and the deployment it replaced is
 * offered as a rollback when the viewer may roll back to it.
 *
 * Null when there is nothing to compare: the deployment never went live,
 * went live less than two minutes ago, or the service had no requests.
 */
final readonly class DeploymentImpact
{
    public const int WINDOW_MINUTES = 60;

    /** Requests needed after the deploy before a rise in errors means anything. */
    public const int MIN_REQUESTS = 20;

    private const int MIN_AFTER_SECONDS = 120;

    public function __construct(
        private AlertRuleSettings $rules,
        private DeploymentSummaries $summaries,
    ) {}

    public function for(Deployment $deployment, ?CarbonImmutable $now = null): ?DeploymentImpactData
    {
        $now ??= CarbonImmutable::now();

        if (! in_array($deployment->status, [DeploymentStatus::Active, DeploymentStatus::Superseded], true) || $deployment->finished_at === null) {
            return null;
        }

        $liveAt = CarbonImmutable::instance($deployment->finished_at);
        $next = Deployment::query()
            ->where('service_instance_id', $deployment->service_instance_id)
            ->whereIn('status', [DeploymentStatus::Active->value, DeploymentStatus::Superseded->value])
            ->where('finished_at', '>', $liveAt)
            ->oldest('finished_at')
            ->first(['id', 'finished_at']);
        $replacedAt = $next?->finished_at !== null ? CarbonImmutable::instance($next->finished_at) : null;
        $end = $liveAt->addMinutes(self::WINDOW_MINUTES);
        $replaced = $replacedAt instanceof CarbonImmutable && $replacedAt->lessThan($end);
        // Only completed minutes count, so the rate doesn't dip at the right edge.
        $end = CarbonImmutable::createFromTimestampUTC(min($end->getTimestamp(), $now->startOfMinute()->getTimestamp(), $replaced ? $replacedAt->getTimestamp() : PHP_INT_MAX));
        $afterSeconds = $end->getTimestamp() - $liveAt->getTimestamp();

        if ($afterSeconds < self::MIN_AFTER_SECONDS) {
            return null;
        }

        $start = $liveAt->subMinutes(self::WINDOW_MINUTES);
        // 1-minute rows are kept 48 hours; older deploys read the 10-minute rows.
        $resolution = $start->greaterThan($now->subHours(47)) ? 1 : 10;
        $bucket = $resolution * 60;
        $rows = DB::table('request_metric_samples')
            ->where('service_instance_id', $deployment->service_instance_id)
            ->where('resolution', $resolution)
            ->where('at', '>=', $start->toIso8601String())
            ->where('at', '<', $end->toIso8601String())
            ->groupBy('at')
            ->orderBy('at')
            ->selectRaw('at, sum(requests) as requests, sum(status_5xx) as errors, '.RequestStats::SUM_SQL.' as latency')
            ->get();

        $sums = ['before' => ['requests' => 0, 'errors' => 0, 'latency' => null], 'after' => ['requests' => 0, 'errors' => 0, 'latency' => null]];
        $first = intdiv($start->getTimestamp(), $bucket) * $bucket;
        $count = (int) ceil(($end->getTimestamp() - $first) / $bucket);
        $requests = array_fill(0, $count, 0);
        $errors = array_fill(0, $count, 0);

        foreach ($rows as $row) {
            $at = CarbonImmutable::parse(Cast::string($row->at ?? null));
            $n = Cast::id($row->requests ?? null);
            $e = Cast::id($row->errors ?? null);
            $side = $at->lessThan($liveAt) ? 'before' : 'after';
            $sums[$side]['requests'] += $n;
            $sums[$side]['errors'] += $e;
            $sums[$side]['latency'] = RequestStats::add($sums[$side]['latency'], RequestStats::parse($row->latency ?? null));
            $i = intdiv($at->getTimestamp() - $first, $bucket);

            if ($i >= 0 && $i < $count) {
                $requests[$i] += $n;
                $errors[$i] += $e;
            }
        }

        if ($sums['after']['requests'] === 0 && $sums['before']['requests'] === 0) {
            return null;
        }

        $after = $this->window($sums['after'], $afterSeconds);
        $before = $sums['before']['requests'] > 0 ? $this->window($sums['before'], self::WINDOW_MINUTES * 60) : null;
        $threshold = $this->rules->rule($deployment->team_id, AlertRule::ErrorRateHigh)->threshold ?? 5.0;
        $worse = $sums['after']['requests'] >= self::MIN_REQUESTS
            && ($after->errorRatePct ?? 0.0) >= $threshold
            && ($after->errorRatePct ?? 0.0) >= 2 * ($before->errorRatePct ?? 0.0);

        $timestamps = [];
        $rates = [];
        $errorRates = [];

        for ($i = 0; $i < $count; $i++) {
            $t = $first + $i * $bucket;
            $timestamps[] = CarbonImmutable::createFromTimestampUTC($t)->toIso8601String();
            $rates[] = round($requests[$i] / $bucket, 3);
            $errorRates[] = $requests[$i] > 0 ? round(100 * $errors[$i] / $requests[$i], 2) : null;
        }

        return new DeploymentImpactData(
            liveAt: $liveAt->utc()->toIso8601String(),
            minutesAfter: max(1, intdiv($afterSeconds, 60)),
            replaced: $replaced,
            before: $before,
            after: $after,
            bucketSeconds: $bucket,
            timestamps: $timestamps,
            requestsPerSecond: $rates,
            errorRatePct: $errorRates,
            thresholdPct: $threshold,
            worse: $worse,
            rollbackTo: $worse && $deployment->status === DeploymentStatus::Active ? $this->rollbackTarget($deployment) : null,
        );
    }

    /**
     * The deployment that served before this one, when the viewer may roll
     * back to it.
     */
    private function rollbackTarget(Deployment $deployment): ?DeploymentSummaryData
    {
        $previous = DeploymentSummaries::withRelations(Deployment::query())
            ->where('service_instance_id', $deployment->service_instance_id)
            ->where('status', DeploymentStatus::Superseded->value)
            ->where('finished_at', '<', $deployment->finished_at)
            ->latest('finished_at')
            ->first();

        if (! $previous instanceof Deployment) {
            return null;
        }

        $summary = $this->summaries->one($previous);

        return $summary->canRollback ? $summary : null;
    }

    /**
     * @param  array{requests: int, errors: int, latency: list<int>|null}  $sum
     */
    private function window(array $sum, int $seconds): DeploymentImpactWindowData
    {
        $p95 = $sum['latency'] === null ? null : RequestStats::quantile($sum['latency'], 0.95);

        return new DeploymentImpactWindowData(
            requests: $sum['requests'],
            requestsPerSecond: round($sum['requests'] / max(1, $seconds), 3),
            errorRatePct: $sum['requests'] > 0 ? round(100 * $sum['errors'] / $sum['requests'], 2) : null,
            p95Ms: $p95 === null ? null : round($p95, 1),
        );
    }
}
