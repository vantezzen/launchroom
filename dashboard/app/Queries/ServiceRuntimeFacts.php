<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Canvas\ServiceRuntimeFactsData;
use App\Data\Deployments\ContainerExitData;
use App\Enums\WorkloadPhase;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Support\Deployments\ExitMeaning;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The service panel's runtime facts (G7): restarts of the live version's
 * replicas and their last exit with its reason. Reads the placements of the
 * active deployment only (restart counts and `last_exit` that launchroomd
 * reported, D68), and a known problem seen in its logs (G12,
 * `deployments.runtime_finding`). Null when the live version never
 * restarted, nothing exited with an error and its logs showed nothing known.
 */
final class ServiceRuntimeFacts
{
    public function for(ServiceInstance $instance): ?ServiceRuntimeFactsData
    {
        if ($instance->active_deployment_id === null) {
            return null;
        }

        $placements = Placement::query()
            ->where('team_id', $instance->team_id)
            ->where('service_instance_id', $instance->id)
            ->where('deployment_id', $instance->active_deployment_id)
            ->where('phase', '!=', WorkloadPhase::Removed->value)
            ->with('server:id,name')
            ->orderBy('slot')
            ->get();

        $finding = Deployment::query()->whereKey($instance->active_deployment_id)->first(['id', 'runtime_finding'])?->runtime_finding;
        $crashes = $this->recentCrashes($instance);
        $quiet = $finding === null && $crashes === null ? null : new ServiceRuntimeFactsData(0, null, null, null, null, null, $finding, $crashes);

        if ($placements->isEmpty()) {
            return $quiet;
        }

        $restarts = $placements->sum(static fn (Placement $placement): int => $placement->restart_count);
        $latest = null;

        foreach ($placements as $placement) {
            $exit = $placement->last_exit;

            if (! $exit instanceof ContainerExitData || ! $this->failed($exit)) {
                continue;
            }

            if ($latest === null || $this->time($exit)->greaterThan($this->time($latest->last_exit))) {
                $latest = $placement;
            }
        }

        if ($restarts === 0 && $latest === null) {
            return $quiet;
        }

        $exit = $latest?->last_exit;
        $reason = $exit instanceof ContainerExitData ? $this->reason($exit) : null;
        $restartText = $restarts === 0 ? null
            : 'Restarted '.($restarts === 1 ? 'once' : "{$restarts} times").' since this version went live'.($reason === null ? '' : " ({$reason})");

        return new ServiceRuntimeFactsData(
            restarts: $restarts,
            restartText: $restartText,
            lastExit: $exit instanceof ContainerExitData ? ExitMeaning::summaryOfExit($exit) : null,
            lastExitAt: $exit?->at,
            lastExitWhere: $latest instanceof Placement && $placements->count() > 1 ? 'Replica '.($latest->slot + 1).' on '.$latest->server->name : null,
            advice: $exit instanceof ContainerExitData ? ExitMeaning::advice($exit->code, $exit->oomKilled, $exit->signal, $exit->byLaunchroom) : null,
            finding: $finding,
            recentCrashes: $crashes,
        );
    }

    /**
     * Crashes of the live replicas in the last 24 hours (`service_crashes`,
     * G7), with the newest one's reason: they outlive a redeploy's restart
     * counts and show a service that keeps crashing and coming back.
     */
    private function recentCrashes(ServiceInstance $instance): ?string
    {
        $crashes = DB::table('service_crashes')
            ->where('team_id', $instance->team_id)
            ->where('service_instance_id', $instance->id)
            ->where('at', '>=', now()->subDay());
        $count = (clone $crashes)->count();

        if ($count === 0) {
            return null;
        }

        $newest = (clone $crashes)->latest('at')->first(['exit_code', 'signal', 'oom_killed']);
        $reason = $newest === null ? null : $this->short(ExitMeaning::of(
            is_numeric($newest->exit_code ?? null) ? (int) $newest->exit_code : null,
            (bool) ($newest->oom_killed ?? false),
            is_numeric($newest->signal ?? null) ? (int) $newest->signal : null,
        ));

        return 'Crashed '.($count === 1 ? 'once' : "{$count} times").' in the last 24 hours'.($reason === null ? '' : " ({$reason})");
    }

    /** An exit worth telling: a non-zero code or out of memory. */
    private function failed(ContainerExitData $exit): bool
    {
        return $exit->oomKilled || ($exit->code !== null && $exit->code !== 0);
    }

    /** "out of memory", "killed: SIGKILL": the meaning without "it was"/"it". */
    private function reason(ContainerExitData $exit): ?string
    {
        return $this->short(ExitMeaning::ofExit($exit));
    }

    private function short(?string $meaning): ?string
    {
        if ($meaning === null) {
            return null;
        }

        // No nested brackets inside the fact's own: "killed: SIGKILL".
        return (string) preg_replace(['/^it (?:was |ran )?/', '/ \(([^)]+)\)$/'], ['', ': $1'], $meaning);
    }

    private function time(?ContainerExitData $exit): CarbonImmutable
    {
        return $exit instanceof ContainerExitData ? CarbonImmutable::parse($exit->at) : CarbonImmutable::createFromTimestamp(0);
    }
}
