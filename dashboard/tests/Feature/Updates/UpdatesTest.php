<?php

declare(strict_types=1);

use App\Actions\Updates\RunAutomaticUpdate;
use App\Contracts\DnsResolver;
use App\Data\Updates\ReleaseCheckData;
use App\Enums\AuditAction;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\ServerRole;
use App\Enums\TeamRole;
use App\Enums\Updates\ControlPlaneUpdateStatus;
use App\Enums\Updates\FleetUpdateStatus;
use App\Enums\Updates\FleetUpdateStepStatus;
use App\Models\AuditEvent;
use App\Models\Backups\BackupRun;
use App\Models\DaemonTask;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\Updates\ControlPlaneUpdate;
use App\Models\Updates\FleetUpdate;
use App\Models\User;
use App\Services\Updates\AutoUpdateWindow;
use App\Services\Updates\ReleaseChecker;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Backups;
use Tests\Support\FakeDaemon;
use Tests\Support\ProtocolSchemas;
use Tests\TestCase;

const RELEASES_URL = 'https://api.github.com/repos/vantezzen/launchroom/releases';

function daemonImage(string $char = 'a'): string
{
    return 'ghcr.io/vantezzen/launchroomd@sha256:'.str_repeat($char, 64);
}

/**
 * A GitHub release as the API returns it, with an images.env asset.
 *
 * @return array<string, mixed>
 */
function release(string $tag, array $overrides = []): array
{
    return [
        'tag_name' => $tag,
        'name' => "launchroom {$tag}",
        'body' => "## Changes\n- <script>alert(1)</script> is just text",
        'html_url' => "https://github.com/vantezzen/launchroom/releases/tag/{$tag}",
        'published_at' => '2026-10-18T10:00:00Z',
        'draft' => false,
        'prerelease' => false,
        'assets' => [
            ['name' => 'launchroom-deploy.tar.gz', 'url' => RELEASES_URL.'/assets/1'],
            ['name' => 'images.env', 'url' => RELEASES_URL.'/assets/'.crc32($tag)],
        ],
        ...$overrides,
    ];
}

/**
 * Where GitHub's API redirects an asset download: its storage host, with a
 * signed query string that must be sent as it is.
 */
function storageUrl(string $tag): string
{
    return 'https://release-assets.githubusercontent.com/github-production-release-asset/1/'.crc32($tag).'?sp=r&sv=2018-11-09&se=2026-10-21T17%3A16%3A58Z&sig=c2lnbmVk&jwt=e30';
}

/**
 * Fakes GitHub: the releases list, each release by tag, and every images.env
 * asset (one redirect to the storage host, like the real API). The storage
 * host answers 403 unless the signed query string arrives intact.
 *
 * @param  list<array<string, mixed>>  $releases
 */
function fakeGitHub(array $releases, array $first = []): void
{
    githubDns();

    // Earlier entries win: $first overrides the defaults below.
    $fakes = [...$first, RELEASES_URL.'?*' => Http::response($releases)];

    foreach ($releases as $release) {
        $fakes[RELEASES_URL.'/tags/'.$release['tag_name']] ??= Http::response($release);
        $fakes[RELEASES_URL.'/assets/'.crc32((string) $release['tag_name'])] ??= Http::response('', 302, ['Location' => storageUrl((string) $release['tag_name'])]);
        $fakes[storageUrl((string) $release['tag_name'])] ??= Http::response("# pins\nLR_DASHBOARD_IMAGE=ghcr.io/vantezzen/launchroom-dashboard@sha256:".str_repeat('d', 64)."\nLR_DAEMON_IMAGE=".daemonImage('b')."\nLR_POSTGRES_IMAGE=postgres:18\n");
    }

    $fakes['https://release-assets.githubusercontent.com/*'] = Http::response('<Error>AuthenticationFailed</Error>', 403);
    $fakes[RELEASES_URL.'/tags/*'] = Http::response(['message' => 'Not Found'], 404);

    Http::fake($fakes);
}

function githubDns(): void
{
    app()->instance(DnsResolver::class, new class implements DnsResolver
    {
        public function resolve(string $hostname): array
        {
            return in_array($hostname, ['api.github.com', 'release-assets.githubusercontent.com', 'evil.example.com', 's3.example.com'], true) ? ['140.82.121.6'] : [];
        }
    });
}

function instanceOwner(Team $team): User
{
    $owner = memberOf($team, TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true])->save();

    return $owner;
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'inertia.ssr.enabled' => false,
        'launchroom.version' => '0.1.0',
        'launchroom.updates.channel' => 'stable',
        'launchroom.daemon.image' => daemonImage(),
    ]);
    $this->team = Team::factory()->create();
    $this->owner = instanceOwner($this->team);
    $this->seed = random_bytes(32);
    $this->cp = Server::factory()->withSigningSeed($this->seed)->online()->create(['team_id' => $this->team->id, 'is_control_plane' => true, 'name' => 'main']);
    $this->cpDaemon = new FakeDaemon($this->seed)->as($this->cp->public_id);
});

/**
 * Leases the server's self_update task and completes it like launchroomd.
 *
 * @param  array<string, mixed>  $extra
 */
function finishSelfUpdate(TestCase $test, FakeDaemon $daemon, string $outcome, array $extra = []): DaemonTask
{
    $lease = $daemon->send($test, 'POST', '/daemon/v1/tasks/lease', ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 1, 'types' => ['self_update']])->assertOk();
    expect(ProtocolSchemas::errors('task-lease', (string) $lease->getContent()))->toBe([]);
    $id = (string) $lease->json('tasks.0.id');

    $daemon->send($test, 'POST', "/daemon/v1/tasks/{$id}/complete", [
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => 1, 'outcome' => $outcome, ...$extra,
    ], ['Idempotency-Key' => "{$id}:1"])->assertSuccessful();

    return DaemonTask::query()->where('public_id', $id)->sole();
}

test('only instance owners see and use Settings → Updates', function (): void {
    $admin = memberOf($this->team, TeamRole::Admin);

    $this->actingAs($admin)->get(route('updates.edit'))->assertForbidden();
    $this->actingAs($admin)->post(route('updates.check'))->assertForbidden();
    $this->actingAs($admin)->withSession(passwordConfirmed())->post(route('updates.control-plane'), ['version' => '0.2.0', 'skipBackup' => true])->assertForbidden();
    $this->actingAs($admin)->withSession(passwordConfirmed())->post(route('updates.servers'))->assertForbidden();

    $this->actingAs($this->owner)->get(route('updates.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('settings/updates')
            ->where('currentVersion', '0.1.0')
            ->where('servers.0.name', 'main')
            ->where('running', false));

    // Mutations need a recently confirmed password.
    $this->flushSession();
    $this->actingAs($this->owner)->post(route('updates.control-plane'), ['version' => '0.2.0'])->assertRedirect(route('password.confirm'));
});

test('the check offers the newest stable release with its changelog and pinned images', function (): void {
    fakeGitHub([
        release('v0.3.0-beta.1', ['prerelease' => true]),
        release('v0.2.1'),
        release('v0.2.0'),
        release('v0.9.0', ['draft' => true]),
        release('v0.0.9'),
        release('not-a-version'),
    ]);

    $this->actingAs($this->owner)->post(route('updates.check'))->assertSessionHas('success', 'launchroom 0.2.1 is available.');

    $this->actingAs($this->owner)->get(route('updates.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('check.available.version', '0.2.1')
            ->where('check.available.daemonImage', daemonImage('b'))
            ->where('check.available.dashboardImage', 'ghcr.io/vantezzen/launchroom-dashboard@sha256:'.str_repeat('d', 64))
            ->where('check.available.notes', "## Changes\n- <script>alert(1)</script> is just text")
            // Highlights are plain text: tags are stripped, never rendered.
            ->where('highlights', ['alert(1) is just text'])
            ->where('check.error', null));

    // The asset redirect was followed once, to GitHub's storage host only,
    // with its signed query string intact.
    Http::assertSent(fn (Request $request): bool => $request->url() === RELEASES_URL.'/assets/'.crc32('v0.2.1') && $request->hasHeader('Accept', 'application/octet-stream'));
    Http::assertSent(fn (Request $request): bool => $request->url() === storageUrl('v0.2.1'));
});

test('release candidates are found on the beta channel and ordered by semver precedence', function (): void {
    fakeGitHub([
        release('v0.1.0-rc.10', ['prerelease' => true]),
        release('v0.1.0-rc.9', ['prerelease' => true]),
        release('v0.1.0-rc.3', ['prerelease' => true]),
        release('v0.1.0-rc.2', ['prerelease' => true]),
        release('v0.0.9'),
    ]);
    config(['launchroom.version' => '0.1.0-rc.3', 'launchroom.updates.channel' => 'beta']);

    $this->actingAs($this->owner)->post(route('updates.check'))->assertSessionHas('success', 'launchroom 0.1.0-rc.10 is available.');
    $this->actingAs($this->owner)->get(route('updates.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('check.available.version', '0.1.0-rc.10')
            ->where('check.available.prerelease', true)
            ->where('check.available.daemonImage', daemonImage('b'))
            ->where('check.available.cannotInstallReason', null));

    // Stable never offers a pre-release, not even a newer one.
    config(['launchroom.updates.channel' => 'stable']);
    $this->actingAs($this->owner)->post(route('updates.check'))->assertSessionHas('success', 'launchroom is up to date.');
});

test('without a chosen channel a pre-release version follows beta, a release stable', function (): void {
    fakeGitHub([release('v0.1.0-rc.4', ['prerelease' => true]), release('v0.1.0-rc.3', ['prerelease' => true]), release('v0.0.9')]);
    config(['launchroom.version' => '0.1.0-rc.3', 'launchroom.updates.channel' => null]);

    $this->actingAs($this->owner)->post(route('updates.check'))->assertSessionHas('success', 'launchroom 0.1.0-rc.4 is available.');
    $this->actingAs($this->owner)->get(route('updates.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('preferences.channel', 'beta')->where('check.channel', 'beta'));

    // An empty LAUNCHROOM_UPDATES_CHANNEL counts as unset too.
    config(['launchroom.version' => '0.0.9', 'launchroom.updates.channel' => '']);
    $this->actingAs($this->owner)->post(route('updates.check'))->assertSessionHas('success', 'launchroom is up to date.');

    // Development builds are not releases.
    config(['launchroom.version' => '0.0.0-dev']);
    expect(resolve(ReleaseChecker::class)->channel())->toBe('stable');

    // A channel picked in Settings → Updates wins over the inference.
    InstanceSettings::current()->forceFill(['update_channel' => 'stable'])->save();
    config(['launchroom.version' => '0.1.0-rc.3']);
    $this->actingAs($this->owner)->post(route('updates.check'))->assertSessionHas('success', 'launchroom is up to date.');
});

test('a version change never shows the previous check, and nothing at or below the running version is offered', function (): void {
    fakeGitHub([release('v0.1.0-rc.4', ['prerelease' => true]), release('v0.1.0-rc.3', ['prerelease' => true])]);
    config(['launchroom.version' => '0.1.0-rc.3', 'launchroom.updates.channel' => 'beta']);

    $this->actingAs($this->owner)->post(route('updates.check'));
    $this->actingAs($this->owner)->get(route('updates.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('check.available.version', '0.1.0-rc.4')->where('updateAvailable', true));

    // Updated by hand with the installer: the dashboard now runs rc.4.
    config(['launchroom.version' => '0.1.0-rc.4']);

    $this->actingAs($this->owner)->get(route('updates.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('currentVersion', '0.1.0-rc.4')
            ->where('check.currentVersion', '0.1.0-rc.4')
            ->where('check.available', null)
            ->where('check.checkedAt', null)
            ->where('updateAvailable', false));

    $this->actingAs($this->owner)->post(route('updates.check'))->assertSessionHas('success', 'launchroom is up to date.');
});

test('a cached release that is not newer than the running version is dropped', function (): void {
    fakeGitHub([release('v0.2.0')]);
    $checker = resolve(ReleaseChecker::class);
    $release = $checker->release('0.2.0');

    // Whatever ended up in the cache entry of this version (an old entry,
    // a hand-edited one), the running version decides what is offered.
    config(['launchroom.version' => '0.2.0']);
    $key = new ReflectionMethod($checker, 'cacheKey')->invoke($checker);
    cache()->put($key, new ReleaseCheckData('0.1.0', 'stable', $release, now()->toIso8601String(), null)->toArray());

    expect($checker->cached()->available)->toBeNull()
        ->and($checker->cached()->currentVersion)->toBe('0.2.0');

    config(['launchroom.version' => '0.1.0']);
    cache()->put(new ReflectionMethod($checker, 'cacheKey')->invoke($checker), new ReleaseCheckData('0.1.0', 'stable', $release, now()->toIso8601String(), null)->toArray());
    expect($checker->cached()->available?->version)->toBe('0.2.0');
});

test('a release the dashboard cannot install says why and shows the installer command', function (string $case, string $reason): void {
    fakeGitHub([release('v0.1.0-rc.4', ['prerelease' => true, ...($case === 'no images.env' ? ['assets' => []] : [])])], match ($case) {
        'no images.env' => [],
        'storage refuses' => [storageUrl('v0.1.0-rc.4') => Http::response('denied', 403)],
        default => [storageUrl('v0.1.0-rc.4') => Http::response("LR_DAEMON_IMAGE=ghcr.io/vantezzen/launchroomd:0.1.0-rc.4\n")],
    });
    config(['launchroom.version' => '0.1.0-rc.3', 'launchroom.updates.channel' => 'beta']);

    $this->actingAs($this->owner)->post(route('updates.check'));
    $this->actingAs($this->owner)->get(route('updates.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('check.available.version', '0.1.0-rc.4')
            ->where('check.available.daemonImage', null)
            ->where('check.available.cannotInstallReason', $reason)
            ->where('check.available.installerCommand', 'curl -fsSL https://launchroom.vantezzen.io/install | sh -s -- --version 0.1.0-rc.4'));

    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('updates.control-plane'), ['version' => '0.1.0-rc.4', 'skipBackup' => true])
        ->assertSessionHasErrors(['version' => "{$reason} Install it with the installer instead: curl -fsSL https://launchroom.vantezzen.io/install | sh -s -- --version 0.1.0-rc.4"]);
    expect(ControlPlaneUpdate::query()->count())->toBe(0);
})->with([
    ['no images.env', 'The release has no image list (images.env), so the dashboard doesn’t know which images to install.'],
    ['storage refuses', 'GitHub answered 403 when the dashboard downloaded the release’s image list (images.env).'],
    ['no pinned server image', 'Its image list (images.env) doesn’t pin the server image (LR_DAEMON_IMAGE) by digest.'],
]);

test('the beta channel includes prereleases; up to date and failures are reported', function (): void {
    fakeGitHub([release('v0.3.0-beta.1', ['prerelease' => true]), release('v0.1.0')]);
    config(['launchroom.updates.channel' => 'beta']);

    $this->actingAs($this->owner)->post(route('updates.check'))->assertSessionHas('success', 'launchroom 0.3.0-beta.1 is available.');

    config(['launchroom.updates.channel' => 'stable']);
    $this->actingAs($this->owner)->post(route('updates.check'))->assertSessionHas('success', 'launchroom is up to date.');
});

test('a failed check is reported', function (): void {
    githubDns();
    Http::fake([RELEASES_URL.'?*' => Http::response(['message' => 'rate limited'], 403)]);

    $this->actingAs($this->owner)->post(route('updates.check'))->assertSessionHas('error', 'GitHub answered 403.');
});

test('assets that redirect anywhere but GitHub storage are ignored', function (): void {
    fakeGitHub([release('v0.2.0')], [RELEASES_URL.'/assets/'.crc32('v0.2.0') => Http::response('', 302, ['Location' => 'https://evil.example.com/images.env'])]);

    $this->actingAs($this->owner)->post(route('updates.check'));

    $this->actingAs($this->owner)->get(route('updates.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('check.available.version', '0.2.0')->where('check.available.daemonImage', null)
            ->where('check.available.cannotInstallReason', 'GitHub sent the release’s image list (images.env) from an unexpected address, so the dashboard didn’t use it.'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'evil.example.com'));
});

test('without a backup destination the update needs the acknowledgement, then queues a schema-valid control-plane task', function (): void {
    fakeGitHub([release('v0.2.0')]);

    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('updates.control-plane'), ['version' => '0.2.0'])
        ->assertSessionHasErrors('skipBackup');
    expect(ControlPlaneUpdate::query()->count())->toBe(0);

    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('updates.control-plane'), ['version' => '0.2.0', 'skipBackup' => true])
        ->assertSessionHasNoErrors();

    $update = ControlPlaneUpdate::query()->sole();
    $task = DaemonTask::query()->find($update->daemon_task_id);

    expect($update->status)->toBe(ControlPlaneUpdateStatus::Updating)
        ->and($update->skip_backup)->toBeTrue()
        ->and($update->from_version)->toBe('0.1.0')
        ->and($task?->type)->toBe(DaemonTaskType::SelfUpdate)
        ->and($task?->server_id)->toBe($this->cp->id)
        ->and($task?->pinned_to_server)->toBeTrue()
        ->and($task?->max_attempts)->toBe(3)
        ->and((array) $task?->spec)->toEqual(['version' => '0.2.0', 'image' => daemonImage('b'), 'scope' => 'control_plane'])
        ->and(AuditEvent::query()->where('action', AuditAction::ControlPlaneUpdateStarted->value)->exists())->toBeTrue();

    // A second update while one runs is refused.
    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('updates.control-plane'), ['version' => '0.2.0', 'skipBackup' => true]);
    expect(ControlPlaneUpdate::query()->count())->toBe(1);

    // The page polls while it runs and shows the daemon's progress line.
    $task?->forceFill(['progress' => 'verifying the release signature'])->save();
    $this->actingAs($this->owner)->get(route('updates.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('running', true)->where('controlPlaneUpdate.progress', 'verifying the release signature'));
});

test('versions that are not newer or not released are refused', function (): void {
    fakeGitHub([release('v0.2.0')]);

    foreach (['0.1.0', '0.0.9', '0.5.0'] as $version) {
        $this->actingAs($this->owner)->withSession(passwordConfirmed())
            ->post(route('updates.control-plane'), ['version' => $version, 'skipBackup' => true])
            ->assertSessionHasErrors('version');
    }

    expect(ControlPlaneUpdate::query()->count())->toBe(0);
});

test('a failing pre-upgrade backup stops the update before any task exists', function (): void {
    Backups::fakeEnvironment();
    fakeGitHub([release('v0.2.0')]);
    Backups::destination(null, $this->owner, 'Control plane');
    InstanceSettings::current()->forceFill(['recovery_public_key' => 'x25519:'.base64_encode(random_bytes(32)), 'recovery_kdf' => ['salt' => 'x']])->save();

    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('updates.control-plane'), ['version' => '0.2.0'])
        ->assertSessionHasNoErrors();

    $update = ControlPlaneUpdate::query()->sole();

    expect($update->status)->toBe(ControlPlaneUpdateStatus::Failed)
        ->and($update->error)->toContain('pre-upgrade backup failed')
        ->and(BackupRun::query()->find($update->backup_run_id)?->reason->value)->toBe('pre_upgrade')
        ->and($update->daemon_task_id)->toBeNull()
        ->and(DaemonTask::query()->where('type', DaemonTaskType::SelfUpdate->value)->exists())->toBeFalse();
});

test('the control-plane task outcome decides the update status', function (string $outcome, array $extra, ControlPlaneUpdateStatus $expected): void {
    fakeGitHub([release('v0.2.0')]);
    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('updates.control-plane'), ['version' => '0.2.0', 'skipBackup' => true]);

    $task = finishSelfUpdate($this, $this->cpDaemon, $outcome, $extra);
    $update = ControlPlaneUpdate::query()->sole();

    expect($update->status)->toBe($expected)
        ->and($task->status->isTerminal())->toBeTrue()
        ->and(AuditEvent::query()->where('action', AuditAction::ControlPlaneUpdateFinished->value)->exists())->toBeTrue();

    if ($expected === ControlPlaneUpdateStatus::RolledBack) {
        expect($task->result['selfUpdate'] ?? null)->toEqual(['scope' => 'control_plane', 'fromVersion' => '0.1.0', 'toVersion' => '0.2.0', 'rolledBack' => true])
            ->and($update->error)->toBe('rolled back to 0.1.0');
    }
})->with([
    'succeeded' => ['succeeded', ['selfUpdate' => ['scope' => 'control_plane', 'fromVersion' => '0.1.0', 'toVersion' => '0.2.0', 'rolledBack' => false]], ControlPlaneUpdateStatus::Succeeded],
    'rolled back' => ['failed', ['error' => 'rolled back to 0.1.0', 'selfUpdate' => ['scope' => 'control_plane', 'fromVersion' => '0.1.0', 'toVersion' => '0.2.0', 'rolledBack' => true]], ControlPlaneUpdateStatus::RolledBack],
    'failed' => ['failed', ['error' => 'signature does not verify'], ControlPlaneUpdateStatus::Failed],
]);

test('the scheduler records an outcome whose event was lost', function (): void {
    fakeGitHub([release('v0.2.0')]);
    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('updates.control-plane'), ['version' => '0.2.0', 'skipBackup' => true]);

    $task = DaemonTask::query()->find(ControlPlaneUpdate::query()->sole()->daemon_task_id);
    // Finished without the event (the dashboard was restarting).
    DaemonTask::withoutEvents(fn () => $task?->forceFill(['status' => DaemonTaskStatus::Succeeded, 'finished_at' => now()])->saveQuietly());

    $this->artisan('launchroom:updates:advance')->assertSuccessful();

    expect(ControlPlaneUpdate::query()->sole()->status)->toBe(ControlPlaneUpdateStatus::Succeeded);
});

test('servers are updated one at a time, edges last, without the control plane, up-to-date or newer servers', function (): void {
    config(['launchroom.version' => '0.2.0']);
    $seeds = [];
    $make = function (string $name, array $roles, string $version) use (&$seeds): Server {
        $seeds[$name] = random_bytes(32);

        return Server::factory()->withSigningSeed($seeds[$name])->online()->create(['team_id' => $this->team->id, 'name' => $name, 'roles' => $roles, 'daemon_version' => $version]);
    };
    $edge = $make('edge-1', [ServerRole::Edge, ServerRole::Runtime], '0.1.0');
    $runtime = $make('runtime-1', [ServerRole::Runtime], '0.1.0');
    $make('current', [ServerRole::Runtime], '0.2.0');
    $make('newer', [ServerRole::Build], '0.3.0');
    // Another team's server is part of the instance's fleet too.
    $other = Server::factory()->online()->create(['name' => 'build-1', 'roles' => [ServerRole::Build], 'daemon_version' => '0.1.5']);

    $this->actingAs($this->owner)->withSession(passwordConfirmed())->post(route('updates.servers'))->assertSessionHasNoErrors();

    $fleet = FleetUpdate::query()->sole();
    $steps = $fleet->steps()->get();

    expect($steps->pluck('server_name')->all())->toBe(['runtime-1', 'build-1', 'edge-1'])
        ->and($steps->pluck('status')->all())->toBe([FleetUpdateStepStatus::Updating, FleetUpdateStepStatus::Pending, FleetUpdateStepStatus::Pending])
        ->and(DaemonTask::query()->where('type', DaemonTaskType::SelfUpdate->value)->count())->toBe(1);

    $first = DaemonTask::query()->find($steps[0]->daemon_task_id);
    expect($first?->server_id)->toBe($runtime->id)
        ->and((array) $first?->spec)->toEqual(['version' => '0.2.0', 'image' => daemonImage()])
        ->and($first?->timeout_seconds)->toBe(1200);

    // Only one rollout at a time; the control plane can't update meanwhile.
    $this->actingAs($this->owner)->withSession(passwordConfirmed())->post(route('updates.servers'))->assertSessionHasErrors();
    expect(FleetUpdate::query()->count())->toBe(1);

    finishSelfUpdate($this, new FakeDaemon($seeds['runtime-1'])->as($runtime->public_id), 'succeeded');

    $steps = $fleet->steps()->get();
    expect($steps->pluck('status')->all())->toBe([FleetUpdateStepStatus::Succeeded, FleetUpdateStepStatus::Updating, FleetUpdateStepStatus::Pending])
        ->and(DaemonTask::query()->find($steps[1]->daemon_task_id)?->server_id)->toBe($other->id);

    // build-1 rolls back: the rollout stops, edge-1 is never touched.
    DaemonTask::query()->whereKey($steps[1]->daemon_task_id)->sole()->forceFill(['status' => DaemonTaskStatus::Failed, 'error' => 'rolled back to 0.1.5', 'result' => ['selfUpdate' => ['scope' => 'daemon', 'fromVersion' => '0.1.5', 'toVersion' => '0.2.0', 'rolledBack' => true]], 'finished_at' => now()])->save();
    $this->artisan('launchroom:updates:advance')->assertSuccessful();

    $fleet->refresh();
    $steps = $fleet->steps()->get();
    expect($fleet->status)->toBe(FleetUpdateStatus::Failed)
        ->and($fleet->error)->toBe('build-1: rolled back to 0.1.5')
        ->and($steps->pluck('status')->all())->toBe([FleetUpdateStepStatus::Succeeded, FleetUpdateStepStatus::RolledBack, FleetUpdateStepStatus::Skipped])
        ->and(DaemonTask::query()->where('server_id', $edge->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditAction::FleetUpdateFinished->value)->exists())->toBeTrue();
});

test('a rollout finishes when every server succeeded', function (): void {
    config(['launchroom.version' => '0.2.0']);
    $seed = random_bytes(32);
    $server = Server::factory()->withSigningSeed($seed)->online()->create(['team_id' => $this->team->id, 'daemon_version' => '0.1.0']);

    $this->actingAs($this->owner)->withSession(passwordConfirmed())->post(route('updates.servers'));
    finishSelfUpdate($this, new FakeDaemon($seed)->as($server->public_id), 'succeeded');

    expect(FleetUpdate::query()->sole()->status)->toBe(FleetUpdateStatus::Succeeded);
});

test('no rollout without a released, digest-pinned target or anything to update', function (): void {
    config(['launchroom.version' => '0.2.0-dev']);
    Server::factory()->online()->create(['daemon_version' => '0.1.0']);
    $this->actingAs($this->owner)->withSession(passwordConfirmed())->post(route('updates.servers'))->assertSessionHasErrors('fleet');

    config(['launchroom.version' => '0.2.0', 'launchroom.daemon.image' => 'ghcr.io/vantezzen/launchroomd:0.2.0']);
    $this->actingAs($this->owner)->withSession(passwordConfirmed())->post(route('updates.servers'))->assertSessionHasErrors('fleet');

    config(['launchroom.version' => '0.1.0', 'launchroom.daemon.image' => daemonImage()]);
    $this->actingAs($this->owner)->withSession(passwordConfirmed())->post(route('updates.servers'))->assertSessionHasErrors('fleet');

    expect(FleetUpdate::query()->count())->toBe(0);
});

test('daemons without a common protocol version are refused with 426', function (): void {
    $this->cpDaemon->send($this, 'GET', '/daemon/v1/config', null, ['X-Lr-Protocol-Versions' => 'v9'])
        ->assertStatus(426)
        ->assertJsonPath('error', 'unsupported_protocol')
        ->assertJsonPath('supported', ['v1']);

    $this->cpDaemon->send($this, 'GET', '/daemon/v1/config', null, ['X-Lr-Protocol-Versions' => ''])->assertStatus(426);

    $this->cp->forceFill(['protocol_version' => null])->save();
    $this->cpDaemon->send($this, 'GET', '/daemon/v1/config', null, ['X-Lr-Protocol-Versions' => 'v2, v1'])->assertOk();
    expect($this->cp->refresh()->protocol_version)->toBe('v1');
});

test('the channel and automatic updates are owner settings, audited, and automatic updates need a backup destination', function (): void {
    Queue::fake();
    // Several requests in a row; throttling has its own tests.
    $this->withoutMiddleware(ThrottleRequests::class);
    fakeGitHub([release('v0.3.0-beta.1', ['prerelease' => true]), release('v0.2.0')]);
    $admin = memberOf($this->team, TeamRole::Admin);

    $this->actingAs($admin)->withSession(passwordConfirmed())->put(route('updates.preferences'), ['channel' => 'beta', 'autoUpdate' => false])->assertForbidden();
    $this->flushSession();
    $this->actingAs($this->owner)->put(route('updates.preferences'), ['channel' => 'beta', 'autoUpdate' => false])->assertRedirect(route('password.confirm'));

    // Without a control-plane destination, automatic updates stay off.
    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->put(route('updates.preferences'), ['channel' => 'stable', 'autoUpdate' => true])
        ->assertSessionHasErrors('autoUpdate');
    expect(InstanceSettings::current()->auto_update)->toBeFalse();

    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->put(route('updates.preferences'), ['channel' => 'nightly', 'autoUpdate' => false])
        ->assertSessionHasErrors('channel');

    // The beta channel offers prereleases; the cached check is per channel.
    $this->actingAs($this->owner)->post(route('updates.check'))->assertSessionHas('success', 'launchroom 0.2.0 is available.');
    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->put(route('updates.preferences'), ['channel' => 'beta', 'autoUpdate' => false])
        ->assertSessionHasNoErrors();
    expect(InstanceSettings::current()->update_channel)->toBe('beta');
    $this->actingAs($this->owner)->post(route('updates.check'))->assertSessionHas('success', 'launchroom 0.3.0-beta.1 is available.');

    Backups::destination(null, $this->owner, 'Control plane');
    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->put(route('updates.preferences'), ['channel' => 'beta', 'autoUpdate' => true])
        ->assertSessionHasNoErrors();

    $settings = InstanceSettings::current();
    expect($settings->auto_update)->toBeTrue()
        ->and($settings->auto_update_by)->toBe($this->owner->id)
        ->and(AuditEvent::query()->where('action', AuditAction::UpdatePreferencesChanged->value)->count())->toBe(2);

    $this->actingAs($this->owner)->get(route('updates.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('preferences.channel', 'beta')
            ->where('preferences.autoUpdate', true)
            ->where('preferences.canAutoUpdate', true)
            ->where('preferences.windowStart', 2)
            ->where('preferences.timezone', 'UTC')
            ->where('backupDestinationName', 'Control plane'));
});

test('"and my servers": the new dashboard rolls every server to its version once, after the control plane', function (): void {
    fakeGitHub([release('v0.2.0')]);
    $seed = random_bytes(32);
    $server = Server::factory()->withSigningSeed($seed)->online()->create(['team_id' => $this->team->id, 'name' => 'fra-1', 'daemon_version' => '0.1.0']);

    $this->actingAs($this->owner)->post(route('updates.check'));
    $this->actingAs($this->owner)->get(route('updates.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('serversBehindRelease', 1));

    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('updates.control-plane'), ['version' => '0.2.0', 'skipBackup' => true, 'updateServers' => true])
        ->assertSessionHasNoErrors();
    expect(ControlPlaneUpdate::query()->sole()->update_servers)->toBeTrue();

    // The old dashboard (0.1.0) records the outcome but never starts the rollout.
    finishSelfUpdate($this, $this->cpDaemon, 'succeeded', ['selfUpdate' => ['scope' => 'control_plane', 'fromVersion' => '0.1.0', 'toVersion' => '0.2.0', 'rolledBack' => false]]);
    expect(FleetUpdate::query()->count())->toBe(0);

    // The new one does, once.
    config(['launchroom.version' => '0.2.0']);
    $this->artisan('launchroom:updates:advance')->assertSuccessful();
    $this->artisan('launchroom:updates:advance')->assertSuccessful();

    $update = ControlPlaneUpdate::query()->sole();
    $fleet = FleetUpdate::query()->sole();
    expect($update->fleet_update_id)->toBe($fleet->id)
        ->and($fleet->actor_id)->toBe($this->owner->id)
        ->and($fleet->steps()->pluck('server_name')->all())->toBe(['fra-1']);

    $this->actingAs($this->owner)->get(route('updates.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('controlPlaneUpdate.fleetUpdateId', $fleet->public_id)
            ->where('fleetUpdate.steps.0.status', 'updating')
            ->where('running', true));

    finishSelfUpdate($this, new FakeDaemon($seed)->as($server->public_id), 'succeeded');
    expect($fleet->refresh()->status)->toBe(FleetUpdateStatus::Succeeded);
});

test('"and my servers" with nothing to update is dropped instead of retried', function (): void {
    fakeGitHub([release('v0.2.0')]);
    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('updates.control-plane'), ['version' => '0.2.0', 'skipBackup' => true, 'updateServers' => true]);
    finishSelfUpdate($this, $this->cpDaemon, 'succeeded');

    config(['launchroom.version' => '0.2.0']);
    $this->artisan('launchroom:updates:advance')->assertSuccessful();

    expect(FleetUpdate::query()->count())->toBe(0)
        ->and(ControlPlaneUpdate::query()->sole()->update_servers)->toBeFalse();
});

test('automatic updates run at night with a backup, for a release never tried before, and include the servers', function (): void {
    Queue::fake();
    fakeGitHub([release('v0.2.0')]);
    Backups::destination(null, $this->owner, 'Control plane');
    $settings = InstanceSettings::current();
    $night = now()->setTimezone('UTC')->setTime(3, 0);
    $day = $night->copy()->setTime(14, 0);
    $run = resolve(RunAutomaticUpdate::class);

    expect($run->handle($night))->toBe('automatic updates are off');

    $settings->forceFill(['auto_update' => true, 'auto_update_by' => $this->owner->id])->save();
    expect($run->handle($day))->toBe('outside the update window');

    // The window follows the control-plane server's time zone.
    $this->cp->forceFill(['facts' => [...$this->cp->facts->toArray(), 'hardware' => ['timezone' => 'Asia/Tokyo']]])->save();
    $window = resolve(AutoUpdateWindow::class);
    expect($window->contains(now()->setTimezone('UTC')->setTime(18, 0)))->toBeTrue()
        ->and($window->contains($night))->toBeFalse();
    $this->cp->forceFill(['facts' => [...$this->cp->facts->toArray(), 'hardware' => null]])->save();

    // The owner who turned it on must still be an instance owner.
    $this->owner->forceFill(['is_instance_owner' => false])->save();
    expect($run->handle($night))->toContain('no longer an instance owner');
    $this->owner->forceFill(['is_instance_owner' => true])->save();

    $update = $run->handle($night);
    expect($update)->toBeInstanceOf(ControlPlaneUpdate::class);
    expect($update->automatic)->toBeTrue()
        ->and($update->update_servers)->toBeTrue()
        ->and($update->skip_backup)->toBeFalse()
        ->and($update->actor_id)->toBe($this->owner->id);

    // While it runs, and after it failed, nothing new is started for that release.
    expect($run->handle($night))->toBe('an update is running');
    ControlPlaneUpdate::query()->whereKey($update->id)->update(['status' => 'rolled_back', 'finished_at' => now()]);
    expect($run->handle($night))->toBe('0.2.0 was tried before');
});

test('automatic updates never run without an off-site backup', function (): void {
    fakeGitHub([release('v0.2.0')]);
    InstanceSettings::current()->forceFill(['auto_update' => true, 'auto_update_by' => $this->owner->id])->save();

    expect(resolve(RunAutomaticUpdate::class)->handle(now()->setTime(3, 0)))->toBe('no control-plane backup destination')
        ->and(ControlPlaneUpdate::query()->count())->toBe(0);
    $this->artisan('launchroom:updates:auto')->assertSuccessful();
});
