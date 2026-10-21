<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\ServerRole;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Tests\Support\FakeDns;
use Tests\Support\GitHubFake;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->owner->forceFill(['is_instance_owner' => true])->save();
    $this->admin = memberOf($this->team, TeamRole::Admin);
});

function stepStatuses(array $steps): array
{
    return collect($steps)->mapWithKeys(fn (array $step): array => [$step['id'] => $step['status']])->all();
}

test('only instance owners see the checklist and its sidebar progress', function (): void {
    $this->actingAs($this->admin)->get(route('onboarding.show'))->assertForbidden();
    $this->actingAs($this->admin)->patch(route('onboarding.update'), ['dismissed' => true])->assertForbidden();
    $this->actingAs($this->admin)->put(route('onboarding.domain'), ['appsDomain' => 'apps.example.com'])->assertForbidden();
    $this->actingAs($this->admin)->postJson(route('onboarding.domain.check'), ['domain' => 'apps.example.com'])->assertForbidden();
    $this->actingAs($this->admin)->get(route('projects.index'))->assertInertia(fn ($page) => $page->where('onboardingProgress', null));

    $this->actingAs($this->owner)->get(route('projects.index'))
        ->assertInertia(fn ($page) => $page->where('onboardingProgress', ['done' => 0, 'total' => 5, 'complete' => false, 'dismissed' => false]));
});

test('steps are derived from real state', function (): void {
    $this->actingAs($this->owner)->get(route('onboarding.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('onboarding')
            ->where('onboarding.server', null)
            ->where('onboarding.steps', fn ($steps): bool => stepStatuses($steps->all()) === ['server' => 'pending', 'github' => 'pending', 'domain' => 'pending', 'project' => 'pending', 'backups' => 'pending'])
            ->where('onboarding.canCreateProject', true));

    [$server] = Projects::server($this->team, ip: '203.0.113.7');
    $server->forceFill(['is_control_plane' => true])->save();
    GitHubFake::setUp();
    GitHubFake::installation(GitHubFake::app($this->team));
    Projects::create($this->team);
    InstanceSettings::current()->forceFill(['apps_domain' => 'apps.example.com'])->save();

    $this->actingAs($this->owner)->get(route('onboarding.show'))
        ->assertInertia(fn ($page) => $page
            ->where('onboarding.server.id', $server->public_id)
            ->where('onboarding.server.status', 'online')
            ->where('onboarding.serverAddress', '203.0.113.7')
            ->where('onboarding.edgeAddresses', ['203.0.113.7'])
            ->where('onboarding.sslipExample', 'web-production-shop.203-0-113-7.sslip.io')
            ->where('onboarding.githubConnected', true)
            ->where('onboarding.githubAccount', fn (?string $account): bool => is_string($account) && $account !== '')
            ->where('onboarding.canConnectGitHub', true)
            ->where('onboarding.projectCount', 1)
            ->where('onboarding.steps', fn ($steps): bool => stepStatuses($steps->all()) === ['server' => 'done', 'github' => 'done', 'domain' => 'done', 'project' => 'done', 'backups' => 'pending'])
            ->where('onboarding.progress.done', 4));
});

test('steps follow the first-run journey: server, GitHub, address, first app, then the optional backup', function (): void {
    $this->actingAs($this->owner)->get(route('onboarding.show'))
        ->assertInertia(fn ($page) => $page
            ->where('onboarding.steps', fn ($steps): bool => array_column($steps->all(), 'id') === ['server', 'github', 'domain', 'project', 'backups'])
            ->where('onboarding.steps.4.skippable', true)
            ->where('githubManifest', null)
            ->where('onboarding.githubInstallUrl', null)
            ->where('onboarding.githubRepositories', 0));
});

test('steps can be skipped and the checklist dismissed', function (): void {
    $this->actingAs($this->owner)->patch(route('onboarding.update'), ['skip' => 'server'])->assertSessionHasErrors('skip');
    $this->actingAs($this->owner)->patch(route('onboarding.update'), ['skip' => 'backups'])->assertRedirect();
    $this->actingAs($this->owner)->patch(route('onboarding.update'), ['skip' => 'domain'])->assertRedirect();
    $this->actingAs($this->owner)->patch(route('onboarding.update'), ['unskip' => 'domain'])->assertRedirect();

    expect(InstanceSettings::current()->onboarding['skipped'])->toBe(['backups']);

    $this->actingAs($this->owner)->get(route('onboarding.show'))
        ->assertInertia(fn ($page) => $page->where('onboarding.steps.4.status', 'skipped')->where('onboarding.progress.done', 1));

    $this->actingAs($this->owner)->patch(route('onboarding.update'), ['dismissed' => true])->assertRedirect(route('projects.index'));
    expect(InstanceSettings::current()->onboarding['dismissedAt'] ?? null)->toBeString();
    $this->actingAs($this->owner)->get(route('dashboard'))->assertRedirect(route('projects.index'));
});

test('the platform domain is validated, audited and used for new generated domains', function (): void {
    $this->actingAs($this->owner)->put(route('onboarding.domain'), ['appsDomain' => 'not a domain'])->assertSessionHasErrors('appsDomain');
    $this->actingAs($this->owner)->put(route('onboarding.domain'), ['appsDomain' => 'foo.localhost'])->assertSessionHasErrors('appsDomain');
    $this->actingAs($this->owner)->put(route('onboarding.domain'), ['appsDomain' => '1-2-3-4.sslip.io'])->assertSessionHasErrors('appsDomain');
    config(['app.url' => 'https://dash.example.com']);
    $this->actingAs($this->owner)->put(route('onboarding.domain'), ['appsDomain' => 'dash.example.com'])->assertSessionHasErrors('appsDomain');

    $this->actingAs($this->owner)->put(route('onboarding.domain'), ['appsDomain' => '*.Apps.Example.com'])->assertRedirect();
    expect(InstanceSettings::current()->apps_domain)->toBe('apps.example.com')
        ->and(AuditEvent::query()->where('action', AuditAction::AppsDomainChanged->value)->latest('id')->first()?->meta)->toMatchArray(['to' => 'apps.example.com']);

    Projects::server($this->team);
    $web = Projects::image(Projects::create($this->team), 'web');
    expect($web->instances()->sole()->domains()->sole()->hostname)->toBe('web-production.shop.apps.example.com');

    $this->actingAs($this->owner)->put(route('onboarding.domain'), ['appsDomain' => null])->assertRedirect();
    expect(InstanceSettings::current()->apps_domain)->toBeNull();
    $this->actingAs($this->owner)->put(route('onboarding.domain'), [])->assertSessionHasErrors('appsDomain');
});

test('the wildcard DNS check resolves a random name under the domain and compares with the edge', function (): void {
    [$server] = Projects::server($this->team, [ServerRole::Edge, ServerRole::Runtime], ip: '203.0.113.7');

    $this->actingAs($this->owner)->postJson(route('onboarding.domain.check'), ['domain' => 'apps.example.com'])
        ->assertOk()
        ->assertJson(['status' => 'missing', 'expected' => ['203.0.113.7']]);

    expect(FakeDns::current()->queried[0])->toMatch('/^lr-check-[0-9a-f]{8}\.apps\.example\.com$/');

    FakeDns::set('*.apps.example.com', ['203.0.113.7']);
    $this->actingAs($this->owner)->postJson(route('onboarding.domain.check'), ['domain' => '*.apps.example.com'])
        ->assertOk()
        ->assertJson(['status' => 'ok', 'addresses' => ['203.0.113.7'], 'message' => 'The wildcard record points at your server (203.0.113.7).']);

    FakeDns::set('*.apps.example.com', ['198.51.100.1']);
    $this->actingAs($this->owner)->postJson(route('onboarding.domain.check'), ['domain' => 'apps.example.com'])
        ->assertJson(['status' => 'mismatch']);

    $this->actingAs($this->owner)->postJson(route('onboarding.domain.check'), ['domain' => 'http://169.254.169.254/'])->assertStatus(422);
    $this->actingAs($this->owner)->postJson(route('onboarding.domain.check'), ['domain' => 'x.localhost'])->assertStatus(422);
});

test('realtime client settings are shared with signed-in users only, public values only', function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'public-key',
        'broadcasting.connections.reverb.secret' => 'very-secret',
        'launchroom.realtime.port' => '443',
        'launchroom.realtime.scheme' => 'https',
    ]);

    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('realtime', null));

    $response = $this->actingAs($this->admin)->get(route('projects.index'));
    $response->assertInertia(fn ($page) => $page->where('realtime', ['key' => 'public-key', 'host' => null, 'port' => 443, 'scheme' => 'https']));
    expect($response->getContent())->not->toContain('very-secret');

    config(['broadcasting.default' => 'null']);
    $this->actingAs($this->admin)->get(route('projects.index'))->assertInertia(fn ($page) => $page->where('realtime', null));
});

test('a user without a current team gets an empty checklist instead of an error', function (): void {
    $owner = User::factory()->withTwoFactor()->create();
    $owner->forceFill(['is_instance_owner' => true])->save();

    $this->actingAs($owner)->get(route('onboarding.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('onboarding.canCreateProject', false)->where('onboarding.projectCount', 0));
});
