<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Models\Project;
use App\Models\ProjectKey;
use LogicException;
use SensitiveParameter;

/**
 * Per-project data-encryption keys (docs/09 R13, docs/06 §3.5).
 *
 * Associated data (domain-separated by a leading tag):
 * - variable values: `var|<team id>|<project id>|<variable public id>|<version>`
 * - deployment env snapshots: `env|<team id>|<project id>|<deployment public id>`
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
        private readonly Envelope $envelope,
    ) {}

    /**
     * Creates version 1 of a new project's DEK.
     */
    public function initialize(Project $project): ProjectKey
    {
        $dek = $this->envelope->generateDataKey();

        $key = ProjectKey::query()->create([
            'project_id' => $project->id,
            'version' => 1,
            'wrapped_dek' => $this->envelope->wrap($dek, $this->wrapContext($project)),
            'kek_id' => $this->envelope->kekId(),
        ]);

        $this->keys[$project->id.':1'] = $dek;
        $this->current[$project->id] = 1;

        return $key;
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
        $dek = $this->envelope->generateDataKey();

        $key = ProjectKey::query()->create([
            'project_id' => $project->id,
            'version' => $version,
            'wrapped_dek' => $this->envelope->wrap($dek, $this->wrapContext($project)),
            'kek_id' => $this->envelope->kekId(),
        ]);

        $this->keys[$project->id.':'.$version] = $dek;
        $this->current[$project->id] = $version;

        return $key;
    }

    /**
     * Re-wraps a stored DEK from one KEK to another (rotate-kek); the caller saves.
     */
    public function rewrap(Project $project, ProjectKey $key, Envelope $from, Envelope $to): void
    {
        $key->wrapped_dek = $from->rewrap($key->wrapped_dek, $this->wrapContext($project), $to);
        $key->kek_id = $to->kekId();
    }

    /**
     * The DEK version a ciphertext was made with.
     */
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

    public function encryptVariable(Project $project, string $variablePublicId, int $version, #[SensitiveParameter] string $plaintext): string
    {
        return $this->encrypt($project, $plaintext, $this->variableContext($project, $variablePublicId, $version));
    }

    public function decryptVariable(Project $project, string $variablePublicId, int $version, string $ciphertext): string
    {
        return $this->decrypt($project, $ciphertext, $this->variableContext($project, $variablePublicId, $version));
    }

    /**
     * @param  array<string, string>  $env
     */
    public function encryptSnapshot(Project $project, string $deploymentPublicId, #[SensitiveParameter] array $env): string
    {
        return $this->encrypt(
            $project,
            json_encode((object) $env, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $this->snapshotContext($project, $deploymentPublicId),
        );
    }

    /**
     * @return array<string, string>
     */
    public function decryptSnapshot(Project $project, string $deploymentPublicId, string $ciphertext): array
    {
        $decoded = json_decode($this->decrypt($project, $ciphertext, $this->snapshotContext($project, $deploymentPublicId)), true, 4, JSON_THROW_ON_ERROR);

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
     * A SQL console statement in the user's query history (docs/07
     * `database_queries`, AD `query|<team>|<project>|<qry_ id>`).
     */
    public function encryptQuery(Project $project, string $queryPublicId, #[SensitiveParameter] string $statement): string
    {
        return $this->encrypt($project, $statement, "query|{$project->team_id}|{$project->id}|{$queryPublicId}");
    }

    public function decryptQuery(Project $project, string $queryPublicId, string $ciphertext): string
    {
        return $this->decrypt($project, $ciphertext, "query|{$project->team_id}|{$project->id}|{$queryPublicId}");
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

    private function encrypt(Project $project, #[SensitiveParameter] string $plaintext, string $associatedData): string
    {
        $version = $this->currentVersion($project);
        $ciphertext = $this->dataKey($project, $version)->encrypt($plaintext, $associatedData);

        return $version === 1 ? $ciphertext : "k{$version}.{$ciphertext}";
    }

    private function decrypt(Project $project, string $ciphertext, string $associatedData): string
    {
        $version = 1;

        if (preg_match(self::VERSIONED, $ciphertext, $m) === 1) {
            $version = (int) $m[1];
            $ciphertext = $m[2];
        }

        return $this->dataKey($project, $version)->decrypt($ciphertext, $associatedData);
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

        return $this->keys[$memo] = $this->envelope->unwrap($key->wrapped_dek, $this->wrapContext($project));
    }

    private function wrapContext(Project $project): string
    {
        return "project:{$project->public_id}";
    }

    private function variableContext(Project $project, string $variablePublicId, int $version): string
    {
        return "var|{$project->team_id}|{$project->id}|{$variablePublicId}|{$version}";
    }

    private function snapshotContext(Project $project, string $deploymentPublicId): string
    {
        return "env|{$project->team_id}|{$project->id}|{$deploymentPublicId}";
    }
}
