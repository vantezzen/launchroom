<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Enums\Crypto\ProjectCiphertext;
use App\Models\Project;
use App\Models\ProjectKey;
use LogicException;
use SensitiveParameter;

/**
 * Per-project data-encryption keys (docs/09 R13, docs/06 §3.5). What is
 * encrypted under them, and with which associated data, is
 * {@see ProjectCiphertext}.
 *
 * Key versions (R13 rotation, `launchroom:keys:rotate-project`): the newest
 * DEK version encrypts. Every ciphertext records the version that made it:
 * versions > 1 are prefixed `k<version>.` (`k2.lr1:…`); unprefixed
 * ciphertexts are version 1, which is every value written before rotation
 * existed. Older versions stay until nothing uses them, so revisions and
 * snapshots written under them keep decrypting.
 *
 * Unwrapped keys are memoized for the lifetime of this object, which is bound
 * `scoped` (one request or one job), never serialized.
 */
final class ProjectKeyring
{
    private const string VERSIONED = '/^k([1-9][0-9]{0,8})\.(lr1:.*)$/s';

    /** @var array<string, SecretKey> "project id:version" => key */
    private array $keys = [];

    /** @var array<int, int> project id => newest version */
    private array $current = [];

    public function __construct(
        private readonly SealedColumns $sealed,
    ) {}

    public function initialize(Project $project): ProjectKey
    {
        return $this->addVersion($project, 1);
    }

    /**
     * Adds a new DEK version (wrapped by the current KEK); it encrypts from
     * now on. Callers re-encrypt existing values (rotate-project).
     */
    public function rotate(Project $project): ProjectKey
    {
        // FOR UPDATE can't be combined with MAX: lock the rows, then take the maximum.
        $versions = ProjectKey::query()->where('project_id', $project->id)->lockForUpdate()->pluck('version')->all();
        $version = max([0, ...array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $versions)]) + 1;

        return $this->addVersion($project, $version);
    }

    public static function versionOf(string $ciphertext): int
    {
        return preg_match(self::VERSIONED, $ciphertext, $m) === 1 ? (int) $m[1] : 1;
    }

    public function currentVersion(Project $project): int
    {
        if (! isset($this->current[$project->id])) {
            $version = ProjectKey::query()->where('project_id', $project->id)->max('version');

            if (! is_numeric($version)) {
                throw new LogicException("Project {$project->public_id} has no data key.");
            }

            $this->current[$project->id] = (int) $version;
        }

        return $this->current[$project->id];
    }

    public function encrypt(ProjectCiphertext $kind, Project $project, string $rowPublicId, #[SensitiveParameter] string $plaintext, int $rowVersion = 0): string
    {
        $version = $this->currentVersion($project);
        $ciphertext = $this->dataKey($project, $version)->encrypt($plaintext, $kind->associatedData($project, $rowPublicId, $rowVersion));

        return $version === 1 ? $ciphertext : "k{$version}.{$ciphertext}";
    }

    public function decrypt(ProjectCiphertext $kind, Project $project, string $rowPublicId, string $ciphertext, int $rowVersion = 0): string
    {
        $version = 1;

        if (preg_match(self::VERSIONED, $ciphertext, $m) === 1) {
            $version = (int) $m[1];
            $ciphertext = $m[2];
        }

        return $this->dataKey($project, $version)->decrypt($ciphertext, $kind->associatedData($project, $rowPublicId, $rowVersion));
    }

    public function encryptVariable(Project $project, string $variablePublicId, int $version, #[SensitiveParameter] string $plaintext): string
    {
        return $this->encrypt(ProjectCiphertext::Variable, $project, $variablePublicId, $plaintext, $version);
    }

    public function decryptVariable(Project $project, string $variablePublicId, int $version, string $ciphertext): string
    {
        return $this->decrypt(ProjectCiphertext::Variable, $project, $variablePublicId, $ciphertext, $version);
    }

    /**
     * @param  array<string, string>  $env
     */
    public function encryptSnapshot(Project $project, string $deploymentPublicId, #[SensitiveParameter] array $env): string
    {
        return $this->encrypt(
            ProjectCiphertext::Snapshot,
            $project,
            $deploymentPublicId,
            json_encode((object) $env, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, string>
     */
    public function decryptSnapshot(Project $project, string $deploymentPublicId, string $ciphertext): array
    {
        $decoded = json_decode($this->decrypt(ProjectCiphertext::Snapshot, $project, $deploymentPublicId, $ciphertext), true, 4, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new LogicException('A deployment env snapshot must decode to an object.');
        }

        $env = [];

        foreach ($decoded as $key => $value) {
            $env[(string) $key] = is_string($value) ? $value : '';
        }

        return $env;
    }

    /**
     * A keyed MAC under the current project DEK, for hashing secret material
     * into stored fingerprints (build keys) without making them guessable.
     * Rotating the DEK changes them (one rebuild).
     */
    public function mac(Project $project, string $context, #[SensitiveParameter] string $data): string
    {
        return hash_hmac('sha256', $context."\0".$data, $this->dataKey($project, $this->currentVersion($project))->reveal());
    }

    private function dataKey(Project $project, int $version): SecretKey
    {
        $memo = $project->id.':'.$version;

        if (isset($this->keys[$memo])) {
            return $this->keys[$memo];
        }

        $key = ProjectKey::query()
            ->where('project_id', $project->id)
            ->where('version', $version)
            ->first();

        if ($key === null) {
            throw new LogicException("Project {$project->public_id} has no data key version {$version}.");
        }

        return $this->keys[$memo] = $this->sealed->dataKey($key->setRelation('project', $project));
    }

    private function addVersion(Project $project, int $version): ProjectKey
    {
        $key = new ProjectKey(['project_id' => $project->id, 'version' => $version]);
        $key->setRelation('project', $project);
        $this->keys[$project->id.':'.$version] = $this->sealed->newKey($key);
        $key->save();
        $this->current[$project->id] = $version;

        return $key;
    }
}
