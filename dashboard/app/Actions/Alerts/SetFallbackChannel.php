<?php

declare(strict_types=1);

namespace App\Actions\Alerts;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\Alerts\AlertChannelKind;
use App\Enums\AuditAction;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Models\Alerts\AlertChannel;
use App\Models\Server;
use App\Services\Alerts\FallbackWatchdog;
use Illuminate\Support\Facades\DB;

/**
 * "Also use it when launchroom itself is down" (OD7, D90): marks the one
 * channel launchroomd notifies through while the dashboard can't be reached
 * (the control-plane server and one peer, FallbackWatchdog). Instance owners
 * only (the caller authorizes). At most one per instance: marking a channel
 * unmarks the others. It must be a channel of the control-plane server's
 * team, an email channel only while the dashboard sends mail through SMTP.
 * `allowPrivateHosts` lets its URL point at a private address (refused by
 * default).
 */
final readonly class SetFallbackChannel
{
    public function __construct(
        private RecordAuditEvent $audit,
        private FallbackWatchdog $watchdog,
    ) {}

    public function handle(AlertChannel $channel, bool $fallback, bool $allowPrivateHosts, AuditActor $actor): void
    {
        if ($fallback) {
            $controlPlane = Server::query()->controlPlane()->orderBy('id')->first();

            if (! $controlPlane instanceof Server || $controlPlane->team_id !== $channel->team_id) {
                throw DomainRuleViolation::on('fallback', 'Only a channel of the team that owns the main server can be used when launchroom is down.');
            }

            if ($channel->kind === AlertChannelKind::Email && ! FallbackWatchdog::smtpAvailable()) {
                throw DomainRuleViolation::on('fallback', 'Email works for this only when the dashboard sends mail through an SMTP server with a password.');
            }
        }

        $servers = $this->watchdog->servers();

        DB::transaction(function () use ($channel, $fallback, $allowPrivateHosts, $actor): void {
            if ($fallback) {
                AlertChannel::query()->where('fallback', true)->whereKeyNot($channel->id)->update(['fallback' => false, 'allow_private_hosts' => false]);
            }

            $channel->forceFill(['fallback' => $fallback, 'allow_private_hosts' => $fallback && $allowPrivateHosts])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::AlertChannelUpdated,
                actor: $actor,
                teamId: $channel->team_id,
                subjectType: 'alert_channel',
                subjectId: $channel->public_id,
                meta: ['changed' => [$fallback ? 'fallbackOn' : 'fallbackOff'], 'allowPrivateHosts' => $fallback && $allowPrivateHosts],
            ));
        });

        NodeStateInvalidated::servers([...$servers, ...$this->watchdog->servers()]);
    }
}
