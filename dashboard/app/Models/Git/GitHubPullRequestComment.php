<?php

declare(strict_types=1);

namespace App\Models\Git;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The single launchroom comment on a pull request (docs/06 §5), updated in
 * place. `rows` holds one entry per service, keyed by the row key.
 *
 * @property int $id
 * @property int $github_installation_id
 * @property int $github_repo_id
 * @property int $pr_number
 * @property int|null $comment_id
 * @property array<string, array{service: string, environment: string, state: string, url: string|null, logUrl: string, sha: string, updatedAt: string}> $rows
 */
#[Fillable(['team_id', 'github_installation_id', 'github_repo_id', 'pr_number', 'comment_id', 'rows'])]
#[Table(name: 'github_pull_request_comments')]
final class GitHubPullRequestComment extends Model
{
    use BelongsToTeam;

    /** @var array<string, mixed> */
    protected $attributes = [
        'rows' => '{}',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'github_repo_id' => 'integer',
            'pr_number' => 'integer',
            'comment_id' => 'integer',
            'rows' => 'array',
        ];
    }
}
