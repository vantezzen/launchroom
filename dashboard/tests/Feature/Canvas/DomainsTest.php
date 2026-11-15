<?php

declare(strict_types=1);

use App\Data\Domains\DnsCheckData;
use App\Enums\AuditAction;
use App\Enums\DomainKind;
use App\Enums\Domains\DnsCheckStatus;
use App\Enums\TeamRole;
use App\Jobs\Domains\VerifyDomainJob;
use App\Models\AuditEvent;
use App\Models\Domain;
use App\Models\InstanceSettings;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
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

    expect(domainNamed('shop.example.com')->verification?->toArray())->toMatchArray(['status' => 'ok', 'addresses' => ['203.0.113.7'], 'expected' => ['203.0.113.7']]);

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
    expect($domain->refresh()->verification?->status)->toBe(DnsCheckStatus::Missing);

    FakeDns::set('shop.example.com', ['198.51.100.9'], ['old-host.example.net']);
    $verify();
    expect($domain->refresh()->verification?->toArray())->toMatchArray([
        'status' => 'mismatch',
        'cname' => 'old-host.example.net',
        'message' => 'shop.example.com points at 198.51.100.9, not at your server (203.0.113.7).',
    ]);

    FakeDns::set('shop.example.com', ['203.0.113.7', '198.51.100.9']);
    $verify();
    expect($domain->refresh()->verification?->status)->toBe(DnsCheckStatus::Mismatch)
        ->and($domain->verification?->message)->toContain('also points at 198.51.100.9');

    FakeDns::fail('shop.example.com');
    $verify();
    expect($domain->refresh()->verification?->status)->toBe(DnsCheckStatus::Error);
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

test('custom domains may point at the generated hostname under the platform domain', function (): void {
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
            ->where('panel.settings.domains.0.apex', false)
            // A zone apex gets the same target for ALIAS/ANAME/flattening; the UI keeps A records first.
            ->where('panel.settings.domains.1.hostname', 'example.net')
            ->where('panel.settings.domains.1.cnameTarget', $generated)
            ->where('panel.settings.domains.1.apex', true));
});

test('DNS checks the Data cast can\'t read are dropped by its migration', function (): void {
    $instanceId = $this->web->instances()->sole()->id;
    $migration = require database_path('migrations/2026_12_30_210000_type_json_columns.php');
    $migration->down();

    $never = Domain::query()->create(['team_id' => $this->team->id, 'service_instance_id' => $instanceId, 'hostname' => 'never.example.com', 'kind' => DomainKind::Custom]);
    $old = Domain::query()->create(['team_id' => $this->team->id, 'service_instance_id' => $instanceId, 'hostname' => 'old.example.com', 'kind' => DomainKind::Custom]);
    $ok = Domain::query()->create(['team_id' => $this->team->id, 'service_instance_id' => $instanceId, 'hostname' => 'ok.example.com', 'kind' => DomainKind::Custom]);
    DB::table('domains')->where('id', $old->id)->update(['verification' => json_encode(['status' => 'pending', 'checkedAt' => '2026-11-01T00:00:00Z'])]);
    DB::table('domains')->where('id', $ok->id)->update(['verification' => json_encode(['status' => 'ok', 'hostname' => 'ok.example.com', 'addresses' => ['203.0.113.7'], 'expected' => ['203.0.113.7'], 'cname' => null, 'message' => 'ok', 'checkedAt' => '2026-11-01T00:00:00Z'])]);

    expect(DB::table('domains')->where('id', $never->id)->value('verification'))->toBe('{}');

    $migration->up();

    expect($never->refresh()->verification)->toBeNull()
        ->and($old->refresh()->verification)->toBeNull()
        ->and($ok->refresh()->verification?->status)->toBe(DnsCheckStatus::Ok);
});

test('the periodic check queues domains that are due', function (): void {
    $instanceId = $this->web->instances()->sole()->id;
    $create = fn (string $host, ?DnsCheckStatus $status = null, ?CarbonInterface $checkedAt = null) => Domain::query()->create(['team_id' => $this->team->id, 'service_instance_id' => $instanceId, 'hostname' => $host, 'kind' => DomainKind::Custom])->forceFill([
        'verification' => $status instanceof DnsCheckStatus ? new DnsCheckData($status, $host, [], [], null, '', (string) $checkedAt?->toIso8601ZuluString()) : null,
    ])->save();

    $create('never.example.com');
    $create('pending-old.example.com', DnsCheckStatus::Missing, now()->subMinutes(10));
    $create('pending-new.example.com', DnsCheckStatus::Missing, now()->subMinute());
    $create('ok-old.example.com', DnsCheckStatus::Ok, now()->subHours(7));
    $create('ok-new.example.com', DnsCheckStatus::Ok, now()->subHour());

    Queue::fake();
    $this->artisan('launchroom:domains:verify')->assertSuccessful();

    $queued = collect(Queue::pushed(VerifyDomainJob::class))->map(fn (VerifyDomainJob $job): string => Domain::query()->whereKey($job->domainId)->value('hostname'))->sort()->values()->all();
    expect($queued)->toBe(['never.example.com', 'ok-old.example.com', 'pending-old.example.com']);
});
