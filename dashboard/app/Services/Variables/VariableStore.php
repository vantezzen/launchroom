<?php

declare(strict_types=1);

namespace App\Services\Variables;

use App\Data\Specs\VariableSpec;
use App\Enums\Permission;
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
use App\Support\Variables\VariableReferences;
use Closure;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Writes variable rows: validates key/type/expressions, evaluates generator
 * functions once, records references, encrypts under the project DEK with
 * associated data bound to the row id and version (docs/06 §3.5). Shared by
 * the variable, service, connection and discard actions; callers own the
 * transaction and authorization.
 */
final readonly class VariableStore
{
    private const string KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,255}$/D';

    public function __construct(
        private ProjectKeyring $keyring,
    ) {}

    /**
     * @param  list<array{environment_id: int|null, all_previews: bool, branch_pattern: string|null}>  $targets
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

        $this->replaceTargets($variable, $targets);

        return $variable;
    }

    public function updateValue(Project $project, Variable $variable, #[SensitiveParameter] string $value, ?User $actor = null): void
    {
        $this->assertValid($variable->key, $value, $variable->type, $variable->exposure_override);

        $generated = GeneratedValues::contains($value);
        $generator = GeneratedValues::sole($value);
        $variable->loadMissing(['targets', 'service']);
        $targets = $variable->targets->map(static fn (VariableTarget $target): array => [
            'environment_id' => $target->environment_id,
            'all_previews' => $target->all_previews,
            'branch_pattern' => $target->branch_pattern,
        ])->values()->all();
        $value = $this->evaluate($value, $this->signingSecrets($project, $variable->service, array_values($targets), $variable->id));
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
        $this->assertValid($key, '', $type, $exposureOverride);
        $this->assertMayDowngradeSecret($variable, $type, $actor);

        $variable->forceFill([
            'key' => $key,
            'type' => $type,
            'exposure_override' => $exposureOverride,
            'updated_by' => $actor?->id,
        ])->save();
    }

    /**
     * @param  list<array{environment_id: int|null, all_previews: bool, branch_pattern: string|null}>  $targets
     */
    public function replaceTargets(Variable $variable, array $targets): void
    {
        $variable->targets()->delete();

        foreach ($targets as $target) {
            if (($target['environment_id'] !== null) === $target['all_previews']) {
                throw new InvalidArgumentException('A target is either one environment or all previews.');
            }

            VariableTarget::query()->create([
                'variable_id' => $variable->id,
                'environment_id' => $target['environment_id'],
                'all_previews' => $target['all_previews'],
                'branch_pattern' => $target['all_previews'] ? $target['branch_pattern'] : null,
            ]);
        }
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
     * Turning a secret into config makes its value readable, so it needs the
     * same permission as revealing it. Enforced here so every path (single
     * edit, raw .env edit, import) is covered; without an actor it fails
     * closed (docs/09 R12).
     */
    private function assertMayDowngradeSecret(Variable $variable, VariableType $type, ?User $actor): void
    {
        if ($variable->type !== VariableType::Secret || $type !== VariableType::Config) {
            return;
        }

        if (! $actor instanceof User || ! $actor->hasTeamPermission($variable->team_id, Permission::RevealSecrets)) {
            throw DomainRuleViolation::on('type', 'Changing a secret to config requires permission to reveal secrets.');
        }
    }

    private function assertValid(string $key, string $value, VariableType $type, ?VariableExposure $exposureOverride): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw DomainRuleViolation::on('key', 'Use letters, digits and underscores, not starting with a digit.');
        }

        if (str_contains($value, "\0")) {
            throw DomainRuleViolation::on('value', 'Values cannot contain NUL bytes.');
        }

        if (strlen($value) > 128 * 1024) {
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
     * @param  list<array{environment_id: int|null, all_previews: bool, branch_pattern: string|null}>  $targets
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
                $covers = array_all($targets, static fn (array $target): bool => $row->targets->contains(
                    static fn (VariableTarget $own): bool => $target['environment_id'] !== null
                        ? $own->environment_id === $target['environment_id']
                        : $own->all_previews && $target['all_previews'] && $own->branch_pattern === $target['branch_pattern'],
                ));

                if ($covers) {
                    return $this->plaintext($project, $row);
                }
            }

            return null;
        };
    }
}
