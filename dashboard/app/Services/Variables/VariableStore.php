<?php

declare(strict_types=1);

namespace App\Services\Variables;

use App\Data\Specs\VariableSpec;
use App\Data\Variables\VariableTargetRow;
use App\Enums\VariableExposure;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\Connection;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Models\Variable;
use App\Models\VariableTarget;
use App\Services\Crypto\ProjectKeyring;
use App\Support\PublicId;
use App\Support\Variables\GeneratedValues;
use App\Support\Variables\VariableKey;
use App\Support\Variables\VariableReferences;
use Closure;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Writes variable rows: validates key/type/expressions, evaluates generator
 * functions once, records references, encrypts under the project DEK with
 * associated data bound to the row id and version (docs/06 §3.5). Callers
 * own authorization.
 *
 * New content is written inside {@see write()}, which runs the save-time
 * checks (SecretExposureGuard: no browser-inlined variable embeds a secret,
 * production secrets differ from preview) for the keys it touched before the
 * transaction can commit, so every writer gets them. Rows that reproduce an
 * existing row ({@see copy()}, {@see restore()}) may be written outside it:
 * the state they recreate existed already and stays blocked at deploy time.
 */
#[Scoped]
final class VariableStore
{
    private ?Project $writing = null;

    /** @var array<string, true> */
    private array $touched = [];

    public function __construct(
        private readonly ProjectKeyring $keyring,
        private readonly SecretExposureGuard $exposure,
    ) {}

    /**
     * Runs $writes in a transaction and checks the keys they touched before
     * it commits; a refusal (DomainRuleViolation on $field) rolls the writes
     * back. Nested calls join the outermost one.
     *
     * @template T
     *
     * @param  Closure(): T  $writes
     * @return T
     */
    public function write(Project $project, Closure $writes, string $field = 'value'): mixed
    {
        if ($this->writing instanceof Project) {
            if ($this->writing->id !== $project->id) {
                throw new LogicException('Variable writes of two projects can\'t be nested.');
            }

            return $writes();
        }

        $this->writing = $project;
        $this->touched = [];

        try {
            return DB::transaction(function () use ($project, $writes, $field): mixed {
                $result = $writes();
                $this->exposure->assertNoPublicSecrets($project, array_keys($this->touched), $field);

                return $result;
            });
        } finally {
            $this->writing = null;
            $this->touched = [];
        }
    }

    /**
     * Marks $key as changed by a write outside this store (a shared
     * variable's opt-in), so the surrounding write() checks it.
     */
    public function touch(string $key): void
    {
        $this->record($key);
    }

    /**
     * @param  list<VariableTargetRow>  $targets
     */
    public function create(
        Project $project,
        ?Service $service,
        string $key,
        #[SensitiveParameter] string $value,
        VariableType $type,
        array $targets,
        ?VariableExposure $exposureOverride = null,
        ?Connection $connection = null,
        ?User $actor = null,
    ): Variable {
        $this->record($key);

        return $this->insert($project, $service, $key, $value, $type, $targets, $exposureOverride, $connection, $actor);
    }

    /**
     * A new row that reproduces an existing one for other targets (restoring
     * into a copy of a service, cloning an environment's intent). Checked
     * only when it runs inside a write().
     *
     * @param  list<VariableTargetRow>  $targets
     */
    public function copy(
        Project $project,
        ?Service $service,
        string $key,
        #[SensitiveParameter] string $value,
        VariableType $type,
        array $targets,
        ?VariableExposure $exposureOverride = null,
        ?User $actor = null,
    ): Variable {
        if ($this->writing instanceof Project) {
            $this->record($key);
        }

        return $this->insert($project, $service, $key, $value, $type, $targets, $exposureOverride, actor: $actor);
    }

    /**
     * @param  list<VariableTargetRow>  $targets
     */
    private function insert(
        Project $project,
        ?Service $service,
        string $key,
        #[SensitiveParameter] string $value,
        VariableType $type,
        array $targets,
        ?VariableExposure $exposureOverride = null,
        ?Connection $connection = null,
        ?User $actor = null,
    ): Variable {
        $this->assertValid($key, $value, $type, $exposureOverride);

        $generated = GeneratedValues::contains($value);
        $generator = GeneratedValues::sole($value);
        $value = $this->evaluate($value, $this->signingSecrets($project, $service, $targets));
        $publicId = PublicId::generate(Variable::publicIdPrefix());

        $variable = new Variable;
        $variable->forceFill([
            'public_id' => $publicId,
            'team_id' => $project->team_id,
            'project_id' => $project->id,
            'service_id' => $service?->id,
            'connection_id' => $connection?->id,
            'key' => $key,
            'value' => $this->keyring->encryptVariable($project, $publicId, 1, $value),
            'version' => 1,
            'type' => $type,
            'exposure_override' => $exposureOverride,
            'generated' => $generated,
            'generator' => $generator,
            'references' => VariableReferences::names($value),
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ])->save();

        $this->writeTargets($variable, $targets);

        return $variable;
    }

    public function updateValue(Project $project, Variable $variable, #[SensitiveParameter] string $value, ?User $actor = null): void
    {
        $this->record($variable->key);
        $this->assertValid($variable->key, $value, $variable->type, $variable->exposure_override);

        $generated = GeneratedValues::contains($value);
        $generator = GeneratedValues::sole($value);
        $variable->loadMissing(['targets', 'service']);
        $targets = array_values($variable->targets->map(VariableTargetRow::of(...))->all());
        $value = $this->evaluate($value, $this->signingSecrets($project, $variable->service, $targets, $variable->id));
        $version = $variable->version + 1;

        $variable->forceFill([
            'value' => $this->keyring->encryptVariable($project, $variable->public_id, $version, $value),
            'version' => $version,
            'generated' => $generated,
            'generator' => $generator,
            'references' => VariableReferences::names($value),
            'updated_by' => $actor?->id,
        ])->save();
    }

    /**
     * Rewrites key/type/exposure (the value stays; its ciphertext is bound to
     * id and version, not to the key).
     */
    public function updateMeta(Variable $variable, string $key, VariableType $type, ?VariableExposure $exposureOverride, ?User $actor = null): void
    {
        $this->record($variable->key);
        $this->record($key);
        $this->assertValid($key, '', $type, $exposureOverride);

        if (! Gate::forUser($actor)->allows('retype', [$variable, $type])) {
            throw DomainRuleViolation::on('type', 'Changing a secret to config requires permission to reveal secrets.');
        }

        $variable->forceFill([
            'key' => $key,
            'type' => $type,
            'exposure_override' => $exposureOverride,
            'updated_by' => $actor?->id,
        ])->save();
    }

    /**
     * @param  list<VariableTargetRow>  $targets
     */
    public function replaceTargets(Variable $variable, array $targets): void
    {
        $this->record($variable->key);
        $this->writeTargets($variable, $targets);
    }

    /**
     * Puts a row back exactly as a revision recorded it (Discard): same id,
     * version and ciphertext, so the associated data still matches.
     */
    public function restore(Variable $variable, VariableSpec $spec): void
    {
        $variable->forceFill([
            'key' => $spec->key,
            'type' => $spec->type,
            'version' => $spec->version,
            'value' => $spec->ciphertext,
            'generated' => $spec->generated,
            'references' => $spec->references,
            'exposure_override' => $spec->exposure === VariableExposure::classify($spec->key) ? null : $spec->exposure,
        ])->save();
    }

    /**
     * Puts back what a revision recorded as a new version (Revert to
     * revision N): the recorded value re-encrypted under the next version, so
     * every version number stays bound to one value.
     */
    public function restoreAsNewVersion(Project $project, Variable $variable, VariableSpec $spec): void
    {
        $plaintext = $this->keyring->decryptVariable($project, $spec->id, $spec->version, $spec->ciphertext);
        $version = max($variable->exists ? $variable->version : 0, $spec->version) + 1;

        $variable->forceFill([
            'key' => $spec->key,
            'type' => $spec->type,
            'version' => $version,
            'value' => $this->keyring->encryptVariable($project, $spec->id, $version, $plaintext),
            'generated' => $spec->generated,
            'references' => $spec->references,
            'exposure_override' => $spec->exposure === VariableExposure::classify($spec->key) ? null : $spec->exposure,
        ])->save();
    }

    public function plaintext(Project $project, Variable $variable): string
    {
        return $this->keyring->decryptVariable($project, $variable->public_id, $variable->version, $variable->value);
    }

    /**
     * @param  list<VariableTargetRow>  $targets
     */
    private function writeTargets(Variable $variable, array $targets): void
    {
        $variable->targets()->delete();

        foreach ($targets as $target) {
            VariableTarget::query()->create([
                'variable_id' => $variable->id,
                'environment_id' => $target->environmentId,
                'all_previews' => $target->allPreviews,
                'branch_pattern' => $target->branchPattern,
            ]);
        }
    }

    private function record(string $key): void
    {
        if (! $this->writing instanceof Project) {
            throw new LogicException('Variable writes run inside VariableStore::write(), so the save-time checks see them.');
        }

        $this->touched[$key] = true;
    }

    private function assertValid(string $key, string $value, VariableType $type, ?VariableExposure $exposureOverride): void
    {
        if (! VariableKey::isValid($key)) {
            throw DomainRuleViolation::on('key', 'Use letters, digits and underscores, not starting with a digit.');
        }

        if (str_contains($value, "\0")) {
            throw DomainRuleViolation::on('value', 'Values cannot contain NUL bytes.');
        }

        if (strlen($value) > VariableKey::MAX_VALUE_BYTES) {
            throw DomainRuleViolation::on('value', 'Values are limited to 128 KiB.');
        }

        // Browser-inlined values are visible to anyone (docs/06 §3.3).
        if ($type === VariableType::Secret && VariableExposure::isBrowserInlined($key)) {
            throw DomainRuleViolation::on('type', "{$key} is baked into the browser bundle at build time and visible to anyone, so it can't be a secret.");
        }

        if ($exposureOverride === VariableExposure::Runtime && VariableExposure::isBrowserInlined($key)) {
            throw DomainRuleViolation::on('exposureOverride', "{$key} is read by the framework at build time; it can't be runtime-only.");
        }

        // A JWT is regenerated per environment from its generator, so it is the whole value.
        if (preg_match('/\$\{\{\s*jwt\(/', $value) === 1 && GeneratedValues::signingKey(GeneratedValues::sole($value)) === null) {
            throw DomainRuleViolation::on('value', 'A jwt() expression must be the whole value, like ${{ jwt("anon", JWT_SECRET) }}.');
        }

        $invalid = VariableReferences::invalid($value);

        if ($invalid !== []) {
            throw DomainRuleViolation::on('value', 'Not a valid reference: '.implode(', ', $invalid).'. Use ${{service.KEY}}, ${{shared.KEY}}, ${{KEY}}, ${{ secret(32) }}, ${{ uuid() }} or ${{ jwt("anon", JWT_SECRET) }}.');
        }
    }

    /**
     * @param  (Closure(string): ?string)|null  $secretOf
     */
    private function evaluate(#[SensitiveParameter] string $value, ?Closure $secretOf = null): string
    {
        try {
            return GeneratedValues::evaluate($value, $secretOf);
        } catch (InvalidArgumentException $e) {
            throw DomainRuleViolation::on('value', $e->getMessage());
        }
    }

    /**
     * Looks up what `jwt("role", KEY)` is signed with: the plaintext of the
     * one row of KEY of the same service (or shared, without a service) that
     * applies to every target of the row being written, so the token is
     * signed with the secret its environment uses. Null when there is no
     * such row (the caller reports it).
     *
     * @param  list<VariableTargetRow>  $targets
     * @return Closure(string): ?string
     */
    private function signingSecrets(Project $project, ?Service $service, array $targets, ?int $except = null): Closure
    {
        return function (string $key) use ($project, $service, $targets, $except): ?string {
            if ($targets === []) {
                return null;
            }

            $query = Variable::query()
                ->where('project_id', $project->id)
                ->where('service_id', $service?->id)
                ->whereNull('connection_id')
                ->where('key', $key)
                ->with('targets');

            if ($except !== null) {
                $query->whereKeyNot($except);
            }

            $rows = $query->get();

            foreach ($rows as $row) {
                $covers = array_all($targets, static fn (VariableTargetRow $target): bool => $row->targets->contains(
                    static fn (VariableTarget $own): bool => $target->sameAs(VariableTargetRow::of($own)),
                ));

                if ($covers) {
                    return $this->plaintext($project, $row);
                }
            }

            return null;
        };
    }
}
