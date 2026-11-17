<?php

declare(strict_types=1);

namespace App\Services\Alerts\Conditions;

use App\Data\Alerts\AlertSignal;
use App\Enums\Alerts\AlertRule;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Throwable;

/**
 * `queue_backlog` (G41): the oldest job waiting on one of the dashboard's
 * queues (Horizon's supervisors) is older than WAIT_MINUTES, so deploys,
 * alerts and backups run late. Measured from the queue itself (the
 * scheduler runs this, not a queue worker). Goes to the control-plane
 * server's team.
 */
final readonly class QueueBacklog extends AlertCondition
{
    public const int WAIT_MINUTES = 5;

    public function __construct(
        private QueueFactory $queues,
    ) {}

    public function rule(): AlertRule
    {
        return AlertRule::QueueBacklog;
    }

    public function subjectPrefix(): string
    {
        return 'control_plane:';
    }

    public function resolvedReason(): string
    {
        return 'Background jobs run on time again.';
    }

    public function failing(array $rules, array $firing, CarbonImmutable $now): array
    {
        $server = $this->controlPlaneServer();

        if (! $server instanceof Server) {
            return [];
        }

        $late = [];

        foreach ($this->queueNames() as [$connection, $queue]) {
            try {
                $oldest = $this->queues->connection($connection)->creationTimeOfOldestPendingJob($queue);
            } catch (Throwable) {
                continue;
            }

            if (is_int($oldest) && $oldest <= $now->subMinutes(self::WAIT_MINUTES)->getTimestamp()) {
                $late[$queue] = intdiv($now->getTimestamp() - $oldest, 60);
            }
        }

        if ($late === []) {
            return [];
        }

        arsort($late);
        $facts = [];

        foreach (array_slice($late, 0, 8, true) as $queue => $minutes) {
            $facts['Queue '.$queue] = "oldest job waits {$minutes} min";
        }

        return [$server->team_id.'|control_plane:queues' => fn (): AlertSignal => new AlertSignal(
            team: $this->team($server->team_id),
            rule: AlertRule::QueueBacklog,
            subject: 'control_plane:queues',
            title: 'Background work is falling behind',
            summary: 'Jobs wait longer than '.self::WAIT_MINUTES.' minutes, so deploys, alerts and backups start late. Check that Horizon runs on '.$server->name.'.',
            href: route('system.show', [], false),
            server: $server,
            facts: $facts,
        )];
    }

    /**
     * Every queue a Horizon supervisor works on.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function queueNames(): array
    {
        $supervisors = config('horizon.defaults');
        $out = [];

        foreach (is_array($supervisors) ? $supervisors : [] as $supervisor) {
            if (! is_array($supervisor)) {
                continue;
            }

            $connection = is_string($supervisor['connection'] ?? null) ? $supervisor['connection'] : 'redis';

            foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                if (is_string($queue)) {
                    $out["{$connection}|{$queue}"] = [$connection, $queue];
                }
            }
        }

        return array_values($out);
    }
}
