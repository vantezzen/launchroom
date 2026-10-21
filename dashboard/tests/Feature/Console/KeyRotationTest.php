<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Models\Backups\BackupDestination;
use App\Models\Deployment;
use App\Models\EnvironmentRevision;
use App\Models\ProjectKey;
use App\Models\Variable;
use App\Services\Backups\DestinationSecrets;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use App\Services\Crypto\KeyFile;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Variables\VariableStore;
use Tests\Support\Backups;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

/**
 * L7 (2026-10 security review): `launchroom:keys:rotate-project` and
 * `launchroom:keys:rotate-kek` (docs/09 R13), with values readable after
 * rotation and the key version recorded per ciphertext.
 */
beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
});

function freshKeyring(): ProjectKeyring
{
    app()->forgetScopedInstances();
    app()->forgetInstance(ProjectKeyring::class);

    return resolve(ProjectKeyring::class);
}

test('rotate-project re-encrypts variables, snapshots and revisions with a new key version', function (): void {
    $world = DeploymentUi::make(deploy: false);
    $secret = $world->variable('API_TOKEN', 'tok_live_rotate_me', VariableType::Secret);
    $world->deploy();
    $project = $world->project;
    $deployment = $world->latest($world->web);
    $oldValue = $secret->refresh()->value;
    $oldSnapshot = (string) $deployment->env_snapshot;

    expect(ProjectKeyring::versionOf($oldValue))->toBe(1);

    $this->artisan('launchroom:keys:rotate-project', ['project' => $project->public_id])->assertSuccessful();

    $keyring = freshKeyring();
    $secret->refresh();
    $deployment->refresh();
    $revision = EnvironmentRevision::query()->where('environment_id', $world->production->id)->latest('id')->firstOrFail();
    $revisionVariable = $revision->spec->services[$world->web->public_id]->variables[$secret->public_id];

    expect(ProjectKey::query()->where('project_id', $project->id)->pluck('version')->sort()->values()->all())->toBe([1, 2])
        ->and($secret->value)->toStartWith('k2.lr1:')
        ->and(resolve(VariableStore::class)->plaintext($project, $secret))->toBe('tok_live_rotate_me')
        ->and($deployment->env_snapshot)->toStartWith('k2.lr1:')
        ->and($keyring->decryptSnapshot($project, $deployment->public_id, (string) $deployment->env_snapshot)['API_TOKEN'])->toBe('tok_live_rotate_me')
        ->and($revisionVariable->ciphertext)->toStartWith('k2.lr1:')
        ->and($keyring->decryptVariable($project, $secret->public_id, $secret->version, $revisionVariable->ciphertext))->toBe('tok_live_rotate_me')
        // Values written before rotation (version 1, unprefixed) still decrypt.
        ->and($keyring->decryptVariable($project, $secret->public_id, $secret->version, $oldValue))->toBe('tok_live_rotate_me')
        ->and($keyring->decryptSnapshot($project, $deployment->public_id, $oldSnapshot)['API_TOKEN'])->toBe('tok_live_rotate_me');

    // New writes use the new version.
    $config = $world->variable('APP_NAME', 'shop');
    expect($config->value)->toStartWith('k2.lr1:');

    // Every row stays readable for the app (no spurious staged changes, deploys still resolve).
    $world->deploy();
    expect(Deployment::query()->where('team_id', $world->team->id)->count())->toBeGreaterThan(2);
});

test('rotate-project --prune drops the old versions once nothing uses them', function (): void {
    $world = DeploymentUi::make(deploy: false);
    $secret = $world->variable('API_TOKEN', 'tok_live_prune_me', VariableType::Secret);
    $world->deploy();
    $oldValue = $secret->refresh()->value;

    $this->artisan('launchroom:keys:rotate-project', ['project' => $world->project->public_id, '--prune' => true])->assertSuccessful();

    $keyring = freshKeyring();
    expect(ProjectKey::query()->where('project_id', $world->project->id)->pluck('version')->all())->toBe([2])
        ->and(resolve(VariableStore::class)->plaintext($world->project, $secret->refresh()))->toBe('tok_live_prune_me')
        ->and(fn (): string => $keyring->decryptVariable($world->project, $secret->public_id, $secret->version, $oldValue))->toThrow(LogicException::class, 'no data key version 1');
});

test('rotate-project refuses unknown projects', function (): void {
    $this->artisan('launchroom:keys:rotate-project', ['project' => 'prj_doesnotexist'])->assertFailed();
});

test('rotate-kek re-wraps every data key, records the new kek_id and values decrypt with the new KEK only', function (): void {
    $directory = sys_get_temp_dir().'/lr-kek-'.bin2hex(random_bytes(4));
    $oldKek = KeyFile::create($directory.'/kek');
    app()->instance(Envelope::class, new Envelope($oldKek));
    freshKeyring();

    $world = DeploymentUi::make(deploy: false);
    $secret = $world->variable('API_TOKEN', 'tok_live_kek', VariableType::Secret);
    $world->deploy();
    $destination = Backups::destination($world->team, $world->member(TeamRole::Owner));

    $this->artisan('launchroom:keys:rotate-kek', ['--new-key' => $directory.'/kek.next'])->assertSuccessful();

    $newKek = KeyFile::read($directory.'/kek.next');
    expect(ProjectKey::query()->pluck('kek_id')->unique()->all())->toBe([$newKek->id()])
        ->and(BackupDestination::query()->pluck('kek_id')->unique()->all())->toBe([$newKek->id()]);

    // With the new KEK everything decrypts …
    app()->instance(Envelope::class, new Envelope($newKek));
    freshKeyring();
    expect(resolve(VariableStore::class)->plaintext($world->project, $secret->refresh()))->toBe('tok_live_kek')
        ->and(resolve(DestinationSecrets::class)->reveal($destination->refresh())->secretAccessKey)->toBe('super-secret-access-key-value');

    // … with the old one nothing does.
    app()->instance(Envelope::class, new Envelope($oldKek));
    freshKeyring();
    expect(fn () => resolve(VariableStore::class)->plaintext($world->project, Variable::query()->findOrFail($secret->id)))->toThrow(DecryptionFailed::class);

    // Rotating to the key already in use is refused.
    app()->instance(Envelope::class, new Envelope($newKek));
    $this->artisan('launchroom:keys:rotate-kek', ['--new-key' => $directory.'/kek.next'])->assertFailed();

    array_map(unlink(...), glob($directory.'/*') ?: []);
    rmdir($directory);
});
