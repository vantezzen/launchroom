<?php

declare(strict_types=1);

namespace App\Actions\Alerts;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Alerts\AlertChannelUpdateInput;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\Alerts\AlertChannelKind;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Alerts\AlertChannel;
use App\Services\Alerts\AlertChannelSecrets;
use App\Services\Alerts\ChannelSecret;
use App\Services\Alerts\ChannelTargets;
use App\Support\Alerts\ChannelUrls;
use Illuminate\Support\Facades\DB;

/**
 * Changes a channel: name, pause/resume, minimum severity, addresses, a new
 * URL, or a new webhook signing secret (returned once). A blank URL keeps
 * the stored one.
 */
final readonly class UpdateAlertChannel
{
    public function __construct(
        private ChannelTargets $targets,
        private AlertChannelSecrets $secrets,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @return string|null the new signing secret, when one was generated
     */
    public function handle(AlertChannel $channel, AlertChannelUpdateInput $input, AuditActor $actor): ?string
    {
        if ($input->rotateSecret && $channel->kind !== AlertChannelKind::Webhook) {
            throw DomainRuleViolation::on('rotateSecret', 'Only webhooks have a signing secret.');
        }

        $url = $channel->kind->hasSecret() && trim((string) $input->secretUrl) !== '' ? $this->targets->url($channel->kind, $input->secretUrl) : null;
        $recipients = $channel->kind === AlertChannelKind::Email && $input->recipients !== null ? $this->targets->recipients($input->recipients) : null;

        return DB::transaction(function () use ($channel, $input, $actor, $url, $recipients): ?string {
            $changed = [];
            $signing = null;

            if ($input->name !== null && trim($input->name) !== '') {
                $channel->name = trim($input->name);
                $changed[] = 'name';
            }

            if ($input->enabled !== null) {
                $channel->enabled = $input->enabled;
                $changed[] = $input->enabled ? 'resumed' : 'paused';
            }

            if ($input->minSeverity instanceof AlertSeverity) {
                $channel->min_severity = $input->minSeverity;
                $changed[] = 'minSeverity';
            }

            if ($recipients !== null) {
                $channel->recipients = $recipients;
                $changed[] = 'recipients';
            }

            if ($url !== null || $input->rotateSecret) {
                $current = $this->secrets->reveal($channel);
                $signing = $input->rotateSecret ? AlertChannelSecrets::generateSigningSecret() : $current->signingSecret;
                $target = $url ?? $current->url;
                $this->secrets->seal($channel, new ChannelSecret($target, $signing));
                $channel->target_hint = ChannelUrls::hint($target);
                $changed[] = $url !== null ? 'url' : 'signingSecret';
            }

            $channel->save();

            if ($changed !== []) {
                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::AlertChannelUpdated,
                    actor: $actor,
                    teamId: $channel->team_id,
                    subjectType: 'alert_channel',
                    subjectId: $channel->public_id,
                    meta: ['changed' => $changed],
                ));
            }

            return $input->rotateSecret ? $signing : null;
        });
    }
}
