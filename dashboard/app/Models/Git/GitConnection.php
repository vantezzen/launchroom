<?php

declare(strict_types=1);

namespace App\Models\Git;

use App\Contracts\Git\GitAccount;
use App\Enums\Git\GitAuthMethod;
use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\GitProviderKind;
use App\Enums\Git\GitServerKind;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Services\Git\AvatarOrigins;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A team's connection to a GitLab, Gitea/Forgejo or plain git server
 * (docs/19 §3). GitLab/Gitea: one per provider account (OAuth or access
 * token). Plain git: one per repository (deploy key + pinned host keys, or a
 * public https remote). Secrets are envelope-encrypted and only decrypted by
 * `App\Services\Git\GitConnectionSecrets`.
 *
 * @property int $id
 * @property string $public_id
 * @property GitProviderKind $provider
 * @property GitServerKind $server_kind
 * @property string $base_url
 * @property string $name
 * @property string|null $account_login
 * @property int|null $account_id
 * @property GitAuthMethod $auth_method
 * @property string|null $oauth_client_id
 * @property string $wrapped_dek
 * @property string $kek_id
 * @property string|null $oauth_client_secret
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property CarbonImmutable|null $token_expires_at
 * @property string $webhook_secret
 * @property string|null $ssh_private_key
 * @property string|null $ssh_public_key
 * @property list<array{type: string, key: string, fingerprint: string}>|null $host_keys
 * @property CarbonImmutable|null $host_keys_confirmed_at
 * @property bool $allow_private_network
 * @property GitConnectionStatus $status
 * @property string|null $status_reason
 * @property CarbonImmutable|null $webhook_last_received_at
 * @property CarbonImmutable|null $webhook_missed_at polling found a push no webhook delivered (docs/06 §2.2)
 * @property CarbonImmutable|null $repositories_synced_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WebhookDelivery|null $latestDelivery
 */
#[Fillable([
    'team_id', 'provider', 'server_kind', 'base_url', 'name', 'account_login', 'account_id', 'auth_method',
    'oauth_client_id', 'ssh_public_key', 'host_keys', 'host_keys_confirmed_at', 'allow_private_network', 'created_by',
])]
#[Hidden(['wrapped_dek', 'kek_id', 'oauth_client_secret', 'access_token', 'refresh_token', 'webhook_secret', 'ssh_private_key'])]
#[Table(name: 'git_connections')]
final class GitConnection extends Model implements GitAccount
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'allow_private_network' => false,
    ];

    public static function publicIdPrefix(): string
    {
        return 'gic';
    }

    protected static function booted(): void
    {
        // The CSP's img-src lists active connections' origins (docs/19 §7).
        self::saved(static function (): void {
            AvatarOrigins::forget();
        });
        self::deleted(static function (): void {
            AvatarOrigins::forget();
        });
    }

    public function gitProvider(): GitProviderKind
    {
        return $this->provider;
    }

    public function accountId(): int
    {
        return $this->id;
    }

    public function accountTeamId(): int
    {
        return $this->team_id;
    }

    /**
     * @return HasMany<GitRepository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(GitRepository::class, 'git_connection_id');
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'git_connection_id');
    }

    /**
     * @return HasOne<WebhookDelivery, $this>
     */
    public function latestDelivery(): HasOne
    {
        return $this->hasOne(WebhookDelivery::class, 'git_connection_id')->ofMany('id', 'max');
    }

    public function isActive(): bool
    {
        return $this->status === GitConnectionStatus::Active;
    }

    /**
     * The only way a connection's status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(GitConnectionStatus $to, ?string $reason = null): void
    {
        $this->status->assertCanTransitionTo($to);

        $this->status = $to;
        $this->status_reason = $reason === null ? null : mb_substr($reason, 0, 255);
        $this->save();
    }

    /**
     * Whether webhooks deliver pushes (docs/06 §2.2): a verified delivery
     * arrived, and none was missed since. A quiet repository stays healthy;
     * only a push that polling found and no webhook delivered within the
     * grace period counts against it, until the next delivery.
     */
    public function hasHealthyWebhooks(): bool
    {
        return $this->webhook_last_received_at !== null
            && ($this->webhook_missed_at === null || $this->webhook_last_received_at->greaterThan($this->webhook_missed_at));
    }

    /**
     * The web origin without a trailing slash (`https://gitlab.com`).
     */
    public function origin(): string
    {
        return rtrim($this->base_url, '/');
    }

    /**
     * API root: `<origin>/api/v4` (GitLab) or `<origin>/api/v1` (Gitea).
     */
    public function apiUrl(): string
    {
        return $this->origin().($this->provider === GitProviderKind::GitLab ? '/api/v4' : '/api/v1');
    }

    /**
     * Host part of the origin, for display ("gitlab.com").
     */
    public function host(): string
    {
        $host = parse_url($this->base_url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $this->base_url;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => GitProviderKind::class,
            'server_kind' => GitServerKind::class,
            'auth_method' => GitAuthMethod::class,
            'status' => GitConnectionStatus::class,
            'account_id' => 'integer',
            'host_keys' => 'array',
            'allow_private_network' => 'boolean',
            'token_expires_at' => 'datetime',
            'host_keys_confirmed_at' => 'datetime',
            'webhook_last_received_at' => 'datetime',
            'webhook_missed_at' => 'datetime',
            'repositories_synced_at' => 'datetime',
        ];
    }
}
