<?php

declare(strict_types=1);

namespace App\Models\Git;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The single launchroom comment on a GitLab merge request or Gitea pull
 * request (docs/19 §1), updated in place. Same rows as GitHub's.
 *
 * @property int $id
 * @property int $git_connection_id
 * @property int $external_id
 * @property int $pr_number
 * @property int|null $comment_id
 * @property array<string, array{service: string, environment: string, state: string, url: string|null, logUrl: string, sha: string, updatedAt: string}> $rows
 */
#[Fillable(['team_id', 'git_connection_id', 'external_id', 'pr_number', 'comment_id', 'rows'])]
#[Table(name: 'git_pull_request_comments')]
final class GitPullRequestComment extends Model
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
            'external_id' => 'integer',
            'pr_number' => 'integer',
            'comment_id' => 'integer',
            'rows' => 'array',
        ];
    }
}
