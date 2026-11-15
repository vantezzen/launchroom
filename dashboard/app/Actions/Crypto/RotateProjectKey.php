<?php

declare(strict_types=1);

namespace App\Actions\Crypto;

use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\VariableSpec;
use App\Enums\Crypto\ProjectCiphertext;
use App\Models\EnvironmentRevision;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Services\Crypto\ProjectKeyring;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Project DEK rotation (docs/09 R13, `launchroom:keys:rotate-project`): adds
 * a new DEK version and re-encrypts, in one transaction under the project
 * lock, every value under the project DEK: each {@see ProjectCiphertext}
 * kind and the variable ciphertexts kept in environment revisions
 * (re-encryption keeps their content, so the immutable revision is
 * rewritten in place). Associated data is unchanged. Older DEK versions stay
 * unless $prune, which deletes them after checking that no ciphertext still
 * uses them.
 */
final readonly class RotateProjectKey
{
    private const array COUNTS = [
        'Variable' => 'variables',
        'Snapshot' => 'snapshots',
        'Query' => 'queries',
        'Inspections' => 'inspections',
    ];

    public function __construct(
        private ProjectKeyring $keyring,
    ) {}

    /**
     * @return array{version: int, variables: int, snapshots: int, revisions: int, queries: int, inspections: int, pruned: int}
     */
    public function handle(Project $project, bool $prune = false): array
    {
        return DB::transaction(function () use ($project, $prune): array {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $version = $this->keyring->rotate($project)->version;
            $counts = ['version' => $version, 'variables' => 0, 'snapshots' => 0, 'revisions' => 0, 'queries' => 0, 'inspections' => 0, 'pruned' => 0];

            foreach (ProjectCiphertext::cases() as $kind) {
                foreach ($kind->rows($project)->orderBy('id')->lockForUpdate()->get() as $row) {
                    $this->reencryptRow($project, $kind, $row);
                    $counts[self::COUNTS[$kind->name]]++;
                }
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

    private function reencryptRow(Project $project, ProjectCiphertext $kind, Model $row): void
    {
        $column = $kind->column();
        $rowId = $row->getAttribute('public_id');
        $ciphertext = $row->getAttribute($column);

        if (! is_string($rowId) || ! is_string($ciphertext)) {
            return;
        }

        $plaintext = $this->keyring->decrypt($kind, $project, $rowId, $ciphertext, $kind->rowVersion($row));
        $row->forceFill([$column => $this->keyring->encrypt($kind, $project, $rowId, $plaintext, $kind->rowVersion($row))])->saveQuietly();
    }

    private function reencryptSpec(Project $project, EnvironmentSpec $spec): EnvironmentSpec
    {
        foreach ($this->specVariables($spec) as $variable) {
            $plaintext = $this->keyring->decryptVariable($project, $variable->id, $variable->version, $variable->ciphertext);
            $variable->ciphertext = $this->keyring->encryptVariable($project, $variable->id, $variable->version, $plaintext);
        }

        return $spec;
    }

    /**
     * @return list<VariableSpec>
     */
    private function specVariables(EnvironmentSpec $spec): array
    {
        $variables = array_values($spec->sharedVariables);

        foreach ($spec->services as $service) {
            array_push($variables, ...array_values($service->variables), ...array_values($service->connectionVariables));
        }

        return $variables;
    }

    private function prune(Project $project, int $version): int
    {
        $ciphertexts = [];

        foreach (ProjectCiphertext::cases() as $kind) {
            array_push($ciphertexts, ...$kind->rows($project)->pluck($kind->column())->all());
        }

        $revisions = EnvironmentRevision::query()->whereHas('environment', static fn (Builder $query) => $query->where('project_id', $project->id))->get();

        foreach ($revisions as $revision) {
            foreach ($this->specVariables($revision->spec) as $variable) {
                $ciphertexts[] = $variable->ciphertext;
            }
        }

        foreach ($ciphertexts as $ciphertext) {
            if (is_string($ciphertext) && ProjectKeyring::versionOf($ciphertext) !== $version) {
                throw new RuntimeException('Some values still use an older key version; nothing was pruned.');
            }
        }

        $deleted = ProjectKey::query()->where('project_id', $project->id)->where('version', '<', $version)->delete();

        return is_int($deleted) ? $deleted : 0;
    }
}
