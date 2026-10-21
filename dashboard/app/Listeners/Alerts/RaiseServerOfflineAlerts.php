<?php

declare(strict_types=1);

namespace App\Listeners\Alerts;

use App\Actions\Alerts\RaiseAlert;
use App\Actions\Alerts\ResolveAlert;
use App\Data\Alerts\AlertSignal;
use App\Enums\Alerts\AlertRule;
use App\Enums\ServerStatus;
use App\Events\ServerStatusChanged;
use App\Models\Server;
use Throwable;

/**
 * `server_offline` (docs/15 §3.4): a server without contact for 5 minutes
 * (status offline) raises an alert; online again resolves it; a revoked
 * server's alert resolves quietly.
 */
final readonly class RaiseServerOfflineAlerts
{
    public function __construct(
        private RaiseAlert $raise,
        private ResolveAlert $resolve,
    ) {}

    public function handle(ServerStatusChanged $event): void
    {
        if (! in_array($event->to, [ServerStatus::Offline, ServerStatus::Online, ServerStatus::Revoked], true)) {
            return;
        }

        try {
            $this->apply($event);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function apply(ServerStatusChanged $event): void
    {
        $server = Server::query()->whereKey($event->serverId)->with('team')->first();

        if (! $server instanceof Server) {
            return;
        }

        $subject = 'server:'.$server->public_id;

        match ($event->to) {
            ServerStatus::Online => $this->resolve->handle($server->team_id, AlertRule::ServerOffline, $subject, "{$server->name} is back online."),
            ServerStatus::Revoked => $this->resolve->handle($server->team_id, AlertRule::ServerOffline, $subject, "{$server->name} was removed.", notify: false),
            default => $this->raise->handle(new AlertSignal(
                team: $server->team,
                rule: AlertRule::ServerOffline,
                subject: $subject,
                title: "{$server->name} is offline",
                summary: 'It has not been in touch for 5 minutes. Apps with replicas on other servers keep serving; the server page lists what to check.',
                href: "/servers/{$server->public_id}",
                server: $server,
                facts: array_filter(['Last seen' => $server->last_seen_at?->toDayDateTimeString()], static fn (?string $v): bool => $v !== null),
            )),
        };
    }
}
