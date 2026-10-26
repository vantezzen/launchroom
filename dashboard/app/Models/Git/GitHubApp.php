<?php

declare(strict_types=1);

namespace App\Models\Git;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Services\GitHub\GitHubAppSecrets;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A GitHub App created for a team through the manifest flow (docs/07, docs/09
 * R19). Secrets are envelope-encrypted ciphertexts; decrypt them only through
 * {@see GitHubAppSecrets}.
 *
 * @property int $id
 * @property string $public_id
 * @property int $app_id
 * @property string $slug
 * @property string $name
 * @property string|null $owner_login
 * @property string $client_id
 * @property string $web_url
 * @property string $api_url
 * @property string $html_url
 * @property string $wrapped_dek
 * @property string $kek_id
 * @property string $client_secret
 * @property string|null $webhook_secret
 * @property string $private_key
 * @property CarbonImmutable|null $webhook_last_received_at
 * @property CarbonImmutable|null $webhook_missed_at polling found a push no webhook delivered (docs/06 §2.2)
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WebhookDelivery|null $latestDelivery
 */
#[Fillable([
    'team_id', 'app_id', 'slug', 'name', 'owner_login', 'client_id', 'web_url', 'api_url', 'html_url', 'created_by',
])]
#[Hidden(['wrapped_dek', 'kek_id', 'client_secret', 'webhook_secret', 'private_key'])]
#[Table(name: 'github_apps')]
final class GitHubApp extends Model
{
    use BelongsToTeam, HasPublicId;

    public static function publicIdPrefix(): string
    {
        return 'gha';
    }

    /**
     * @return HasMany<GitHubInstallation, $this>
     */
    public function installations(): HasMany
    {
        return $this->hasMany(GitHubInstallation::class, 'github_app_id');
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'github_app_id');
    }

    /**
     * @return HasOne<WebhookDelivery, $this>
     */
    public function latestDelivery(): HasOne
    {
        return $this->hasOne(WebhookDelivery::class, 'github_app_id')->ofMany('id', 'max');
    }

    /**
     * GitHub page where the app is installed on another account.
     */
    public function installUrl(): string
    {
        return rtrim($this->htmlUrl(), '/').'/installations/new';
    }

    /**
     * The app's page, safe to link: the stored `html_url` comes from the
     * (possibly hostile) GitHub Enterprise server, so it is used only when it
     * is https on the configured web host (review L10).
     */
    public function htmlUrl(): string
    {
        return self::safeHtmlUrl($this->html_url, $this->web_url, $this->slug);
    }

    /**
     * $htmlUrl when it is an https URL on $webUrl's host without credentials,
     * else the app page derived from $webUrl and $slug.
     */
    public static function safeHtmlUrl(string $htmlUrl, string $webUrl, string $slug): string
    {
        $html = parse_url($htmlUrl);
        $web = parse_url($webUrl);
        $webHost = is_array($web) && isset($web['host']) ? strtolower($web['host']) : null;

        if (is_array($html) && ($html['scheme'] ?? null) === 'https' && $webHost !== null
            && strtolower($html['host'] ?? '') === $webHost && ($html['port'] ?? null) === ($web['port'] ?? null)
            && ! isset($html['user']) && ! isset($html['pass']) && preg_match('/[\x00-\x20\x7F"<>\\\\]/', $htmlUrl) !== 1) {
            return $htmlUrl;
        }

        return rtrim($webUrl, '/').'/apps/'.rawurlencode($slug);
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'app_id' => 'integer',
            'webhook_last_received_at' => 'datetime',
            'webhook_missed_at' => 'datetime',
        ];
    }
}
