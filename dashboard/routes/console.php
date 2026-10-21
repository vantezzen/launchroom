<?php

declare(strict_types=1);

use App\Jobs\Git\PollTrackedRefsJob;
use App\Services\Moves\InstanceFreeze;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Liveness hysteresis, rescheduling off dead servers, lease expiry and stuck
// build pins (docs/03 §7, §9, docs/02 §6). Every 15 s: replicas move 90–105 s
// after a server's last contact.
Schedule::command('launchroom:servers:check-liveness')->everyFifteenSeconds()->withoutOverlapping();

// GitHub polling fallback for tracked refs when webhooks are stale (docs/06 §2).
Schedule::job(new PollTrackedRefsJob)->everyMinute()->name('github:poll-tracked-refs')->withoutOverlapping();

// Drained workloads and deployment timeouts (docs/04 §6, §8).
Schedule::command('launchroom:deployments:sweep')->everyMinute()->withoutOverlapping();

// Interactive sessions no server picked up (docs/03 §11.3).
Schedule::command('launchroom:sessions:sweep')->everyMinute()->withoutOverlapping();

// Backups: due schedules, retention, task follow-up, restores (docs/14 §3).
Schedule::command('launchroom:backups:tick')->everyMinute()->withoutOverlapping();

// Data import and export (docs/14 §12): imports move on, old transfers go.
Schedule::command('launchroom:transfers:tick')->everyMinute()->withoutOverlapping();
Schedule::command('launchroom:transfers:sweep')->everyFiveMinutes()->withoutOverlapping();

// Live DNS verification of custom domains (docs/05 §5).
Schedule::command('launchroom:domains:verify')->everyFiveMinutes()->withoutOverlapping();

// A dashboard domain being set up: DNS, certificate, HTTPS test (docs/05 §4.1).
Schedule::command('launchroom:dashboard-domain:check')->everyMinute()->withoutOverlapping();

// Rollback targets whose images Zot's retention removed (docs/04 §8).
Schedule::command('launchroom:registry:check-retained-images')->hourly()->withoutOverlapping();

// Preview environments: data preparation, queue, expiry (docs/06 §5).
Schedule::command('launchroom:previews:tick')->everyMinute()->withoutOverlapping();

// Previews whose pull request closed while webhooks were missed (docs/06 §5).
Schedule::command('launchroom:previews:sweep-orphans')->dailyAt('03:17')->withoutOverlapping();

// Dashboard-driven updates: finished self_update tasks, next server (docs/11 §4).
Schedule::command('launchroom:updates:advance')->everyMinute()->withoutOverlapping();

// Automatic updates (opt-in): at night on the control-plane server (docs/11 §4).
Schedule::command('launchroom:updates:auto')->hourlyAt(7)->withoutOverlapping();

// Volumes of deleted environments/projects/previews whose retention passed (docs/05 §1.1).
Schedule::command('launchroom:volumes:retire')->everyFifteenMinutes()->withoutOverlapping();

// Mesh: API key rotation, ACL policy, node tags, pending server keys (docs/05 §2).
Schedule::command('launchroom:mesh:maintain')->everyFiveMinutes()->withoutOverlapping();

// Automatic shared certificate storage: the Valkey ACL user lives in memory only (docs/05 §5).
Schedule::command('launchroom:ingress:cert-storage-user')->everyMinute()->withoutOverlapping();

// Usage metrics: 10-minute and daily rollups, retention 48 h / 30 days / 1 year (docs/07).
Schedule::command('launchroom:metrics:rollup')->everyTenMinutes()->withoutOverlapping();

// Runtime logs past their retention period or size cap; live-tail lines after 24 h (docs/08 §12).
Schedule::command('launchroom:logs:prune')->everyFifteenMinutes()->withoutOverlapping();

// Private-network link quality: hourly rollups are kept 30 days (docs/05 §2.1).
Schedule::command('launchroom:links:prune')->dailyAt('04:23')->withoutOverlapping();

// Alerts (docs/15 §3): condition rules every minute, history retention daily.
Schedule::command('launchroom:alerts:evaluate')->everyMinute()->withoutOverlapping();
Schedule::command('launchroom:alerts:prune')->dailyAt('04:41')->withoutOverlapping();

// Public API (docs/16): expired CLI sign-ins, old idempotency keys, long-expired tokens.
Schedule::command('launchroom:api:prune')->dailyAt('04:53')->withoutOverlapping();

// Audit log (docs/09 R34): signed checkpoints every 10 minutes, then a check
// from the newest checkpoint; a full check from the first entry daily;
// forwarding retries and backlog every minute.
Schedule::command('launchroom:audit:checkpoint')->everyTenMinutes()->withoutOverlapping()
    ->after(static fn () => Artisan::call('launchroom:audit:verify', ['--from-checkpoint' => true]));
Schedule::command('launchroom:audit:verify')->dailyAt('03:41')->withoutOverlapping();
Schedule::command('launchroom:audit:deliver')->everyMinute()->withoutOverlapping();

// Generate a Dockerfile (docs/20 §5): timeouts and missed task events, then housekeeping.
Schedule::command('launchroom:dockerfile-generations:sweep')->everyMinute()->withoutOverlapping();
Schedule::command('launchroom:dockerfile-generations:prune')->dailyAt('04:37')->withoutOverlapping();

// Server moves and the control-plane move (docs/14 §13): every 15 s, and
// right after the tasks and deployments they wait for (AdvanceServerMovesJob).
Schedule::command('launchroom:moves:tick')->everyFifteenSeconds()->withoutOverlapping();

// While the control plane is handed over to a new server (and after it moved
// there) this dashboard changes nothing on its own (docs/14 §13.4).
foreach (Schedule::events() as $event) {
    $event->skip(static fn (): bool => InstanceFreeze::active());
}
