<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Ingress\DnsProviderCheckData;
use App\Data\Instance\LegacyOriginEntryData;
use App\Data\Instance\OnboardingStateData;
use App\Data\Instance\RecoveryStateData;
use App\Data\Moves\MoveChecklistStateData;
use App\Data\Security\AuditVerificationData;
use App\Enums\ClientCertificateMode;
use App\Enums\DnsProvider;
use App\Enums\Mesh\MeshMode;
use App\Support\Crypto\OpaqueToken;
use App\Support\PublicId;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SensitiveParameter;
use Spatie\LaravelData\DataCollection;

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
 * @property string|null $recovery_kit_reset_reason passphrase_changed|storage_changed: why a kit saved before must be saved again
 * @property OnboardingStateData|null $onboarding
 * @property MeshMode|null $mesh_mode how servers are connected; null = mesh off (docs/05 §2)
 * @property Carbon|null $mesh_enabled_at
 * @property string|null $mesh_login_server "bring your own Tailscale" login server
 * @property string|null $mesh_headscale_user the embedded Headscale's user for launchroom servers
 * @property string|null $mesh_policy_hash sha256 of the last applied ACL policy
 * @property string|null $mesh_error last error enabling or maintaining the mesh (key-free)
 * @property Carbon|null $mesh_error_at
 * @property DnsProvider|null $dns_provider DNS-01 provider for the platform domain's wildcard certificates (docs/05 §5)
 * @property Carbon|null $dns_verified_at last successful token check
 * @property DnsProviderCheckData|null $dns_verification last token check (no secrets)
 * @property string|null $registry_config_hash sha256 of the last Zot config written by `launchroom:registry:config --write` (docs/14 §9.11)
 * @property Carbon|null $registry_config_written_at
 * @property string|null $node_state_config_hash sha256 of the configuration every NodeState was last rendered with (RefreshNodeStates)
 * @property RecoveryStateData|null $recovery_state
 * @property string|null $update_channel stable|beta chosen in Settings → Updates; null = config `launchroom.updates.channel`, else beta on a pre-release version, stable otherwise (ReleaseChecker::channel)
 * @property bool $auto_update install new releases automatically at night (docs/11 §4; off by default)
 * @property int|null $auto_update_by the instance owner who turned automatic updates on (their actor)
 * @property Carbon|null $auto_update_changed_at
 * @property string|null $dashboard_url canonical origin chosen in Settings → Dashboard domain; null = APP_URL (docs/05 §4.1)
 * @property DataCollection<int, LegacyOriginEntryData>|null $dashboard_legacy_urls earlier origins that keep answering (redirects; launchroomd's fallback)
 * @property string|null $dashboard_pending_host a new domain routed while it is verified (DNS points here)
 * @property string|null $passkey_rp_id WebAuthn relying party id in use; null = the canonical host
 * @property Carbon|null $dashboard_switched_at
 * @property AuditVerificationData|null $audit_verification
 * @property ClientCertificateMode|null $daemon_client_certificates client certificates for daemon transport (docs/03 §4.3); null = off
 * @property Carbon|null $daemon_client_certificates_changed_at
 * @property string|null $daemon_edge_secret the secret the control-plane edge adds to proxied requests (X-Lr-Edge), encrypted with APP_KEY
 * @property Carbon|null $frozen_at writes are refused while the control plane is handed over to a new server (docs/14 §13.4)
 * @property string|null $frozen_reason
 * @property string|null $moved_to the address this instance moved to (the new dashboard took over)
 * @property MoveChecklistStateData|null $move_checklist
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['setup_token_hash', 'recovery_mac_key', 'daemon_edge_secret'])]
#[Table(name: 'instance_settings')]
#[WithoutIncrementing]
final class InstanceSettings extends Model
{
    public const int ID = 1;

    /** Container key of the per-request copy (scoped: a new request or queued job reads the row again). */
    private const string CURRENT = 'launchroom.instance-settings';

    /**
     * The singleton row, created on first use. Read once per request (or
     * queued job) and shared: a full page asks for it about nine times.
     * Saving or deleting any copy of the row forgets the shared one, so a
     * write followed by a read in the same request sees the write.
     */
    public static function current(): self
    {
        $container = Container::getInstance();

        if (! $container->bound(self::CURRENT)) {
            $container->scoped(self::CURRENT, static fn (): self => self::read());
        }

        $settings = $container->make(self::CURRENT);

        return $settings instanceof self ? $settings : self::read();
    }

    /**
     * A stable id for this launchroom instance, generated on first use. It
     * names backups and audit batches, and survives a control-plane restore
     * because it lives in the restored database.
     */
    public static function instanceId(): string
    {
        $settings = self::current();

        if ($settings->instance_id === null) {
            self::query()->whereKey(self::ID)->whereNull('instance_id')->update(['instance_id' => PublicId::generate('ins')]);
            self::forgetCurrent();
            $settings = self::current();
        }

        return (string) $settings->instance_id;
    }

    private static function forgetCurrent(): void
    {
        Container::getInstance()->forgetInstance(self::CURRENT);
    }

    public function clientCertificateMode(): ClientCertificateMode
    {
        return $this->daemon_client_certificates ?? ClientCertificateMode::Off;
    }

    /**
     * First-run setup is done once the owner exists (docs/11 §2).
     */
    public function setupDone(): bool
    {
        return $this->setup_completed_at !== null || User::query()->exists();
    }

    /**
     * $token is the unexpired setup token (`launchroom:setup-token`).
     */
    public function acceptsSetupToken(#[SensitiveParameter] string $token): bool
    {
        return $token !== ''
            && $this->setup_token_hash !== null
            && $this->setup_token_expires_at?->isFuture() === true
            && OpaqueToken::matches($token, $this->setup_token_hash);
    }

    protected static function booted(): void
    {
        self::saved(static function (): void {
            self::forgetCurrent();
        });
        self::deleted(static function (): void {
            self::forgetCurrent();
        });
    }

    private static function read(): self
    {
        $settings = self::query()->find(self::ID);

        if ($settings instanceof self) {
            return $settings;
        }

        self::ensureExists();

        return self::query()->findOrFail(self::ID);
    }

    /**
     * The saved recovery kit no longer matches (docs/14 §4): it has to be
     * confirmed again ($downloaded: also downloaded again). $reason
     * (`passphrase_changed`, `storage_changed`) is kept only when a kit had
     * been saved before, so the page can say why. The caller saves.
     */
    public function forgetRecoveryKit(string $reason, bool $downloaded = true): self
    {
        $saved = $this->recovery_kit_confirmed_at !== null || $this->recovery_kit_downloaded_at !== null;

        return $this->forceFill([
            'recovery_kit_confirmed_at' => null,
            ...($downloaded ? ['recovery_kit_downloaded_at' => null] : []),
            'recovery_kit_reset_reason' => $saved ? $reason : $this->recovery_kit_reset_reason,
        ]);
    }

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
            'onboarding' => OnboardingStateData::class,
            'dns_provider' => DnsProvider::class,
            'dns_verified_at' => 'datetime',
            'dns_verification' => DnsProviderCheckData::class,
            'mesh_mode' => MeshMode::class,
            'mesh_enabled_at' => 'datetime',
            'mesh_error_at' => 'datetime',
            'registry_config_written_at' => 'datetime',
            'recovery_state' => RecoveryStateData::class,
            'auto_update' => 'boolean',
            'auto_update_changed_at' => 'datetime',
            'dashboard_legacy_urls' => DataCollection::class.':'.LegacyOriginEntryData::class,
            'dashboard_switched_at' => 'datetime',
            'daemon_client_certificates' => ClientCertificateMode::class,
            'daemon_client_certificates_changed_at' => 'datetime',
            'daemon_edge_secret' => 'encrypted',
            'audit_verification' => AuditVerificationData::class,
            'frozen_at' => 'datetime',
            'move_checklist' => MoveChecklistStateData::class,
        ];
    }
}
