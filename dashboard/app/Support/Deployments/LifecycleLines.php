<?php

declare(strict_types=1);

namespace App\Support\Deployments;

use App\Data\Protocol\V1\WorkloadEvent;
use App\Data\Services\HealthcheckSettings;
use App\Enums\HealthcheckType;
use Illuminate\Support\Str;

/**
 * The deployment log's and the Logs tab's management entries for container
 * lifecycle events (D68, docs/15 §6): launchroomd reports typed events, the
 * dashboard owns the words. "Container started (replica 1, server-2)",
 * "Container exited with code 1 (replica 1, server-2)", "Health check
 * failed: GET / on :8000 → connection refused (3/3)", "Container stopped by
 * launchroom (replaced by deployment dpl_…)".
 */
final readonly class LifecycleLines
{
    /**
     * @param  string|null  $replacedBy  the deployment that replaced this one (stops and removals)
     * @param  string|null  $removedBecause  why the workload left the desired state, in words ("the service was stopped")
     */
    public function __construct(
        public string $server,
        public ?int $replica,
        public ?int $port,
        public ?HealthcheckSettings $healthcheck,
        public ?string $replacedBy = null,
        public ?string $removedBecause = null,
    ) {}

    /**
     * The line's text and level.
     *
     * @return array{text: string, level: string}
     */
    public function line(WorkloadEvent $event): array
    {
        $where = $this->where();
        $restarts = is_int($event->restarts) ? $event->restarts : 0;
        $failures = is_int($event->failures) ? $event->failures : 0;
        $message = is_string($event->message) ? trim($event->message) : '';
        $code = is_int($event->exitCode) ? $event->exitCode : null;
        $reason = is_string($event->reason) ? $event->reason : null;

        return match ($event->kind) {
            'created' => $this->info("Container created ({$where})"),
            'create_failed' => $this->error("Couldn't start the container ({$where})".($message === '' ? '' : ": {$message}")),
            'started' => $this->info("Container started ({$where})"),
            'exited' => $event->oomKilled === true
                ? $this->error("Out of memory: the container was killed ({$where}). Raise its memory limit in Settings → Scaling.")
                : ($code === 0 ? $this->info("Container exited with code 0 ({$where})") : $this->error('Container exited'.($code === null ? '' : " with code {$code}")." ({$where})")),
            'restarted' => $this->warning('Container restarted (attempt '.($restarts + 1).", {$where})"),
            'health_passing' => $this->info('Health check passed'.$this->probe()." ({$where})"),
            'health_failing' => $this->error('Health check failed'.$this->probe().($message === '' ? '' : " → {$message}").' ('.$failures.'/'.max(1, $this->healthcheck->failureThreshold ?? 1).", {$where})"),
            'stopped' => $this->info("Container stopped by launchroom ({$this->because($reason)}, {$where})"),
            'removed' => $this->info(match ($reason) {
                'spec_changed' => "Container replaced by launchroom: its settings changed ({$where})",
                'duplicate' => "Duplicate container removed by launchroom ({$where})",
                'port_not_bound' => "Container recreated by launchroom to publish its port on the private network ({$where})",
                default => "Container removed by launchroom ({$this->because($reason)}, {$where})",
            }),
            default => $this->info("Container {$event->kind} ({$where})"),
        };
    }

    /**
     * "GET / on :8000", "TCP :5432", or none.
     */
    public static function probeLabel(?int $port, ?HealthcheckSettings $healthcheck): ?string
    {
        if ($port === null || ! $healthcheck instanceof HealthcheckSettings || $healthcheck->type === HealthcheckType::None) {
            return null;
        }

        return $healthcheck->type === HealthcheckType::Tcp ? "TCP :{$port}" : "GET {$healthcheck->path} on :{$port}";
    }

    private function probe(): string
    {
        $label = self::probeLabel($this->port, $this->healthcheck);

        return $label === null ? '' : ": {$label}";
    }

    private function because(?string $reason): string
    {
        return match (true) {
            $this->replacedBy !== null => "replaced by deployment {$this->replacedBy}",
            $this->removedBecause !== null => $this->removedBecause,
            $reason === 'drained' => 'its drain period ended',
            default => 'no longer needed',
        };
    }

    private function where(): string
    {
        return $this->replica === null ? $this->server : "replica {$this->replica}, {$this->server}";
    }

    /**
     * @return array{text: string, level: string}
     */
    private function info(string $text): array
    {
        return ['text' => Str::limit($text, 2000), 'level' => 'info'];
    }

    /**
     * @return array{text: string, level: string}
     */
    private function warning(string $text): array
    {
        return ['text' => Str::limit($text, 2000), 'level' => 'warning'];
    }

    /**
     * @return array{text: string, level: string}
     */
    private function error(string $text): array
    {
        return ['text' => Str::limit($text, 2000), 'level' => 'error'];
    }
}
