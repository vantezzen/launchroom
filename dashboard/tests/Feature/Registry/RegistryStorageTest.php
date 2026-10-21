<?php

declare(strict_types=1);

use App\Actions\Crypto\RotateKek;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\Registry\RegistryStorage;
use App\Models\Team;
use App\Models\User;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\SecretKey;
use App\Services\Registry\RegistryStorageSecrets;
use App\Services\Registry\ZotConfig;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Backups;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    config(['app.url' => 'https://dash.example.com']);
});

function registryInstanceOwner(): User
{
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true])->save();

    return $owner;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registryForm(array $overrides = []): array
{
    return [
        'endpoint' => 'https://fsn1.your-objectstorage.com',
        'bucket' => 'launchroom-registry',
        'region' => 'fsn1',
        'prefix' => 'zot',
        'accessKeyId' => 'AKIAREGISTRY00001',
        'secretAccessKey' => 'registry-secret-access-key',
        'forcePathStyle' => true,
        ...$overrides,
    ];
}

test('instance owners put the registry on S3; credentials are envelope-encrypted, hidden and never rendered', function (): void {
    $owner = registryInstanceOwner();

    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->put(route('registry-settings.update'), registryForm())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $storage = RegistryStorage::query()->sole();
    expect($storage->credentials)->toStartWith('lr1:')
        ->and(json_encode($storage->toArray(), JSON_THROW_ON_ERROR))->not->toContain('lr1:')
        ->and(resolve(RegistryStorageSecrets::class)->reveal($storage)['secretAccessKey'])->toBe('registry-secret-access-key');

    $page = $this->actingAs($owner)->get(route('registry-settings.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('settings/registry')
            ->where('settings.storage.bucket', 'launchroom-registry')
            ->where('settings.storage.prefix', 'zot')
            ->where('settings.applied', false));
    expect($page->getContent())->not->toContain('registry-secret-access-key')->not->toContain('AKIAREGISTRY00001');

    $audit = AuditEvent::query()->where('action', AuditAction::RegistryStorageConfigured->value)->sole();
    expect(json_encode($audit->meta, JSON_THROW_ON_ERROR))->not->toContain('registry-secret');
});

test('only instance owners see or change the registry storage', function (TeamRole $role): void {
    $team = Team::factory()->create();
    $member = memberOf($team, $role);

    $this->actingAs($member)->get(route('registry-settings.edit'))->assertForbidden();
    $this->actingAs($member)->withSession(passwordConfirmed())->put(route('registry-settings.update'), registryForm())->assertForbidden();
    $this->actingAs($member)->withSession(passwordConfirmed())->delete(route('registry-settings.destroy'))->assertForbidden();

    expect(RegistryStorage::query()->count())->toBe(0);
})->with(['team owner' => [TeamRole::Owner], 'admin' => [TeamRole::Admin], 'developer' => [TeamRole::Developer]]);

test('changing the registry storage needs a recent password', function (): void {
    $this->actingAs(registryInstanceOwner())
        ->put(route('registry-settings.update'), registryForm())
        ->assertRedirect(route('password.confirm'));

    expect(RegistryStorage::query()->count())->toBe(0);
});

test('endpoints follow the backup destination rules; the private-network override is audited', function (): void {
    $owner = registryInstanceOwner();

    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->put(route('registry-settings.update'), registryForm(['endpoint' => 'http://versitygw:7070']))
        ->assertSessionHasErrors('endpoint');
    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->put(route('registry-settings.update'), registryForm(['endpoint' => 'https://internal.example.com']))
        ->assertSessionHasErrors('endpoint');
    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->put(route('registry-settings.update'), registryForm(['endpoint' => 'http://zot:5000', 'allowPrivateNetwork' => true]))
        ->assertSessionHasErrors('endpoint');
    expect(RegistryStorage::query()->count())->toBe(0);

    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->put(route('registry-settings.update'), registryForm(['endpoint' => 'http://versitygw:7070', 'allowPrivateNetwork' => true]))
        ->assertSessionHasNoErrors();

    expect(RegistryStorage::query()->sole()->allow_private_network)->toBeTrue()
        ->and(AuditEvent::query()->where('action', AuditAction::RegistryStoragePrivateNetworkAllowed->value)->count())->toBe(1);
});

test('blank credentials keep the stored ones; new ones are required the first time', function (): void {
    $owner = registryInstanceOwner();

    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->put(route('registry-settings.update'), registryForm(['accessKeyId' => '', 'secretAccessKey' => '']))
        ->assertSessionHasErrors('accessKeyId');

    $this->actingAs($owner)->withSession(passwordConfirmed())->put(route('registry-settings.update'), registryForm())->assertSessionHasNoErrors();
    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->put(route('registry-settings.update'), registryForm(['bucket' => 'other-bucket', 'accessKeyId' => '', 'secretAccessKey' => '']))
        ->assertSessionHasNoErrors();

    $storage = RegistryStorage::query()->sole();
    expect($storage->bucket)->toBe('other-bucket')
        ->and(resolve(RegistryStorageSecrets::class)->reveal($storage)['accessKeyId'])->toBe('AKIAREGISTRY00001');
});

test('local storage renders the installer template', function (): void {
    // Mounted (dev) or copied (CI) next to the daemon installer.
    $template = config()->string('launchroom.install.scripts_path').'/zot-config.json';

    if (! is_file($template)) {
        $this->markTestSkipped('deploy/images/zot/config.json is not mounted.');
    }

    $expected = json_decode(str_replace('__LAUNCHROOM_URL__', 'https://dash.example.com', (string) file_get_contents($template)), true, 64, JSON_THROW_ON_ERROR);

    expect(resolve(ZotConfig::class)->render())->toBe($expected);
});

test('S3 storage renders Zot\'s s3 driver without dedupe; display copies are redacted', function (): void {
    $this->actingAs(registryInstanceOwner())->withSession(passwordConfirmed())
        ->put(route('registry-settings.update'), registryForm(['endpoint' => 'http://versitygw:7070', 'allowPrivateNetwork' => true, 'prefix' => '', 'region' => '']));

    $config = resolve(ZotConfig::class);
    $storage = RegistryStorage::query()->sole();
    $rendered = $config->render($storage);

    expect($rendered['storage']['dedupe'])->toBeFalse()
        ->and($rendered['storage']['storageDriver'])->toBe([
            'name' => 's3',
            'rootdirectory' => '/registry',
            'region' => 'us-east-1',
            'regionendpoint' => 'http://versitygw:7070',
            'bucket' => 'launchroom-registry',
            'forcepathstyle' => true,
            'secure' => false,
            'skipverify' => false,
            'accesskey' => 'AKIAREGISTRY00001',
            'secretkey' => 'registry-secret-access-key',
        ])
        ->and($rendered['extensions']['sync']['downloadDir'])->toBe('/var/lib/registry/.sync')
        ->and(resolve(ZotConfig::class)->render())->not->toHaveKey('extensions.sync.downloadDir')
        ->and($rendered['http']['auth']['bearer']['realm'])->toBe('https://dash.example.com/registry/token')
        ->and($config->redacted($storage))->not->toContain('registry-secret-access-key')->toContain(ZotConfig::REDACTED);
});

test('the config command prints a redacted config and writes the file once (0600), recording it as applied', function (): void {
    $owner = registryInstanceOwner();
    $this->actingAs($owner)->withSession(passwordConfirmed())->put(route('registry-settings.update'), registryForm());

    expect(Artisan::call('launchroom:registry:config'))->toBe(0)
        ->and(Artisan::output())->toContain('"bucket": "launchroom-registry"')->not->toContain('registry-secret-access-key');

    config(['launchroom.registry.zot_config_path' => null]);
    expect(Artisan::call('launchroom:registry:config', ['--write' => true]))->toBe(1);

    $path = tempnam(sys_get_temp_dir(), 'zot');
    config(['launchroom.registry.zot_config_path' => $path]);

    expect(Artisan::call('launchroom:registry:config', ['--write' => true]))->toBe(0)
        ->and(trim(Artisan::output()))->toBe('changed')
        ->and(json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR)['storage']['storageDriver']['secretkey'])->toBe('registry-secret-access-key')
        ->and(fileperms($path) & 0777)->toBe(0600);

    expect(Artisan::call('launchroom:registry:config', ['--write' => true]))->toBe(0)
        ->and(trim(Artisan::output()))->toBe('unchanged')
        ->and(InstanceSettings::current()->registry_config_written_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::RegistryConfigWritten->value)->count())->toBe(1);

    $this->actingAs($owner)->get(route('registry-settings.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('settings.applied', true));

    // Back to local storage: pending until written again.
    $this->actingAs($owner)->withSession(passwordConfirmed())->delete(route('registry-settings.destroy'))->assertSessionHasNoErrors();
    expect(RegistryStorage::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditAction::RegistryStorageRemoved->value)->count())->toBe(1);
    $this->actingAs($owner)->get(route('registry-settings.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('settings.applied', false)->where('settings.storage', null));

    Artisan::call('launchroom:registry:config', ['--write' => true]);
    expect(trim(Artisan::output()))->toBe('changed')
        ->and((string) file_get_contents($path))->not->toContain('registry-secret-access-key');

    unlink($path);
});

test('KEK rotation re-wraps the registry credentials', function (): void {
    $this->actingAs(registryInstanceOwner())->withSession(passwordConfirmed())->put(route('registry-settings.update'), registryForm());

    $current = resolve(Envelope::class);
    $next = new Envelope(SecretKey::generate());
    resolve(RotateKek::class)->handle($current, $next);
    app()->instance(Envelope::class, $next);
    app()->forgetInstance(RegistryStorageSecrets::class);

    $storage = RegistryStorage::query()->sole();
    expect($storage->kek_id)->toBe($next->kekId())
        ->and(resolve(RegistryStorageSecrets::class)->reveal($storage)['secretAccessKey'])->toBe('registry-secret-access-key');
});
