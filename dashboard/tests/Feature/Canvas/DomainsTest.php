<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\DomainKind;
use App\Enums\TeamRole;
use App\Jobs\Domains\VerifyDomainJob;
use App\Models\AuditEvent;
use App\Models\Domain;
use App\Models\InstanceSettings;
use App\Models\Team;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeDns;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    $this->team = Team::factory()->create();
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    [$this->server] = Projects::server($this->team, ip: '203.0.113.7');
    $this->project = Projects::create($this->team);
    $this->production = $this->project->production()->sole();
    $this->web = Projects::image($this->project, 'web');
    $this->route = fn (string $name, ?Domain $domain = null): string => route($name, array_filter([$this->project, $this->production, $this->web, $domain]));
    $this->settings = fn () => $this->actingAs($this->developer)->get(route('projects.services.show', [$this->project, $this->production, $this->web, 'settings']));
});

function domainNamed(string $hostname): Domain
{
    return Domain::query()->where('hostname', $hostname)->sole();
}

test('adding a custom domain checks its DNS right away and shows the result', function (): void {
    FakeDns::set('shop.example.com', ['203.0.113.7']);

    $this->actingAs($this->developer)->post(($this->route)('projects.domains.store'), ['hostname' => 'shop.example.com'])->assertRedirect();

    expect(domainNamed('shop.example.com')->verification)->toMatchArray(['status' => 'ok', 'addresses' => ['203.0.113.7'], 'expected' => ['203.0.113.7']]);

    ($this->settings)()->assertInertia(fn ($page) => $page
        ->where('panel.settings.domains.0.hostname', 'shop.example.com')
        ->where('panel.settings.domains.0.verification.status', 'ok')
        ->where('panel.settings.domains.0.verification.message', 'shop.example.com points at your server (203.0.113.7).')
        ->where('panel.settings.domains.0.canRedirectWww', true)
        ->where('panel.settings.domains.0.wwwRedirect', false)
        // sslip.io generated domains are no CNAME target.
        ->where('panel.settings.domains.0.cnameTarget', null));
});

test('verification tells missing, foreign and partial records apart', function (): void {
    $domain = Domain::query()->create(['team_id' => $this->team->id, 'service_instance_id' => $this->web->instances()->sole()->id, 'hostname' => 'shop.example.com', 'kind' => DomainKind::Custom]);
    $verify = fn () => $this->actingAs($this->developer)->post(($this->route)('projects.domains.verify', $domain))->assertRedirect();

    $verify();
    expect($domain->refresh()->verification['status'])->toBe('missing');

    FakeDns::set('shop.example.com', ['198.51.100.9'], ['old-host.example.net']);
    $verify();
    expect($domain->refresh()->verification)->toMatchArray([
        'status' => 'mismatch',
        'cname' => 'old-host.example.net',
        'message' => 'shop.example.com points at 198.51.100.9, not at your server (203.0.113.7).',
    ]);

    FakeDns::set('shop.example.com', ['203.0.113.7', '198.51.100.9']);
    $verify();
    expect($domain->refresh()->verification['status'])->toBe('mismatch')
        ->and($domain->verification['message'])->toContain('also points at 198.51.100.9');

    FakeDns::fail('shop.example.com');
    $verify();
    expect($domain->refresh()->verification['status'])->toBe('error');
});

test('viewers see verification but cannot re-check or change domains', function (): void {
    $domain = Domain::query()->create(['team_id' => $this->team->id, 'service_instance_id' => $this->web->instances()->sole()->id, 'hostname' => 'shop.example.com', 'kind' => DomainKind::Custom]);
    $generated = Domain::query()->where('kind', DomainKind::Generated)->sole();

    $this->actingAs($this->viewer)->post(($this->route)('projects.domains.verify', $domain))->assertForbidden();
    $this->actingAs($this->viewer)->patch(($this->route)('projects.domains.update', $domain), ['wwwRedirect' => true])->assertForbidden();
    // Generated domains have no DNS of their own to check and no www redirect.
    $this->actingAs($this->developer)->post(($this->route)('projects.domains.verify', $generated))->assertForbidden();
    $this->actingAs($this->developer)->patch(($this->route)('projects.domains.update', $generated), ['wwwRedirect' => true])->assertForbidden();
});

test('the www redirect adds and removes www.<host> as a redirect domain, audited', function (): void {
    $this->actingAs($this->developer)->post(($this->route)('projects.domains.store'), ['hostname' => 'example.com'])->assertRedirect();
    $domain = domainNamed('example.com');

    $this->actingAs($this->developer)->patch(($this->route)('projects.domains.update', $domain), [])->assertSessionHasErrors('wwwRedirect');
    $this->actingAs($this->developer)->patch(($this->route)('projects.domains.update', $domain), ['wwwRedirect' => true])->assertRedirect();

    $www = domainNamed('www.example.com');
    expect($www->redirect_to)->toBe('example.com')
        ->and($www->kind)->toBe(DomainKind::Custom)
        ->and(AuditEvent::query()->where('action', AuditAction::DomainWwwRedirectChanged->value)->count())->toBe(1);

    ($this->settings)()->assertInertia(fn ($page) => $page
        ->where('panel.settings.domains.0.hostname', 'example.com')
        ->where('panel.settings.domains.0.wwwRedirect', true)
        // The www row itself can't redirect again.
        ->where('panel.settings.domains.1.hostname', 'www.example.com')
        ->where('panel.settings.domains.1.canRedirectWww', false));

    // Turning it on again changes nothing; a www domain can't get its own www redirect.
    $this->actingAs($this->developer)->patch(($this->route)('projects.domains.update', $domain), ['wwwRedirect' => true])->assertRedirect();
    $this->actingAs($this->developer)->patch(($this->route)('projects.domains.update', $www), ['wwwRedirect' => true])->assertSessionHasErrors('wwwRedirect');
    expect(Domain::query()->where('kind', DomainKind::Custom)->count())->toBe(2);

    $this->actingAs($this->developer)->patch(($this->route)('projects.domains.update', $domain), ['wwwRedirect' => false])->assertRedirect();
    expect(Domain::query()->where('hostname', 'www.example.com')->exists())->toBeFalse();
});

test('removing a domain removes its www redirect too', function (): void {
    $this->actingAs($this->developer)->post(($this->route)('projects.domains.store'), ['hostname' => 'example.com'])->assertRedirect();
    $domain = domainNamed('example.com');
    $this->actingAs($this->developer)->patch(($this->route)('projects.domains.update', $domain), ['wwwRedirect' => true])->assertRedirect();

    $this->actingAs($this->developer)->delete(($this->route)('projects.domains.destroy', $domain))->assertRedirect();

    expect(Domain::query()->where('kind', DomainKind::Custom)->exists())->toBeFalse();
});

test('subdomains may CNAME to the generated hostname under the platform domain', function (): void {
    InstanceSettings::current()->forceFill(['apps_domain' => 'apps.example.org'])->save();
    $api = Projects::image($this->project, 'api');
    $instance = $api->instances()->sole();

    $this->actingAs($this->developer)->post(route('projects.domains.store', [$this->project, $this->production, $api]), ['hostname' => 'api.example.com'])->assertRedirect();
    $this->actingAs($this->developer)->post(route('projects.domains.store', [$this->project, $this->production, $api]), ['hostname' => 'example.net'])->assertRedirect();
    $generated = Domain::query()->where('service_instance_id', $instance->id)->where('kind', DomainKind::Generated)->sole()->hostname;

    expect($generated)->toEndWith('.apps.example.org');

    $this->actingAs($this->developer)->get(route('projects.services.show', [$this->project, $this->production, $api, 'settings']))
        ->assertInertia(fn ($page) => $page
            ->where('panel.settings.domains.0.hostname', 'api.example.com')
            ->where('panel.settings.domains.0.cnameTarget', $generated)
            // No CNAME at a zone apex.
            ->where('panel.settings.domains.1.hostname', 'example.net')
            ->where('panel.settings.domains.1.cnameTarget', null));
});

test('the periodic check queues domains that are due', function (): void {
    $instanceId = $this->web->instances()->sole()->id;
    $create = fn (string $host, array $verification) => Domain::query()->create(['team_id' => $this->team->id, 'service_instance_id' => $instanceId, 'hostname' => $host, 'kind' => DomainKind::Custom])->forceFill(['verification' => $verification])->save();

    $create('never.example.com', []);
    $create('pending-old.example.com', ['status' => 'missing', 'checkedAt' => now()->subMinutes(10)->toIso8601ZuluString()]);
    $create('pending-new.example.com', ['status' => 'missing', 'checkedAt' => now()->subMinute()->toIso8601ZuluString()]);
    $create('ok-old.example.com', ['status' => 'ok', 'checkedAt' => now()->subHours(7)->toIso8601ZuluString()]);
    $create('ok-new.example.com', ['status' => 'ok', 'checkedAt' => now()->subHour()->toIso8601ZuluString()]);

    Queue::fake();
    $this->artisan('launchroom:domains:verify')->assertSuccessful();

    $queued = collect(Queue::pushed(VerifyDomainJob::class))->map(fn (VerifyDomainJob $job): string => Domain::query()->whereKey($job->domainId)->value('hostname'))->sort()->values()->all();
    expect($queued)->toBe(['never.example.com', 'ok-old.example.com', 'pending-old.example.com']);
});
