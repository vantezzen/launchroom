<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Data\Protocol\V1\WatchdogSpec;
use App\Enums\Alerts\AlertChannelKind;
use App\Enums\DaemonFeature;
use App\Enums\ServerStatus;
use App\Models\Alerts\AlertChannel;
use App\Models\Server;
use App\Services\Crypto\SealedColumns;
use App\Support\Crypto\IngressSecretSealer;
use App\Support\Protocol\Grammar;
use SensitiveParameter;

/**
 * `system.watchdog` of a server's NodeState (D90, OD7): the control-plane
 * server watches the dashboard (`control_plane`) and one peer server
 * watches from outside (`peer`): online, not the control plane, the
 * control-plane server's team, the newest launchroom version that has the
 * `watchdog` feature (oldest id on a tie). Both get the instance's fallback
 * channel (`alert_channels.fallback`, at most one, a channel of the
 * control-plane server's team), its secret sealed to the server:
 *
 * - webhook, Slack, Discord: `{url}`;
 * - email: the dashboard's SMTP server (only when mail goes out through
 *   SMTP to a DNS name) as `smtp {host, port, from, to}`, the password and
 *   user name sealed.
 *
 * Without a usable channel the role is still rendered (the watchdog
 * restarts hung services) with no fallback.
 */
final readonly class FallbackWatchdog
{
    public const int MAX_RECIPIENTS = 5;

    public function __construct(
        private SealedColumns $sealed,
    ) {}

    public function for(Server $server): ?WatchdogSpec
    {
        // An older launchroomd would drop it anyway (FeatureGate); nothing is sealed for it.
        $role = $server->supportsDaemonFeature(DaemonFeature::Watchdog) ? $this->role($server) : null;

        if ($role === null) {
            return null;
        }

        $channel = $this->channel();

        if (! $channel instanceof AlertChannel || $channel->team_id !== $server->team_id) {
            return new WatchdogSpec($role);
        }

        $values = $this->values($channel);
        $smtp = $channel->kind === AlertChannelKind::Email ? $this->smtp($channel) : null;

        if ($values === null || ($channel->kind === AlertChannelKind::Email && $smtp === null)) {
            return new WatchdogSpec($role);
        }

        return new WatchdogSpec(
            role: $role,
            fallbackKind: $channel->kind->value,
            fallbackSecretSealed: IngressSecretSealer::seal($server->sealing_public_key, $server->public_id, IngressSecretSealer::FALLBACK_CHANNEL, $values),
            // Secret-free: changes when the channel's secret changes.
            fallbackSecretFingerprint: hash('sha256', $channel->public_id.'|'.$this->fingerprint($values)),
            smtp: $smtp,
            allowPrivateHosts: $channel->allow_private_hosts,
            serverLabel: mb_substr($server->name, 0, 128),
        );
    }

    /**
     * The servers whose NodeState carries a watchdog (the control plane and its peer).
     *
     * @return list<int>
     */
    public function servers(): array
    {
        $controlPlane = $this->controlPlane();

        return array_values(array_filter([$controlPlane?->id, $controlPlane instanceof Server ? $this->peer($controlPlane)?->id : null], is_int(...)));
    }

    /**
     * Whether an email channel can be the fallback: the dashboard sends mail
     * through SMTP to a DNS name.
     */
    public static function smtpAvailable(): bool
    {
        return self::smtpConfig() !== null;
    }

    /**
     * @return 'control_plane'|'peer'|null
     */
    private function role(Server $server): ?string
    {
        if ($server->is_control_plane) {
            return 'control_plane';
        }

        $controlPlane = $this->controlPlane();

        return $controlPlane instanceof Server && $this->peer($controlPlane)?->id === $server->id ? 'peer' : null;
    }

    private function controlPlane(): ?Server
    {
        return Server::query()->controlPlane()->orderBy('id')->first();
    }

    private function peer(Server $controlPlane): ?Server
    {
        $candidates = Server::query()
            ->where('team_id', $controlPlane->team_id)
            ->where('is_control_plane', false)
            ->where('status', ServerStatus::Online->value)
            ->orderBy('id')
            ->get()
            ->filter(static fn (Server $server): bool => $server->supportsDaemonFeature(DaemonFeature::Watchdog))
            ->values()
            ->all();

        usort($candidates, static fn (Server $a, Server $b): int => version_compare(ltrim($b->daemon_version ?? '0', 'v'), ltrim($a->daemon_version ?? '0', 'v')) ?: $a->id <=> $b->id);

        return $candidates[0] ?? null;
    }

    private function channel(): ?AlertChannel
    {
        return AlertChannel::query()->where('fallback', true)->where('enabled', true)->orderBy('id')->first();
    }

    /**
     * @return array<string, string>|null
     */
    private function values(AlertChannel $channel): ?array
    {
        if ($channel->kind->hasSecret()) {
            $url = $this->sealed->revealJson($channel, 'secret')['url'] ?? null;

            return is_string($url) && $url !== '' ? ['url' => $url] : null;
        }

        $config = self::smtpConfig();

        if ($config === null) {
            return null;
        }

        return array_filter(['password' => $config['password'], 'username' => $config['username']], static fn (string $value): bool => $value !== '') ?: null;
    }

    /**
     * @return array{host: string, port: int, from: string, to: list<string>}|null
     */
    private function smtp(AlertChannel $channel): ?array
    {
        $config = self::smtpConfig();
        $to = array_values(array_filter($channel->recipients ?? [], static fn (string $address): bool => preg_match(Grammar::EMAIL, $address) === 1));

        if ($config === null || $to === []) {
            return null;
        }

        return ['host' => $config['host'], 'port' => $config['port'], 'from' => $config['from'], 'to' => array_slice($to, 0, self::MAX_RECIPIENTS)];
    }

    /**
     * @return array{host: string, port: int, from: string, username: string, password: string}|null
     */
    private static function smtpConfig(): ?array
    {
        $mailer = config('mail.default');
        $smtp = is_string($mailer) ? config("mail.mailers.{$mailer}") : null;
        $from = config('mail.from.address');

        if (! is_array($smtp) || ($smtp['transport'] ?? null) !== 'smtp' || ! is_string($from) || preg_match(Grammar::EMAIL, $from) !== 1) {
            return null;
        }

        $host = is_string($smtp['host'] ?? null) ? strtolower($smtp['host']) : '';
        $port = is_numeric($smtp['port'] ?? null) ? (int) $smtp['port'] : 0;
        $username = is_string($smtp['username'] ?? null) ? $smtp['username'] : '';
        $password = is_string($smtp['password'] ?? null) ? $smtp['password'] : '';

        if (preg_match(Grammar::HOSTNAME, $host) !== 1 || $port < 1 || $port > 65535 || $password === '') {
            return null;
        }

        return ['host' => $host, 'port' => $port, 'from' => $from, 'username' => $username, 'password' => $password];
    }

    /**
     * @param  array<string, string>  $values
     */
    private function fingerprint(#[SensitiveParameter] array $values): string
    {
        ksort($values);

        return hash_hmac('sha256', (string) json_encode($values), config()->string('app.key'));
    }
}
