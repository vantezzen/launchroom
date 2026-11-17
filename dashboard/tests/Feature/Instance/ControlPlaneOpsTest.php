<?php

declare(strict_types=1);

use App\Actions\Instance\DashboardDomain\RecordDashboardCertificate;
use App\Data\Protocol\V1\CertificateStatus;
use App\Enums\AuditAction;
use App\Enums\CertStatus;
use App\Enums\DashboardDomainStatus;
use App\Enums\ServerRole;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\DashboardDomainChange;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Queries\ControlPlaneHealth;
use App\Services\Scheduling\SchedulerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * G31 (artisan side), G34, G37, G38, G41: the control plane's own checks,
 * readiness, the scheduler heartbeat, the sign-in link and Settings → System.
 */

/**
 * @return array{checks: list<array{id: string, title: string, status: string, summary: string, detail: list<string>, fix: string|null}>}
 */
function doctorJson(int &$exit = 0): array
{
    $exit = Artisan::call('launchroom:doctor', ['--json' => true]);
    $doc = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($doc)->toBeArray()->toHaveKey('checks');

    return $doc;
}

test('/up/ready answers 200 with a constant body while the database and the cache answer', function (): void {
    $this->getJson('/up/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'database' => true, 'cache' => true]);
});

test('launchroom:doctor --json prints checks in the format ./launchroom doctor reads', function (): void {
    $exit = 0;
    $doc = doctorJson($exit);

    foreach ($doc['checks'] as $check) {
        expect($check['id'])->toMatch('/^[a-z0-9][a-z0-9.-]{0,63}$/')
            ->and($check['status'])->toBeIn(['ok', 'warn', 'fail', 'skip'])
            ->and($check['fix'])->toBeIn([null, 'clear-schedule-locks', 'restart-workers'])
            ->and($check['summary'])->not->toBe('')
            ->and(array_keys($check))->toBe(['id', 'title', 'status', 'summary', 'detail', 'fix']);
    }

    $byId = collect($doc['checks'])->keyBy('id');

    // No heartbeat yet: the scheduler check fails, so the command exits 1.
    expect($byId['scheduler']['status'])->toBe('fail')
        ->and($byId['migrations']['status'])->toBe('ok')
        ->and($exit)->toBe(1);

    resolve(SchedulerHeartbeat::class)->beat(resolve(Schedule::class));

    expect(collect(doctorJson()['checks'])->keyBy('id')['scheduler']['status'])->toBe('ok');
});

test('launchroom:scheduler:alive follows the heartbeat', function (): void {
    expect(Artisan::call('launchroom:scheduler:alive'))->toBe(1);

    resolve(SchedulerHeartbeat::class)->beat(resolve(Schedule::class));
    expect(Artisan::call('launchroom:scheduler:alive'))->toBe(0);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(4));
    expect(Artisan::call('launchroom:scheduler:alive'))->toBe(1)
        ->and(Artisan::output())->toContain('seconds ago');
    CarbonImmutable::setTestNow();
});

test('launchroom:users:sign-in-link prints only a one-time reset link, audited, without the token in the audit log', function (): void {
    config(['app.url' => 'https://dash.example.com']);
    $user = User::factory()->create(['email' => 'ada@example.com', 'remember_token' => 'old-remember-token']);

    expect(Artisan::call('launchroom:users:sign-in-link', ['email' => 'ADA@example.com ']))->toBe(0);
    $url = trim(Artisan::output());

    expect($url)->toMatch('#^https://dash\.example\.com/reset-password/[A-Za-z0-9]+\?email=ada%40example\.com$#')
        ->and(str_contains($url, "\n"))->toBeFalse();

    $token = (string) preg_replace('#^.*/reset-password/([^?]+)\?.*$#', '$1', $url);
    expect(Password::broker()->tokenExists($user, $token))->toBeTrue()
        ->and($user->fresh()?->remember_token)->not->toBe('old-remember-token');

    $audit = AuditEvent::query()->where('action', AuditAction::SignInLinkIssued->value)->sole();
    expect(json_encode($audit->toArray()))->not->toContain($token);

    // The link works once.
    $this->post('/reset-password', ['token' => $token, 'email' => 'ada@example.com', 'password' => 'a-new-Passw0rd-long', 'password_confirmation' => 'a-new-Passw0rd-long'])
        ->assertSessionHasNoErrors();
    expect(Hash::check('a-new-Passw0rd-long', (string) $user->fresh()?->password))->toBeTrue()
        ->and(Password::broker()->tokenExists($user, $token))->toBeFalse();

    expect(Artisan::call('launchroom:users:sign-in-link', ['email' => 'nobody@example.com']))->toBe(1);
});

test('Settings → System is for instance owners and shows the checks', function (): void {
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $admin = memberOf($team, TeamRole::Admin);
    $owner->forceFill(['is_instance_owner' => true])->save();

    $this->actingAs($admin)->get('/settings/system')->assertForbidden();

    $this->actingAs($owner)->get('/settings/system')
        ->assertOk()
        ->assertInertia(static fn (Assert $page): Assert => $page
            ->component('settings/system')
            ->has('system.checks')
            ->where('system.version', config()->string('launchroom.version'))
            ->where('system.horizonUrl', '/horizon')
            ->where('system.services', null));
});

test('the active dashboard domain’s certificate keeps being recorded and expiring soon warns', function (): void {
    $team = Team::factory()->create();
    $server = Server::factory()->online()->create(['team_id' => $team->id, 'roles' => [ServerRole::Edge, ServerRole::Runtime], 'is_control_plane' => true]);
    $change = new DashboardDomainChange;
    $change->forceFill(['hostname' => 'dash.example.com', 'previous_url' => 'https://1-2-3-4.sslip.io', 'status' => DashboardDomainStatus::Active, 'switched_at' => now()])->save();

    $expires = CarbonImmutable::now()->addDays(5)->startOfSecond();
    resolve(RecordDashboardCertificate::class)->handle($server, [
        'dash.example.com' => new CertificateStatus(host: 'dash.example.com', state: CertStatus::Issued, notAfter: $expires->toIso8601ZuluString()),
    ]);

    expect(resolve(ControlPlaneHealth::class)->dashboardCertificate()['expires']?->toIso8601ZuluString())->toBe($expires->toIso8601ZuluString());

    $certificate = collect(resolve(ControlPlaneHealth::class)->checks())->firstWhere('id', 'certificate');
    expect($certificate?->status->value)->toBe('warn')
        ->and($certificate?->summary)->toContain('dash.example.com');
});
