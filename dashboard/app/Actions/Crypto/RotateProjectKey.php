<?php

declare(strict_types=1);

namespace App\Actions\Crypto;

use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\VariableSpec;
use App\Models\Deployment;
use App\Models\EnvironmentRevision;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Models\Variable;
use App\Services\Crypto\ProjectKeyring;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Project DEK rotation (docs/09 R13, `launchroom:keys:rotate-project`): adds
 * a new DEK version and re-encrypts, in one transaction under the project
 * lock, every value under the project DEK: variable rows, deployment env
 * snapshots and the variable ciphertexts kept in environment revisions
 * (re-encryption keeps their content, so the immutable revision is
 * rewritten in place). Associated data (team, project, row, version) is
 * unchanged. Older DEK versions stay unless $prune, which deletes them after
 * checking that no ciphertext still uses them.
 */
final readonly class RotateProjectKey
{
    public function __construct(
        private ProjectKeyring $keyring,
    ) {}

    /**
     * @return array{version: int, variables: int, snapshots: int, revisions: int, pruned: int}
     */
    public function handle(Project $project, bool $prune = false): array
    {
        return DB::transaction(function () use ($project, $prune): array {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $version = $this->keyring->rotate($project)->version;
            $counts = ['version' => $version, 'variables' => 0, 'snapshots' => 0, 'revisions' => 0, 'pruned' => 0];

            foreach (Variable::query()->where('project_id', $project->id)->orderBy('id')->lockForUpdate()->get() as $variable) {
                $plaintext = $this->keyring->decryptVariable($project, $variable->public_id, $variable->version, $variable->value);
                $variable->forceFill(['value' => $this->keyring->encryptVariable($project, $variable->public_id, $variable->version, $plaintext)])->saveQuietly();
                $counts['variables']++;
            }

            $deployments = Deployment::query()
                ->whereNotNull('env_snapshot')
                ->whereHas('environment', static fn (Builder $query) => $query->where('project_id', $project->id))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($deployments as $deployment) {
                $env = $this->keyring->decryptSnapshot($project, $deployment->public_id, (string) $deployment->env_snapshot);
                $deployment->forceFill(['env_snapshot' => $this->keyring->encryptSnapshot($project, $deployment->public_id, $env)])->saveQuietly();
                $counts['snapshots']++;
            }

            $revisions = EnvironmentRevision::query()
                ->whereHas('environment', static fn (Builder $query) => $query->where('project_id', $project->id))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($revisions as $revision) {
                $spec = $this->reencryptSpec($project, $revision->spec);
                $revision->setAttribute('spec', $spec);
                // Same content under the new key: written past the immutability guard on purpose.
                EnvironmentRevision::query()->whereKey($revision->id)->update(['spec' => $revision->getAttributes()['spec']]);
                $counts['revisions']++;
            }

            if ($prune) {
                $counts['pruned'] = $this->prune($project, $version);
            }

            return $counts;
        });
    }

    private function reencryptSpec(Project $project, EnvironmentSpec $spec): EnvironmentSpec
    {
        $reencrypt = function (VariableSpec $variable) use ($project): void {
            $plaintext = $this->keyring->decryptVariable($project, $variable->id, $variable->version, $variable->ciphertext);
            $variable->ciphertext = $this->keyring->encryptVariable($project, $variable->id, $variable->version, $plaintext);
        };

        array_walk($spec->sharedVariables, $reencrypt);

        foreach ($spec->services as $service) {
            array_walk($service->variables, $reencrypt);
            array_walk($service->connectionVariables, $reencrypt);
        }

        return $spec;
    }

    private function prune(Project $project, int $version): int
    {
        $stale = Variable::query()->where('project_id', $project->id)->pluck('value')
            ->merge(Deployment::query()->whereNotNull('env_snapshot')->whereHas('environment', static fn (Builder $query) => $query->where('project_id', $project->id))->pluck('env_snapshot'))
            ->filter(static fn (mixed $ciphertext): bool => is_string($ciphertext) && ProjectKeyring::versionOf($ciphertext) !== $version);

        if ($stale->isNotEmpty()) {
            throw new RuntimeException('Some values still use an older key version; nothing was pruned.');
        }

        $deleted = ProjectKey::query()->where('project_id', $project->id)->where('version', '<', $version)->delete();

        return is_int($deleted) ? $deleted : 0;
    }
}
