<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClientCertificateMode;
use App\Enums\DnsProvider;
use App\Enums\Mesh\MeshMode;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The instance-level singleton row (docs/07 "Instance-level"; a check
 * constraint keeps it to id = 1).
 *
 * @property int $id
 * @property string|null $setup_token_hash
 * @property Carbon|null $setup_token_expires_at
 * @property Carbon|null $setup_completed_at
 * @property Carbon|null $recovery_kit_confirmed_at
 * @property string|null $apps_domain
 * @property string|null $instance_id
 * @property string|null $recovery_public_key
 * @property array{alg: string, salt: string, opslimit: int, memlimit: int}|null $recovery_kdf
 * @property string|null $recovery_mac_key base64 key authenticating key bundles (RecoveryKey::macKey), encrypted with APP_KEY
 * @property Carbon|null $recovery_passphrase_set_at
 * @property Carbon|null $recovery_kit_downloaded_at
 * @property array{dismissedAt?: string, skipped?: list<string>, twoFactorDismissed?: list<int>, smallServerHintDismissed?: list<int>, domainConfirmed?: array{domain: string, verified: bool|null, at: string}}|null $onboarding first-run checklist (docs/08 §3.1); twoFactorDismissed: user ids who chose "Not now" on the 2FA recommendation; smallServerHintDismissed: user ids who dismissed "This server is small"; domainConfirmed: the platform domain the owner continued with and whether its DNS check passed then (null: set before the check existed)
 * @property MeshMode|null $mesh_mode how servers are connected; null = mesh off (docs/05 §2)
 * @property Carbon|null $mesh_enabled_at
 * @property string|null $mesh_login_server "bring your own Tailscale" login server
 * @property string|null $mesh_headscale_user the embedded Headscale's user for launchroom servers
 * @property string|null $mesh_policy_hash sha256 of the last applied ACL policy
 * @property string|null $mesh_error last error enabling or maintaining the mesh (key-free)
 * @property Carbon|null $mesh_error_at
 * @property DnsProvider|null $dns_provider DNS-01 provider for the platform domain's wildcard certificates (docs/05 §5)
 * @property Carbon|null $dns_verified_at last successful token check
 * @property array{ok: bool, message: string, zone?: string|null, checkedAt: string}|null $dns_verification last token check (no secrets)
 * @property string|null $registry_config_hash sha256 of the last Zot config written by `launchroom:registry:config --write` (docs/14 §9.11)
 * @property Carbon|null $registry_config_written_at
 * @property string|null $node_state_config_hash sha256 of the configuration every NodeState was last rendered with (RefreshNodeStates)
 * @property array{startedAt: string, previousControlPlaneServerId?: int|null, replacementId?: int|null}|null $recovery_state recovery mode after `install --restore` (docs/14 §5.2)
 * @property string|null $update_channel stable|beta chosen in Settings → Updates; null = config `launchroom.updates.channel`, else beta on a pre-release version, stable otherwise (ReleaseChecker::channel)
 * @property bool $auto_update install new releases automatically at night (docs/11 §4; off by default)
 * @property int|null $auto_update_by the instance owner who turned automatic updates on (their actor)
 * @property Carbon|null $auto_update_changed_at
 * @property string|null $dashboard_url canonical origin chosen in Settings → Dashboard domain; null = APP_URL (docs/05 §4.1)
 * @property list<array<string, mixed>>|null $dashboard_legacy_urls earlier origins that keep answering (redirects; launchroomd's fallback)
 * @property string|null $dashboard_pending_host a new domain routed while it is verified (DNS points here)
 * @property string|null $passkey_rp_id WebAuthn relying party id in use; null = the canonical host
 * @property Carbon|null $dashboard_switched_at
 * @property array{ok: bool, mode: string, checkedAt: string, entries: int, checkpoints: int, verifiedUpTo: string|null, lastEventId: int|null, problem?: array{kind: string, eventId: int|null, checkpointId: int|null, message: string}}|null $audit_verification last audit chain verification (docs/09 R34)
 * @property ClientCertificateMode|null $daemon_client_certificates client certificates for daemon transport (docs/03 §4.3); null = off
 * @property Carbon|null $daemon_client_certificates_changed_at
 * @property string|null $daemon_edge_secret the secret the control-plane edge adds to proxied requests (X-Lr-Edge), encrypted with APP_KEY
 * @property Carbon|null $frozen_at writes are refused while the control plane is handed over to a new server (docs/14 §13.4)
 * @property string|null $frozen_reason
 * @property string|null $moved_to the address this instance moved to (the new dashboard took over)
 * @property array{startedAt: string, moveId?: string|null, previousServerId?: int|null, previousUrl?: string|null, newServerId?: int|null}|null $move_checklist the new dashboard's checklist after a move (docs/14 §13.4)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['setup_token_hash', 'recovery_mac_key', 'daemon_edge_secret'])]
#[Table(name: 'instance_settings')]
#[WithoutIncrementing]
final class InstanceSettings extends Model
{
    public const int ID = 1;

    /**
     * The singleton row, created on first use. Read first: the row exists on
     * every request but the very first, and this runs several times per page.
     */
    public static function current(): self
    {
        $settings = self::query()->find(self::ID);

        if ($settings instanceof self) {
            return $settings;
        }

        self::ensureExists();

        return self::query()->findOrFail(self::ID);
    }

    /**
     * The singleton row, locked for the current transaction.
     */
    public static function lockedForUpdate(): self
    {
        self::ensureExists();

        return self::query()->whereKey(self::ID)->lockForUpdate()->firstOrFail();
    }

    private static function ensureExists(): void
    {
        self::query()->insertOrIgnore(['id' => self::ID, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'setup_token_expires_at' => 'datetime',
            'setup_completed_at' => 'datetime',
            'recovery_kit_confirmed_at' => 'datetime',
            'recovery_kdf' => 'array',
            'recovery_mac_key' => 'encrypted',
            'recovery_passphrase_set_at' => 'datetime',
            'recovery_kit_downloaded_at' => 'datetime',
            'onboarding' => 'array',
            'dns_provider' => DnsProvider::class,
            'dns_verified_at' => 'datetime',
            'dns_verification' => 'array',
            'mesh_mode' => MeshMode::class,
            'mesh_enabled_at' => 'datetime',
            'mesh_error_at' => 'datetime',
            'registry_config_written_at' => 'datetime',
            'recovery_state' => 'array',
            'auto_update' => 'boolean',
            'auto_update_changed_at' => 'datetime',
            'dashboard_legacy_urls' => 'array',
            'dashboard_switched_at' => 'datetime',
            'daemon_client_certificates' => ClientCertificateMode::class,
            'daemon_client_certificates_changed_at' => 'datetime',
            'daemon_edge_secret' => 'encrypted',
            'audit_verification' => 'array',
            'frozen_at' => 'datetime',
            'move_checklist' => 'array',
        ];
    }
}
