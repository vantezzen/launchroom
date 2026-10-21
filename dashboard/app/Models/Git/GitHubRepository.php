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
 * Cached repository an installation can access (docs/07 `repositories`).
 * Refreshed on installation events and on demand; `github_repo_id` is what
 * webhooks and services match on.
 *
 * @property int $id
 * @property string $public_id
 * @property int $github_installation_id
 * @property int $github_repo_id
 * @property string $full_name
 * @property string|null $default_branch
 * @property bool $private
 * @property bool $archived
 * @property CarbonImmutable|null $pushed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read GitHubInstallation $installation
 */
#[Fillable([
    'team_id', 'github_installation_id', 'github_repo_id', 'full_name', 'default_branch', 'private', 'archived', 'pushed_at',
])]
#[Table(name: 'github_repositories')]
final class GitHubRepository extends Model implements SourceRepository
{
    use BelongsToTeam, HasPublicId;

    public static function publicIdPrefix(): string
    {
        return 'ghr';
    }

    /**
     * @return BelongsTo<GitHubInstallation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(GitHubInstallation::class, 'github_installation_id');
    }

    public function repositoryRef(): RepositoryRef
    {
        return new RepositoryRef(GitProviderKind::GitHub, $this->team_id, $this->github_installation_id, $this->github_repo_id, $this->full_name);
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
        return [
            'type' => 'git',
            'repositoryId' => $this->github_repo_id,
            'fullName' => $this->full_name,
            'installationId' => $this->github_installation_id,
            ...($rootDirectory === '' ? [] : ['rootDirectory' => $rootDirectory]),
            ...($branch === null ? [] : ['branch' => $branch]),
        ];
    }

    public function pickerData(): GitRepositoryData
    {
        return GitRepositoryData::fromRepository($this);
    }

    /**
     * @return array{0: string, 1: string} owner, name
     */
    public function ownerAndName(): array
    {
        [$owner, $name] = explode('/', $this->full_name, 2) + [1 => ''];

        return [$owner, $name];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'github_repo_id' => 'integer',
            'private' => 'boolean',
            'archived' => 'boolean',
            'pushed_at' => 'datetime',
        ];
    }
}
