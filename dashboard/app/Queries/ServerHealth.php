<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Servers\HealthCheckData;
use App\Data\Servers\Host\DiskCleanupData;
use App\Data\Servers\Host\DiskUsageData;
use App\Data\Servers\Host\DockerReachData;
use App\Data\Servers\Host\HostHealthData;
use App\Data\Servers\Host\HostStatusData;
use App\Data\Servers\Host\InotifyLimitsData;
use App\Data\Servers\Host\MemoryUsageData;
use App\Data\Servers\Host\NetworkCountData;
use App\Data\Servers\Host\ReconcileStatusData;
use App\Data\Servers\Host\RegistryReachData;
use App\Data\Servers\ServerHealthData;
use App\Enums\HealthCheckState;
use App\Enums\Servers\CleanupTrigger;
use App\Models\Environment;
use App\Models\Server;
use App\Support\Network\SubnetAllocator;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns a server's latest host facts (servers.host_status) into health
 * checks with warning/critical thresholds (config `launchroom.server_health`).
 * The checks cover what most often breaks self-hosted Docker installs: full
 * disks, exhausted Docker address pools, low inotify limits and clock skew.
 */
final readonly class ServerHealth
{
    public function evaluate(Server $server): ServerHealthData
    {
        $host = $server->host_status;
        $health = $host->health ?? new HostHealthData;
        $collectedAt = $this->time($health->collectedAt);
        $stale = ! $collectedAt instanceof CarbonImmutable || $collectedAt->lt(now()->subSeconds($this->int('stale_after_seconds')));

        $checks = [
            $this->freshness($collectedAt, $stale),
            $this->disk($health),
            $this->memory($health),
            $this->load($health, $host?->cpus),
            $this->inotify($health),
            $this->networks($health),
            $this->clock($health),
            $this->buildCache($health),
            $this->cleanup($health),
            $this->registry($host?->registry),
            $this->docker($host),
            $this->desiredState($server),
        ];

        if ($stale) {
            // Old numbers are not a basis for "ok": show them, but as unknown.
            $checks = array_map(static fn (HealthCheckData $check): HealthCheckData => in_array($check->key, ['report', 'desired_state', 'docker'], true) || $check->state === HealthCheckState::Unknown
                ? $check
                : new HealthCheckData($check->key, $check->label, HealthCheckState::Unknown, $check->value, $check->detail), $checks);
        }

        $state = HealthCheckState::Ok;

        foreach ($checks as $check) {
            $state = $state->worst($check->state);
        }

        return new ServerHealthData($state, $collectedAt, $stale, $checks);
    }

    private function freshness(?CarbonImmutable $collectedAt, bool $stale): HealthCheckData
    {
        if (! $collectedAt instanceof CarbonImmutable) {
            return new HealthCheckData('report', 'Health report', HealthCheckState::Unknown, 'Not reported yet', 'The server reports host health a few minutes after it connects. Servers on an older version than the dashboard don’t report it.');
        }

        return new HealthCheckData(
            'report',
            'Health report',
            $stale ? HealthCheckState::Warning : HealthCheckState::Ok,
            $collectedAt->diffForHumans(),
            $stale ? 'The server has not sent fresh host facts. Check that launchroom runs on it and can reach the dashboard.' : null,
        );
    }

    private function disk(HostHealthData $health): HealthCheckData
    {
        $fs = $health->dockerDisk ?? $health->dataDisk;
        $label = $health->dockerDisk instanceof DiskUsageData ? 'Disk (Docker)' : 'Disk';

        if (! $fs instanceof DiskUsageData || $fs->totalMb <= 0) {
            return new HealthCheckData('disk', $label, HealthCheckState::Unknown, null);
        }

        [$total, $free] = [$fs->totalMb, $fs->freeMb];
        $percent = $free * 100 / $total;
        $state = $this->below($percent, 'disk_free_warning_percent', 'disk_free_critical_percent');
        // Many small files (caches, sessions) can fill the inodes while space is left.
        $inodes = $fs->inodesFreePercent();
        $inodeState = $inodes === null ? HealthCheckState::Ok : match (true) {
            $inodes < 5 => HealthCheckState::Critical,
            $inodes < 10 => HealthCheckState::Warning,
            default => HealthCheckState::Ok,
        };

        if ($inodes !== null && $inodeState !== HealthCheckState::Ok && $inodeState->worst($state) === $inodeState) {
            return new HealthCheckData('disk', $label, $inodeState, sprintf('%d%% of file entries free', (int) floor($inodes)),
                'The disk has space left but almost no room for new files (inodes). This usually comes from millions of small files, such as caches or sessions in a volume. Delete them or move them to a database.');
        }

        return new HealthCheckData('disk', $label, $state, sprintf('%s free of %s (%d%%)', $this->size($free), $this->size($total), (int) round($percent)),
            $state === HealthCheckState::Ok ? null : 'Free up space. launchroom removes unused images daily and when the disk is 85% full, but it doesn’t touch old databases, volumes or other software on the server. The Disk section shows what takes the space.');
    }

    private function memory(HostHealthData $health): HealthCheckData
    {
        $memory = $health->memory;

        if (! $memory instanceof MemoryUsageData || $memory->totalMb <= 0) {
            return new HealthCheckData('memory', 'Memory', HealthCheckState::Unknown, null);
        }

        [$total, $available] = [$memory->totalMb, $memory->availableMb];
        $percent = $available * 100 / $total;
        $state = $this->below($percent, 'memory_available_warning_percent', 'memory_available_critical_percent');

        return new HealthCheckData('memory', 'Memory', $state, sprintf('%s available of %s', $this->size($available), $this->size($total)),
            $state === HealthCheckState::Ok ? null : 'Services may be killed for lack of memory. Lower memory limits, move services to another server or add memory.');
    }

    private function load(HostHealthData $health, ?int $cpus): HealthCheckData
    {
        $load5 = $health->load?->load5;

        if ($load5 === null) {
            return new HealthCheckData('load', 'Load', HealthCheckState::Unknown, null);
        }

        $perCpu = $load5 / max(1, $cpus ?? 1);
        $state = match (true) {
            $perCpu >= $this->float('load_per_cpu_critical') => HealthCheckState::Critical,
            $perCpu >= $this->float('load_per_cpu_warning') => HealthCheckState::Warning,
            default => HealthCheckState::Ok,
        };

        return new HealthCheckData('load', 'Load', $state, sprintf('%.2f (5 min) on %d CPU%s', $load5, $cpus ?? 1, ($cpus ?? 1) === 1 ? '' : 's'),
            $state === HealthCheckState::Ok ? null : 'The server is overloaded: builds and requests will be slow.');
    }

    private function inotify(HostHealthData $health): HealthCheckData
    {
        if (! $health->inotify instanceof InotifyLimitsData) {
            return new HealthCheckData('inotify', 'File watch limits', HealthCheckState::Unknown, null);
        }

        $watches = $health->inotify->maxUserWatches;
        $instances = $health->inotify->maxUserInstances;

        $low = $watches < $this->int('inotify_min_watches') || $instances < $this->int('inotify_min_instances');

        return new HealthCheckData('inotify', 'File watch limits', $low ? HealthCheckState::Warning : HealthCheckState::Ok,
            sprintf('%s watches, %s instances', number_format($watches), number_format($instances)),
            $low ? sprintf('Low inotify limits cause "too many open files" errors in containers. Raise fs.inotify.max_user_watches to at least %s and fs.inotify.max_user_instances to at least %s in /etc/sysctl.d on the server.', number_format($this->int('inotify_min_watches')), number_format($this->int('inotify_min_instances'))) : null);
    }

    private function networks(HostHealthData $health): HealthCheckData
    {
        $networks = $health->networks;
        $rangeFull = $this->rangeUsedPercent() >= $this->int('environment_range_warning_percent');

        if (! $networks instanceof NetworkCountData) {
            return new HealthCheckData('networks', 'Docker networks', $rangeFull ? HealthCheckState::Warning : HealthCheckState::Unknown, null,
                $rangeFull ? $this->rangeHint() : null);
        }

        [$total, $managed] = [$networks->total, $networks->managed];
        $unmanaged = max(0, $total - $managed);
        $state = match (true) {
            $unmanaged >= $this->int('unmanaged_networks_critical') => HealthCheckState::Critical,
            $unmanaged >= $this->int('unmanaged_networks_warning') => HealthCheckState::Warning,
            $rangeFull => HealthCheckState::Warning,
            default => HealthCheckState::Ok,
        };

        $detail = match (true) {
            $state === HealthCheckState::Ok => null,
            $unmanaged >= $this->int('unmanaged_networks_warning') => "{$unmanaged} networks use Docker's default address pools, which run out at about 30. Remove unused networks that other software created on this server.",
            default => $this->rangeHint(),
        };

        return new HealthCheckData('networks', 'Docker networks', $state, sprintf('%d total, %d from launchroom', $total, $managed), $detail);
    }

    private function rangeHint(): string
    {
        return "launchroom's environment address range is nearly full. Delete unused environments or widen LAUNCHROOM_ENVIRONMENT_RANGE.";
    }

    private function clock(HostHealthData $health): HealthCheckData
    {
        $skew = $health->clockSkewMs;

        if ($skew === null) {
            return new HealthCheckData('clock', 'Clock', HealthCheckState::Unknown, null);
        }

        $seconds = abs($skew) / 1000;
        $state = match (true) {
            $seconds >= $this->int('clock_skew_critical_seconds') => HealthCheckState::Critical,
            $seconds >= $this->int('clock_skew_warning_seconds') => HealthCheckState::Warning,
            default => HealthCheckState::Ok,
        };

        return new HealthCheckData('clock', 'Clock', $state, $seconds < 1 ? 'In sync with the dashboard' : sprintf('%s s %s the dashboard', number_format($seconds, 1), $skew > 0 ? 'behind' : 'ahead of'),
            $state === HealthCheckState::Ok ? null : 'Signed requests are refused beyond 60 s of skew. Enable time sync (NTP, e.g. systemd-timesyncd) on the server.');
    }

    private function buildCache(HostHealthData $health): HealthCheckData
    {
        $mb = $health->buildCacheMb;

        return new HealthCheckData('build_cache', 'Build cache', is_int($mb) ? HealthCheckState::Ok : HealthCheckState::Unknown,
            is_int($mb) ? $this->size($mb) : 'No build role');
    }

    private function cleanup(HostHealthData $health): HealthCheckData
    {
        $cleanup = $health->cleanup;
        $at = $this->time($cleanup?->at);

        if (! $cleanup instanceof DiskCleanupData || ! $at instanceof CarbonImmutable) {
            return new HealthCheckData('cleanup', 'Disk cleanup', HealthCheckState::Unknown, 'Not run yet', 'Runs daily and when the disk is 85% full.');
        }

        $images = $cleanup->removedImages;
        $reclaimed = $cleanup->reclaimedMb;
        $error = $cleanup->error;
        $trigger = $cleanup->trigger === CleanupTrigger::DiskPressure ? ' (disk almost full)' : '';

        return new HealthCheckData('cleanup', 'Disk cleanup', $error === null ? HealthCheckState::Ok : HealthCheckState::Warning,
            sprintf('%s%s: %d image%s removed, %s freed', $at->diffForHumans(), $trigger, $images, $images === 1 ? '' : 's', $this->size($reclaimed)),
            $error === null ? null : 'Partly failed: '.$error);
    }

    /**
     * The dashboard refused to publish an invalid NodeState (render_error),
     * or launchroomd refused one (state_rejection): either way the server
     * keeps running its previous desired state and new rollouts stall.
     * Not derived from host facts, so never "unknown" when facts are stale.
     */
    private function desiredState(Server $server): HealthCheckData
    {
        $render = $server->render_error;
        $rejected = $server->state_rejection;

        if ($render !== null) {
            return new HealthCheckData('desired_state', 'Desired state', HealthCheckState::Critical,
                'Not rendered: the previous version stays applied',
                $render->message.' The deployments that caused it were marked as failed. Fix the configuration and deploy again.');
        }

        if ($rejected !== null) {
            return new HealthCheckData('desired_state', 'Desired state', HealthCheckState::Critical,
                sprintf('Server refused v%d and keeps running v%d', $rejected->version, $server->observed_version),
                'launchroomd: '.$rejected->error);
        }

        if ($server->last_report_at === null) {
            return new HealthCheckData('desired_state', 'Desired state', HealthCheckState::Unknown, 'Not reported yet');
        }

        return new HealthCheckData('desired_state', 'Desired state', HealthCheckState::Ok, sprintf('v%d of v%d applied', $server->observed_version, $server->desired_version));
    }

    /**
     * Whether the server reaches the image registry it pulls from (D68):
     * a server that can't makes every rollout on it wait for its image.
     */
    private function registry(?RegistryReachData $registry): HealthCheckData
    {
        if (! $registry instanceof RegistryReachData) {
            return new HealthCheckData('registry', 'Image registry', HealthCheckState::Unknown, 'Not checked yet');
        }

        if (! $registry->reachable) {
            return new HealthCheckData('registry', 'Image registry', HealthCheckState::Critical,
                'Not reachable'.($registry->error !== null ? ': '.$registry->error : ''),
                'This server downloads images from the registry on the main server over the private network. Deploys to it wait until it reaches the registry again. Check the private network connection on this page.');
        }

        $latency = $registry->latencyMs;

        return new HealthCheckData('registry', 'Image registry', HealthCheckState::Ok, $latency === null ? 'Reachable' : "Reachable ({$latency} ms)");
    }

    /**
     * Whether launchroom reaches Docker on the server and can run apps there
     * (Report.host.docker and the last reconcile pass, G3). Not derived from
     * the health snapshot, so never "unknown" when facts are stale.
     */
    private function docker(?HostStatusData $host): HealthCheckData
    {
        $docker = $host?->docker;
        $reconcile = $host?->reconcile;

        if (! $docker instanceof DockerReachData && ! $reconcile instanceof ReconcileStatusData) {
            return new HealthCheckData('docker', 'Docker', HealthCheckState::Unknown, 'Not reported yet');
        }

        if ($docker instanceof DockerReachData && ! $docker->reachable) {
            return new HealthCheckData('docker', 'Docker', HealthCheckState::Critical, 'Not reachable since '.CarbonImmutable::parse($docker->checkedAt)->diffForHumans(),
                'launchroom can’t start, stop or check apps on this server. The ones running keep running. Check that Docker runs on the machine.'.($docker->error !== null ? ' Docker said: '.$docker->error : ''));
        }

        if ($reconcile instanceof ReconcileStatusData && $reconcile->has('docker_unreachable', 'guard_refused')) {
            return new HealthCheckData('docker', 'Docker', HealthCheckState::Critical, 'Online, but can’t run apps',
                'launchroom couldn’t change containers in its last pass, so new versions and restarts wait.');
        }

        if ($reconcile instanceof ReconcileStatusData && $reconcile->has('not_isolated')) {
            return new HealthCheckData('docker', 'Docker', HealthCheckState::Critical, 'Apps held: firewall not working',
                'launchroom only starts apps once their networks are isolated from each other. Check the server’s firewall (iptables or nftables) and Docker’s network settings.');
        }

        $complete = $reconcile?->lastCompleteAt !== null ? CarbonImmutable::parse($reconcile->lastCompleteAt) : null;

        if ($reconcile instanceof ReconcileStatusData && $reconcile->errors !== [] && (! $complete instanceof CarbonImmutable || $complete->lt(now()->subMinutes(30)))) {
            return new HealthCheckData('docker', 'Docker', HealthCheckState::Warning, 'Reachable, with errors',
                'launchroom’s last passes over the apps on this server didn’t finish. '.($reconcile->errors[0]->message ?? $reconcile->errors[0]->code));
        }

        return new HealthCheckData('docker', 'Docker', HealthCheckState::Ok, 'Reachable');
    }

    private function rangeUsedPercent(): float
    {
        try {
            $capacity = SubnetAllocator::capacity(
                config()->string('launchroom.networks.environment_range'),
                config()->integer('launchroom.networks.environment_prefix'),
            );
        } catch (Throwable) {
            return 0.0;
        }

        return $capacity > 0 ? Environment::query()->whereNotNull('network_subnet')->count() * 100 / $capacity : 0.0;
    }

    private function below(float $percent, string $warning, string $critical): HealthCheckState
    {
        return match (true) {
            $percent < $this->int($critical) => HealthCheckState::Critical,
            $percent < $this->int($warning) => HealthCheckState::Warning,
            default => HealthCheckState::Ok,
        };
    }

    private function time(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value);
    }

    private function size(int $mb): string
    {
        return $mb >= 1024 ? sprintf('%.1f GB', $mb / 1024) : "{$mb} MB";
    }

    private function int(string $key): int
    {
        return config()->integer("launchroom.server_health.{$key}");
    }

    private function float(string $key): float
    {
        return config()->float("launchroom.server_health.{$key}");
    }
}
