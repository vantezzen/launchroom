<?php

declare(strict_types=1);

namespace App\Models\Git;

use App\Contracts\Git\SourceRepository;
use App\Data\Git\GitRepositoryData;
use App\Data\Git\RepositoryRef;
use App\Enums\Git\GitProviderKind;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A repository seen through a git connection (docs/19 §3): GitLab project,
 * Gitea repository, or the one repository of a plain git connection
 * (`external_id` 0). `external_id` is what webhooks and services match on.
 *
 * @property int $id
 * @property string $public_id
 * @property int $git_connection_id
 * @property int $external_id
 * @property string $full_name
 * @property string|null $default_branch
 * @property bool $private
 * @property bool $archived
 * @property CarbonImmutable|null $pushed_at
 * @property string|null $web_url
 * @property int|null $hook_id
 * @property string|null $hook_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read GitConnection $gitConnection
 */
#[Fillable([
    'team_id', 'git_connection_id', 'external_id', 'full_name', 'default_branch', 'private', 'archived', 'pushed_at', 'web_url',
])]
#[Table(name: 'git_repositories')]
final class GitRepository extends Model implements SourceRepository
{
    use BelongsToTeam, HasPublicId;

    public static function publicIdPrefix(): string
    {
        return 'gir';
    }

    /**
     * @return BelongsTo<GitConnection, $this>
     */
    public function gitConnection(): BelongsTo
    {
        return $this->belongsTo(GitConnection::class, 'git_connection_id');
    }

    public function repositoryRef(): RepositoryRef
    {
        $connection = $this->gitConnection;

        return new RepositoryRef(
            $connection->provider,
            $this->team_id,
            $connection->id,
            $this->external_id,
            $this->full_name,
            $connection->provider === GitProviderKind::Git ? $connection->base_url : null,
        );
    }

    public function repositoryPublicId(): string
    {
        return $this->public_id;
    }

    public function repositoryTeamId(): int
    {
        return $this->team_id;
    }

    public function repositoryFullName(): string
    {
        return $this->full_name;
    }

    public function repositoryDefaultBranch(): ?string
    {
        return $this->default_branch;
    }

    public function gitSource(string $rootDirectory = '', ?string $branch = null): array
    {
        $connection = $this->gitConnection;

        return [
            'type' => 'git',
            'provider' => $connection->provider->value,
            'repositoryId' => $this->external_id,
            'fullName' => $this->full_name,
            'installationId' => $connection->id,
            ...($rootDirectory === '' ? [] : ['rootDirectory' => $rootDirectory]),
            ...($connection->provider === GitProviderKind::Git ? ['cloneUrl' => $connection->base_url] : []),
            ...($branch === null ? [] : ['branch' => $branch]),
        ];
    }

    public function pickerData(): GitRepositoryData
    {
        return GitRepositoryData::fromSource($this);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'external_id' => 'integer',
            'private' => 'boolean',
            'archived' => 'boolean',
            'pushed_at' => 'datetime',
            'hook_id' => 'integer',
        ];
    }
}
