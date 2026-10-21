<?php

declare(strict_types=1);

namespace App\Models\Git;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * GitHub objects created for one launchroom deployment, so status changes
 * update them instead of creating new ones.
 *
 * @property int $id
 * @property int $github_installation_id
 * @property int $github_repo_id
 * @property string $deployment_key
 * @property int|null $github_deployment_id
 * @property int|null $check_run_id
 */
#[Fillable(['team_id', 'github_installation_id', 'github_repo_id', 'deployment_key', 'github_deployment_id', 'check_run_id'])]
#[Table(name: 'github_deployment_reports')]
final class GitHubDeploymentReport extends Model
{
    use BelongsToTeam;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'github_repo_id' => 'integer',
            'github_deployment_id' => 'integer',
            'check_run_id' => 'integer',
        ];
    }
}
