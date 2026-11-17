<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Data;

/**
 * NodeState.system.watchdog (protocol WatchdogSpec, daemon feature
 * `watchdog`): `control_plane` on the control-plane host (restarts hung
 * dashboard services, never Postgres or Valkey, and notifies), `peer` on one
 * other server (only notifies). The fallback channel's secret is an
 * IngressSecret of purpose `fallback_channel` sealed to the server: "url"
 * (webhook, slack, discord) or "password" and an optional "username"
 * (email). `fallbackSecretFingerprint` identifies it without the randomized
 * ciphertext (render deduplication).
 */
final class WatchdogSpec extends Data
{
    public const array ROLES = ['control_plane', 'peer'];

    public const array KINDS = ['webhook', 'slack', 'discord', 'email'];

    /**
     * @param  'control_plane'|'peer'  $role
     * @param  'webhook'|'slack'|'discord'|'email'|null  $fallbackKind  null: no fallback channel
     * @param  array{host: string, port: int, from: string, to: list<string>}|null  $smtp  email channels only
     */
    public function __construct(
        public string $role,
        public ?string $fallbackKind = null,
        public ?string $fallbackSecretSealed = null,
        public ?string $fallbackSecretFingerprint = null,
        public ?array $smtp = null,
        public bool $allowPrivateHosts = false,
        public ?string $serverLabel = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toProtocol(): array
    {
        return $this->render(['sealed' => $this->fallbackSecretSealed ?? '']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toFingerprint(): array
    {
        return $this->render(['fingerprint' => $this->fallbackSecretFingerprint ?? '']);
    }

    /**
     * @param  array<string, string>  $secret
     * @return array<string, mixed>
     */
    private function render(array $secret): array
    {
        $spec = ['role' => $this->role];

        if ($this->fallbackKind === null) {
            return $spec;
        }

        $fallback = ['kind' => $this->fallbackKind, 'secret' => $secret];

        if ($this->smtp !== null) {
            $fallback['smtp'] = $this->smtp;
        }

        if ($this->allowPrivateHosts) {
            $fallback['allowPrivateHosts'] = true;
        }

        $fallback['serverLabel'] = $this->serverLabel ?? '';
        $spec['fallback'] = $fallback;

        return $spec;
    }
}
