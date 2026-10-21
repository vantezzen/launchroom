<?php

declare(strict_types=1);

namespace App\Actions\Alerts;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Alerts\AlertChannelInput;
use App\Data\Alerts\CreatedAlertChannel;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\Alerts\AlertChannelKind;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Alerts\AlertChannel;
use App\Models\Team;
use App\Models\User;
use App\Services\Alerts\AlertChannelSecrets;
use App\Services\Alerts\ChannelSecret;
use App\Services\Alerts\ChannelTargets;
use App\Support\Alerts\ChannelUrls;
use App\Support\PublicId;
use Illuminate\Support\Facades\DB;

/**
 * Adds a notification channel (docs/15 §3.5): validated target, secrets
 * envelope-encrypted per channel, a generated signing secret for webhooks
 * (returned once). Audited without any secret.
 */
final readonly class CreateAlertChannel
{
    public const int MAX_CHANNELS = 20;

    public function __construct(
        private ChannelTargets $targets,
        private AlertChannelSecrets $secrets,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Team $team, AlertChannelInput $input, User $user, AuditActor $actor): CreatedAlertChannel
    {
        $recipients = $input->kind === AlertChannelKind::Email ? $this->targets->recipients($input->recipients) : null;
        $url = $input->kind->hasSecret() ? $this->targets->url($input->kind, $input->secretUrl) : null;
        $signing = $input->kind === AlertChannelKind::Webhook ? AlertChannelSecrets::generateSigningSecret() : null;

        $channel = DB::transaction(function () use ($team, $input, $user, $actor, $recipients, $url, $signing): AlertChannel {
            // Serialise per team so two requests can't both pass the cap.
            Team::query()->whereKey($team->id)->lockForUpdate()->first();

            if (AlertChannel::query()->where('team_id', $team->id)->count() >= self::MAX_CHANNELS) {
                throw DomainRuleViolation::conflict('A team can have up to '.self::MAX_CHANNELS.' notification channels.');
            }

            $channel = new AlertChannel([
                'team_id' => $team->id,
                'kind' => $input->kind,
                'name' => $this->name($input, $recipients),
                'recipients' => $recipients,
                'target_hint' => $url === null ? null : ChannelUrls::hint($url),
                'min_severity' => $input->minSeverity,
                'created_by' => $user->id,
            ]);
            $channel->public_id = PublicId::generate(AlertChannel::publicIdPrefix());

            if ($url !== null) {
                $this->secrets->seal($channel, new ChannelSecret($url, $signing));
            }

            $channel->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::AlertChannelCreated,
                actor: $actor,
                teamId: $team->id,
                subjectType: 'alert_channel',
                subjectId: $channel->public_id,
                meta: ['kind' => $input->kind->value],
            ));

            return $channel;
        });

        return new CreatedAlertChannel($channel, $signing);
    }

    /**
     * @param  list<string>|null  $recipients
     */
    private function name(AlertChannelInput $input, ?array $recipients): string
    {
        $name = trim((string) $input->name);

        if ($name !== '') {
            return $name;
        }

        return match ($input->kind) {
            AlertChannelKind::Email => $recipients !== null && count($recipients) === 1 ? $recipients[0] : count($recipients ?? []).' email addresses',
            default => $input->kind->label(),
        };
    }
}
