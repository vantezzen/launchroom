<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Alerts\CreateAlertChannel;
use App\Contracts\DnsResolver;
use App\Data\Alerts\AlertChannelInput;
use App\Data\Alerts\AlertSignal;
use App\Data\Audit\AuditActor;
use App\Enums\Alerts\AlertChannelKind;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Models\Alerts\AlertChannel;
use App\Models\Team;
use App\Models\User;

/**
 * Alert test helpers (docs/15 §3): public DNS answers for the channel hosts
 * (never real lookups) and channels created through the real action.
 */
final class Alerts
{
    public const string SLACK = 'https://hooks.slack.com/services/T0001/B0002/abcdefghijklmnopqrstuvwx';

    public const string DISCORD = 'https://discord.com/api/webhooks/123456789012345678/abcDEF_ghi-JKL';

    public const string WEBHOOK = 'https://hooks.example.com/launchroom';

    public static function fakeDns(): void
    {
        app()->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function resolve(string $hostname): array
            {
                return match ($hostname) {
                    'hooks.slack.com', 'discord.com', 'hooks.example.com' => ['93.184.216.34'],
                    'internal.example.com' => ['10.0.0.5'],
                    default => [],
                };
            }
        });
    }

    /**
     * @param  list<string>|null  $recipients
     */
    public static function channel(Team $team, User $user, AlertChannelKind $kind = AlertChannelKind::Slack, ?string $url = null, ?array $recipients = null, AlertSeverity $minSeverity = AlertSeverity::Warning): AlertChannel
    {
        return resolve(CreateAlertChannel::class)->handle($team, AlertChannelInput::from([
            'kind' => $kind,
            'secretUrl' => $url ?? match ($kind) {
                AlertChannelKind::Slack => self::SLACK,
                AlertChannelKind::Discord => self::DISCORD,
                AlertChannelKind::Webhook => self::WEBHOOK,
                AlertChannelKind::Email => null,
            },
            'recipients' => $recipients ?? ($kind === AlertChannelKind::Email ? ['ops@example.com'] : null),
            'minSeverity' => $minSeverity,
        ]), $user, AuditActor::user($user))->channel;
    }

    /**
     * @param  array<string, string>  $facts
     */
    public static function signal(Team $team, AlertRule $rule = AlertRule::BackupFailed, string $subject = 'backup:bks_01JB7Z6X9Q3T8M5N2P4R6S8V0W', ?string $occurrence = null, ?AlertSeverity $severity = null, array $facts = []): AlertSignal
    {
        return new AlertSignal(
            team: $team,
            rule: $rule,
            subject: $subject,
            title: 'Backup of postgres failed',
            summary: 'The bucket could not be reached. The last good backup is from 3 hours ago.',
            severity: $severity,
            facts: $facts,
            occurrence: $occurrence,
        );
    }
}
