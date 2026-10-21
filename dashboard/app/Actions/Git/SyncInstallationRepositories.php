<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRepository;
use App\Services\GitHub\Dto\RepositoryInfo;
use Illuminate\Support\Facades\DB;

/**
 * Updates the repository cache of an installation: upserts `$repositories`,
 * deletes `$removedIds`, and with `$complete` deletes everything else.
 */
final class SyncInstallationRepositories
{
    /**
     * @param  list<RepositoryInfo>  $repositories
     * @param  list<int>  $removedIds
     */
    public function handle(GitHubInstallation $installation, array $repositories, array $removedIds = [], bool $complete = false): void
    {
        DB::transaction(function () use ($installation, $repositories, $removedIds, $complete): void {
            foreach ($repositories as $repository) {
                $values = [
                    'team_id' => $installation->team_id,
                    'full_name' => $repository->fullName,
                    'private' => $repository->private,
                    'archived' => $repository->archived,
                ];

                // Partial payloads (installation events) don't carry these.
                if ($repository->defaultBranch !== null) {
                    $values['default_branch'] = $repository->defaultBranch;
                }

                if ($repository->pushedAt !== null) {
                    $values['pushed_at'] = $repository->pushedAt;
                }

                GitHubRepository::query()->updateOrCreate(
                    ['github_installation_id' => $installation->id, 'github_repo_id' => $repository->id],
                    $values,
                );
            }

            if ($removedIds !== []) {
                $installation->repositories()->whereIn('github_repo_id', $removedIds)->delete();
            }

            if ($complete) {
                $installation->repositories()
                    ->whereNotIn('github_repo_id', array_map(static fn (RepositoryInfo $r): int => $r->id, $repositories))
                    ->delete();

                $installation->forceFill(['repositories_synced_at' => now()])->save();
            }
        });
    }
}
