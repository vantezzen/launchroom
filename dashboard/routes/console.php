<?php

declare(strict_types=1);

use App\Jobs\Git\PollTrackedRefsJob;
use App\Services\Moves\InstanceFreeze;
use App\Services\Scheduling\ScheduleLocks;
use App\Services\Scheduling\SchedulerHeartbeat;
use Illuminate\Console\Scheduling\Schedule as EventSchedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Every event runs without overlapping, with a lock that expires after a
// few times its usual run time (never Laravel's default 24 h): a scheduler
// killed mid-run must not keep a command locked for a day (tests/Arch/ScheduleMutexTest).

// Liveness hysteresis, rescheduling off dead servers, lease expiry and stuck
// build pins (docs/03 §7, §9, docs/02 §6). Every 15 s: replicas move 90–105 s
// after a server's last contact.
Schedule::command('launchroom:servers:check-liveness')->everyFifteenSeconds()->withoutOverlapping(5);

// System image pins and other instance-wide NodeState inputs change with an
// update: re-render every NodeState once when they differ (RefreshNodeStates).
Schedule::command('launchroom:node-states:refresh')->everyMinute()->withoutOverlapping(10);

// GitHub polling fallback for tracked refs when webhooks are stale (docs/06 §2).
Schedule::job(new PollTrackedRefsJob)->everyMinute()->name('github:poll-tracked-refs')->withoutOverlapping(10);

// Drained workloads and deployment timeouts (docs/04 §6, §8).
Schedule::command('launchroom:deployments:sweep')->everyMinute()->withoutOverlapping(10);

// Interactive sessions no server picked up (docs/03 §11.3).
Schedule::command('launchroom:sessions:sweep')->everyMinute()->withoutOverlapping(10);
Schedule::command('launchroom:remote-access:sweep')->everyMinute()->withoutOverlapping(10);

// Backups: due schedules, retention, task follow-up, restores (docs/14 §3).
Schedule::command('launchroom:backups:tick')->everyMinute()->withoutOverlapping(10);

// Data import and export (docs/14 §12): imports move on, old transfers go.
Schedule::command('launchroom:transfers:tick')->everyMinute()->withoutOverlapping(10);
Schedule::command('launchroom:transfers:sweep')->everyFiveMinutes()->withoutOverlapping(15);

// Live DNS verification of custom domains (docs/05 §5).
Schedule::command('launchroom:domains:verify')->everyFiveMinutes()->withoutOverlapping(15);

// A dashboard domain being set up: DNS, certificate, HTTPS test (docs/05 §4.1).
Schedule::command('launchroom:dashboard-domain:check')->everyMinute()->withoutOverlapping(10);

// Rollback targets whose images Zot's retention removed (docs/04 §8).
Schedule::command('launchroom:registry:check-retained-images')->hourly()->withoutOverlapping(60);

// Preview environments: data preparation, queue, expiry (docs/06 §5).
Schedule::command('launchroom:previews:tick')->everyMinute()->withoutOverlapping(10);

// Previews whose pull request closed while webhooks were missed (docs/06 §5).
Schedule::command('launchroom:previews:sweep-orphans')->dailyAt('03:17')->withoutOverlapping(120);

// Dashboard-driven updates: finished self_update tasks, next server (docs/11 §4).
Schedule::command('launchroom:updates:advance')->everyMinute()->withoutOverlapping(10);

// Automatic updates (opt-in): at night on the control-plane server (docs/11 §4).
Schedule::command('launchroom:updates:auto')->hourlyAt(7)->withoutOverlapping(60);

// Volumes of deleted environments/projects/previews whose retention passed (docs/05 §1.1).
Schedule::command('launchroom:volumes:retire')->everyFifteenMinutes()->withoutOverlapping(30);

// Mesh: API key rotation, ACL policy, node tags, pending server keys (docs/05 §2).
Schedule::command('launchroom:mesh:maintain')->everyFiveMinutes()->withoutOverlapping(15);

// Automatic shared certificate storage: the Valkey ACL user lives in memory only (docs/05 §5).
Schedule::command('launchroom:ingress:cert-storage-user')->everyMinute()->withoutOverlapping(10);

// Request trace keys: one per edge, rotated every 30 days, readable 90 days (docs/23 §4).
Schedule::command('launchroom:traces:keys')->hourlyAt(13)->withoutOverlapping(60);

// Usage metrics: 10-minute and daily rollups, retention 48 h / 30 days / 1 year (docs/07);
// request analytics: hourly and daily rollups, retention 48 h / 7 days / 90 days (docs/15 §5.3);
// web firewall: hourly rollups, retention 7 / 90 days, events 7 days (docs/22 §6.5).
Schedule::command('launchroom:metrics:rollup')->everyTenMinutes()->withoutOverlapping(30);

// Runtime logs past their retention period or size cap; live-tail lines after 24 h (docs/08 §12).
Schedule::command('launchroom:logs:prune')->everyFifteenMinutes()->withoutOverlapping(30);

// Private-network link quality: hourly rollups are kept 30 days (docs/05 §2.1).
Schedule::command('launchroom:links:prune')->dailyAt('04:23')->withoutOverlapping(120);

// Alerts (docs/15 §3): condition rules every minute, history retention daily.
Schedule::command('launchroom:alerts:evaluate')->everyMinute()->withoutOverlapping(10);
Schedule::command('launchroom:alerts:prune')->dailyAt('04:41')->withoutOverlapping(120);

// Public API (docs/16): expired CLI sign-ins, old idempotency keys, long-expired tokens.
Schedule::command('launchroom:api:prune')->dailyAt('04:53')->withoutOverlapping(120);

// Audit log (docs/09 R34): signed checkpoints every 10 minutes, then a check
// from the newest checkpoint; a full check from the first entry daily;
// forwarding retries and backlog every minute.
Schedule::command('launchroom:audit:checkpoint')->everyTenMinutes()->withoutOverlapping(30)
    ->after(static fn () => Artisan::call('launchroom:audit:verify', ['--from-checkpoint' => true]));
Schedule::command('launchroom:audit:verify')->dailyAt('03:41')->withoutOverlapping(180);
Schedule::command('launchroom:audit:deliver')->everyMinute()->withoutOverlapping(10);

// Security center (docs/22 §3–4): OSV every 6 hours (jittered), KEV and
// endoflife.date daily, backfill and re-evaluation hourly, retention daily.
Schedule::command('launchroom:security:sync')->everySixHours()->withoutOverlapping(360);
Schedule::command('launchroom:security:feeds')->dailyAt('05:11')->withoutOverlapping(120);
Schedule::command('launchroom:security:backfill')->hourlyAt(23)->withoutOverlapping(60);
Schedule::command('launchroom:security:prune')->dailyAt('04:29')->withoutOverlapping(120);

// Generate a Dockerfile (docs/20 §5): timeouts and missed task events, then housekeeping.
Schedule::command('launchroom:dockerfile-generations:sweep')->everyMinute()->withoutOverlapping(10);
Schedule::command('launchroom:dockerfile-generations:prune')->dailyAt('04:37')->withoutOverlapping(120);

// Server moves and the control-plane move (docs/14 §13): every 15 s, and
// right after the tasks and deployments they wait for (AdvanceServerMovesJob).
Schedule::command('launchroom:moves:tick')->everyFifteenSeconds()->withoutOverlapping(5);

// While the control plane is handed over to a new server (and after it moved
// there) this dashboard changes nothing on its own (docs/14 §13.4). Each run
// records when it started, so `launchroom:doctor` can tell a lock left by a
// scheduler that stopped mid-run from a command that is still running.
foreach (Schedule::events() as $event) {
    $event->skip(static fn (): bool => InstanceFreeze::active());
    $event->before(static fn (ScheduleLocks $locks) => $locks->started($event));
}

// The scheduler's heartbeat (`launchroom:scheduler:alive`, the compose
// healthcheck; launchroomd's watchdog restarts a scheduler whose check
// fails). Registered after the freeze above: it keeps beating while frozen.
Schedule::call(static fn (EventSchedule $schedule, SchedulerHeartbeat $heartbeat) => $heartbeat->beat($schedule))
    ->everyMinute()
    ->name(SchedulerHeartbeat::EVENT)
    ->withoutOverlapping(2);
