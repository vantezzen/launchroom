<?php

declare(strict_types=1);

namespace App\Models\Git;

use App\Contracts\Git\GitAccount;
use App\Enums\Git\GitProviderKind;
use App\Enums\Git\RepositorySelection;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An installation of a team's GitHub App on a user or organization account.
 * Only ever created from data GitHub confirmed: a signed webhook or
 * `GET /app/installations/{id}` with the App JWT (docs/09 R18).
 *
 * @property int $id
 * @property string $public_id
 * @property int $github_app_id
 * @property int $installation_id
 * @property int $account_id
 * @property string $account_login
 * @property string $account_type
 * @property RepositorySelection $repository_selection
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $removed_at
 * @property CarbonImmutable|null $repositories_synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read GitHubApp $app
 */
#[Fillable([
    'team_id', 'github_app_id', 'installation_id', 'account_id', 'account_login', 'account_type',
    'repository_selection', 'suspended_at', 'removed_at', 'repositories_synced_at',
])]
#[Table(name: 'github_installations')]
final class GitHubInstallation extends Model implements GitAccount
{
    use BelongsToTeam, HasPublicId;

    public static function publicIdPrefix(): string
    {
        return 'ghi';
    }

    /**
     * @return BelongsTo<GitHubApp, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(GitHubApp::class, 'github_app_id');
    }

    /**
     * @return HasMany<GitHubRepository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(GitHubRepository::class, 'github_installation_id');
    }

    public function isActive(): bool
    {
        return $this->removed_at === null && $this->suspended_at === null;
    }

    public function gitProvider(): GitProviderKind
    {
        return GitProviderKind::GitHub;
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
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('removed_at')->whereNull('suspended_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
            'account_id' => 'integer',
            'repository_selection' => RepositorySelection::class,
            'suspended_at' => 'datetime',
            'removed_at' => 'datetime',
            'repositories_synced_at' => 'datetime',
        ];
    }
}
