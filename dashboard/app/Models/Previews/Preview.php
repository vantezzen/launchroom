<?php

declare(strict_types=1);

namespace App\Models\Previews;

use App\Enums\Previews\PreviewProvider;
use App\Enums\Previews\PreviewStatus;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\Environment;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubInstallation;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One pull request's preview (docs/06 §5, docs/07 `previews`): outlives its
 * environment (which is created and destroyed with the PR) so the list can
 * show skipped, queued, awaiting-approval and closed previews too.
 *
 * `data` holds per stateful service (keyed by service public id) the data
 * preparation state: `{strategy, restoreRun?, sanitizeTask?, state, message?}`.
 *
 * @property int $id
 * @property string $public_id
 * @property int $project_id
 * @property int|null $environment_id
 * @property PreviewProvider $provider
 * @property int|null $github_installation_id
 * @property int|null $git_connection_id GitLab/Gitea previews (docs/19)
 * @property int $repository_id
 * @property string $repository_name
 * @property int $pr_number
 * @property string $title
 * @property string $author_login
 * @property string $head_sha
 * @property string $head_ref
 * @property string $base_ref
 * @property bool $is_fork
 * @property string|null $author_permission
 * @property list<string> $labels
 * @property PreviewStatus $status
 * @property string|null $status_reason
 * @property int|null $approved_by
 * @property CarbonInterface|null $approved_at
 * @property string|null $approved_sha the fork head commit the approval covers
 * @property array<string, mixed> $data
 * @property list<string>|null $changed_files
 * @property int|null $github_deployment_id
 * @property string|null $github_deployment_sha
 * @property CarbonInterface $last_activity_at
 * @property CarbonInterface|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Environment|null $environment
 * @property-read GitHubInstallation|null $installation
 * @property-read GitConnection|null $gitConnection
 * @property-read User|null $approver
 */
#[Fillable(['team_id', 'project_id', 'provider', 'github_installation_id', 'git_connection_id', 'repository_id', 'repository_name', 'pr_number', 'title', 'author_login', 'head_sha', 'head_ref', 'base_ref', 'is_fork', 'author_permission', 'labels', 'changed_files', 'last_activity_at'])]
final class Preview extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'labels' => '[]',
        'data' => '{}',
    ];

    public static function publicIdPrefix(): string
    {
        return 'prv';
    }

    /**
     * The only way a preview's status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(PreviewStatus $to, ?string $reason = null): void
    {
        if ($to !== $this->status) {
            $this->status->assertCanTransitionTo($to);
        }

        $this->status = $to;
        $this->status_reason = $reason === null ? null : mb_substr($reason, 0, 500);

        $this->forceFill(['closed_at' => $to->isOpen() ? null : now()])->save();
    }

    /**
     * Environment name and slug: `pr-<n>` (generated hostnames
     * `{service}-pr-{n}.{project}.…`, docs/05 §5).
     */
    public function slug(): string
    {
        return 'pr-'.$this->pr_number;
    }

    /**
     * The GitHub environment name (`preview/pr-<n>`).
     */
    public function githubEnvironment(): string
    {
        return 'preview/'.$this->slug();
    }

    public function expiresAt(int $expiryDays): CarbonInterface
    {
        return $this->last_activity_at->copy()->addDays($expiryDays);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return BelongsTo<GitHubInstallation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(GitHubInstallation::class, 'github_installation_id');
    }

    /**
     * @return BelongsTo<GitConnection, $this>
     */
    public function gitConnection(): BelongsTo
    {
        return $this->belongsTo(GitConnection::class, 'git_connection_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<PreviewShareLink, $this>
     */
    public function shareLinks(): HasMany
    {
        return $this->hasMany(PreviewShareLink::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PreviewProvider::class,
            'status' => PreviewStatus::class,
            'repository_id' => 'integer',
            'pr_number' => 'integer',
            'is_fork' => 'boolean',
            'labels' => 'array',
            'data' => 'array',
            'changed_files' => 'array',
            'approved_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'closed_at' => 'datetime',
            'github_deployment_id' => 'integer',
        ];
    }
}
