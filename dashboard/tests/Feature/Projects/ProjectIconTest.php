<?php

declare(strict_types=1);

use App\Actions\Projects\RefreshProjectIcon;
use App\Contracts\DnsResolver;
use App\Enums\AuditAction;
use App\Enums\DeploymentStatus;
use App\Enums\DomainKind;
use App\Enums\ProjectIconMode;
use App\Enums\TeamRole;
use App\Events\DeploymentStatusChanged;
use App\Jobs\Projects\FetchProjectIconJob;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Project;
use App\Models\Team;
use App\Services\Projects\ProjectIconStore;
use App\Support\Images\Png;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

/*
| Project icons (docs/08 §13, docs/09 R32): favicons fetched from the
| production domain through SafeHttpClient, uploads, and the authorized
| serving route.
*/

function iconPng(string $extra = ''): string
{
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return Png::SIGNATURE
        .$chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0))
        .$extra
        .$chunk('IDAT', (string) gzcompress("\x00\x11\x22\x33\xFF"))
        .$chunk('IEND', '');
}

/**
 * @param  array<string, list<string>>  $records
 */
function iconDns(array $records): void
{
    app()->instance(DnsResolver::class, new readonly class($records) implements DnsResolver
    {
        /** @param array<string, list<string>> $records */
        public function __construct(private array $records) {}

        public function resolve(string $hostname): array
        {
            return $this->records[$hostname] ?? [];
        }
    });
}

function iconDomain(Project $project, string $hostname): Domain
{
    $instance = $project->services()->where('slug', 'web')->sole()->instances()->sole();

    return Domain::query()->create(['team_id' => $project->team_id, 'service_instance_id' => $instance->id, 'hostname' => $hostname, 'kind' => DomainKind::Custom]);
}

beforeEach(function (): void {
    Projects::useTestKek();
    Storage::fake(ProjectIconStore::DISK);
    Http::preventStrayRequests();
    $this->world = DeploymentUi::make(deploy: false);
    $this->project = $this->world->project;
});

test('the favicon of the production domain is fetched, re-encoded and stored', function (): void {
    iconDomain($this->project, 'shop.example.com');
    iconDns(['shop.example.com' => ['93.184.216.34']]);
    Http::fake([
        'https://shop.example.com/' => Http::response('<html><head><link rel="icon" href="/static/icon.png" sizes="64x64"></head></html>', 200, ['Content-Type' => 'text/html']),
        'https://shop.example.com/static/icon.png' => Http::response(iconPng(pack('N', 4).'tEXt'.'evil'.pack('N', crc32('tEXtevil'))), 200, ['Content-Type' => 'text/html']),
    ]);

    expect(resolve(RefreshProjectIcon::class)->handle($this->project))->toBeTrue();

    $project = $this->project->refresh();
    expect($project->icon_mode)->toBe(ProjectIconMode::Automatic)
        ->and($project->icon_type)->toBe('png')
        ->and($project->icon_source)->toBe('https://shop.example.com/static/icon.png')
        ->and($project->icon_path)->toBe("project-icons/{$project->public_id}/{$project->icon_version}.png")
        ->and(Storage::disk(ProjectIconStore::DISK)->get((string) $project->icon_path))->not->toContain('evil');
});

test('without icon links it falls back to /favicon.ico and follows a few redirects', function (): void {
    iconDomain($this->project, 'shop.example.com');
    iconDns(['shop.example.com' => ['93.184.216.34'], 'www.shop.example.com' => ['93.184.216.35']]);
    Http::fake([
        'https://shop.example.com/' => Http::response('', 301, ['Location' => 'https://www.shop.example.com/']),
        'https://www.shop.example.com/' => Http::response('<html><head><title>Shop</title></head></html>', 200, ['Content-Type' => 'text/html; charset=utf-8']),
        'https://www.shop.example.com/favicon.ico' => Http::response(iconPng()),
    ]);

    expect(resolve(RefreshProjectIcon::class)->handle($this->project))->toBeTrue()
        ->and($this->project->refresh()->icon_source)->toBe('https://www.shop.example.com/favicon.ico');
});

test('SSRF: private, loopback, link-local and mesh addresses are never requested', function (string $ip): void {
    iconDomain($this->project, 'shop.example.com');
    iconDns(['shop.example.com' => [$ip]]);
    Http::fake();

    expect(resolve(RefreshProjectIcon::class)->handle($this->project))->toBeFalse();

    Http::assertNothingSent();
    expect($this->project->refresh()->icon_path)->toBeNull()
        ->and($this->project->icon_checked_at)->not->toBeNull();
})->with([
    'private' => '10.0.0.5',
    'loopback' => '127.0.0.1',
    'metadata' => '169.254.169.254',
    'mesh (CGNAT)' => '100.64.0.7',
    'ULA' => 'fd7a:115c:a1e0::1',
    'IPv6 loopback' => '::1',
]);

test('SSRF: icon links and redirects to internal hosts or other schemes are not followed', function (): void {
    iconDomain($this->project, 'shop.example.com');
    iconDns(['shop.example.com' => ['93.184.216.34'], 'internal.example.com' => ['192.168.1.10']]);
    Http::fake([
        'https://shop.example.com/' => Http::response(
            '<html><head><link rel="icon" href="https://internal.example.com/icon.png"><link rel="icon" href="http://169.254.169.254/latest/meta-data/"><link rel="icon" href="file:///etc/passwd"><link rel="icon" href="/redirect.png"></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
        'https://shop.example.com/redirect.png' => Http::response('', 302, ['Location' => 'http://127.0.0.1:8000/admin']),
        'https://shop.example.com/favicon.ico' => Http::response('', 302, ['Location' => 'gopher://shop.example.com/']),
    ]);

    expect(resolve(RefreshProjectIcon::class)->handle($this->project))->toBeFalse();

    Http::assertSentCount(3);
    Http::assertNotSent(fn (Request $request): bool => ! str_starts_with($request->url(), 'https://shop.example.com/'));
});

test('SSRF: redirect chains stop after three hops', function (): void {
    iconDomain($this->project, 'shop.example.com');
    iconDns(['shop.example.com' => ['93.184.216.34']]);
    Http::fake(fn (Request $request) => Http::response('', 302, ['Location' => $request->url().'x']));

    expect(resolve(RefreshProjectIcon::class)->handle($this->project))->toBeFalse();

    // Page (https + http) and favicon: one request plus three redirects each.
    Http::assertSentCount(12);
});

test('oversized, non-image and unsupported favicons are ignored', function (): void {
    iconDomain($this->project, 'shop.example.com');
    iconDns(['shop.example.com' => ['93.184.216.34']]);
    Http::fake([
        'https://shop.example.com/' => Http::response('<link rel="icon" href="/big.png"><link rel="icon" href="/page.png"><link rel="icon" href="/anim.gif">', 200, ['Content-Type' => 'text/html']),
        'https://shop.example.com/big.png' => Http::response(str_repeat('a', 300 * 1024)),
        'https://shop.example.com/page.png' => Http::response('<html><script>alert(1)</script></html>', 200, ['Content-Type' => 'image/png']),
        'https://shop.example.com/anim.gif' => Http::response("GIF89a\x01\x00\x01\x00\x00\x00\x00;"),
        'https://shop.example.com/favicon.ico' => Http::response('', 404),
    ]);

    expect(resolve(RefreshProjectIcon::class)->handle($this->project))->toBeFalse()
        ->and($this->project->refresh()->icon_path)->toBeNull();
});

test('a project without a production domain is not fetched; uploads are never overwritten', function (): void {
    Http::fake();

    expect(resolve(RefreshProjectIcon::class)->handle($this->project))->toBeFalse();
    Http::assertNothingSent();

    $this->project->forceFill(['icon_mode' => ProjectIconMode::Uploaded])->save();
    iconDomain($this->project, 'shop.example.com');
    expect(resolve(RefreshProjectIcon::class)->handle($this->project))->toBeFalse();
    Http::assertNothingSent();
});

test('a production deployment going live queues a lookup, at most daily', function (): void {
    Bus::fake([FetchProjectIconJob::class]);
    $this->world->deploy();
    $deployment = $this->world->latest($this->world->web);

    Bus::assertDispatched(FetchProjectIconJob::class, fn (FetchProjectIconJob $job): bool => $job->projectId === $this->project->id);

    Bus::fake([FetchProjectIconJob::class]);
    $this->project->forceFill(['icon_checked_at' => now()])->save();
    event(new DeploymentStatusChanged($deployment->id, DeploymentStatus::Routing, DeploymentStatus::Active));
    Bus::assertNotDispatched(FetchProjectIconJob::class);

    $this->project->forceFill(['icon_checked_at' => now()->subDays(2), 'icon_mode' => ProjectIconMode::None])->save();
    event(new DeploymentStatusChanged($deployment->id, DeploymentStatus::Routing, DeploymentStatus::Active));
    Bus::assertNotDispatched(FetchProjectIconJob::class);
});

test('developers upload an icon; it is re-encoded and served with a strict content type', function (): void {
    $developer = $this->world->member(TeamRole::Developer);
    $viewer = $this->world->member(TeamRole::Viewer);
    $file = UploadedFile::fake()->createWithContent('logo.html', iconPng());

    $this->actingAs($viewer)->post(route('projects.icon.store', $this->project), ['icon' => $file])->assertForbidden();
    $this->actingAs($developer)->post(route('projects.icon.store', $this->project), ['icon' => $file])->assertRedirect()->assertSessionHasNoErrors();

    $project = $this->project->refresh();
    expect($project->icon_mode)->toBe(ProjectIconMode::Uploaded)
        ->and($project->iconUrl())->toBe("/p/{$project->public_id}/icon?v={$project->icon_version}")
        ->and(AuditEvent::query()->where('action', AuditAction::ProjectIconChanged->value)->exists())->toBeTrue();

    $this->actingAs($viewer)->get((string) $project->iconUrl())
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');

    // The icon is in the shell props, the switcher and the projects list
    // (page renders may try the dev SSR server; it isn't what we test).
    Http::preventStrayRequests(false);
    $this->actingAs($viewer)->get(route('projects.settings', ['project' => $project]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('project.iconUrl', $project->iconUrl())
            ->where('projects.0.iconUrl', $project->iconUrl())
            ->where('icon.mode', 'uploaded'));
    $this->actingAs($viewer)->get(route('projects.index'))
        ->assertInertia(fn ($page) => $page->where('projects.0.iconUrl', $project->iconUrl()));
});

test('uploaded SVGs are sanitized; other files are refused with a reason', function (): void {
    $developer = $this->world->member(TeamRole::Developer);
    $svg = UploadedFile::fake()->createWithContent('i.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1" onload="alert(1)"><script>alert(2)</script><rect width="1" height="1"/></svg>');

    $this->actingAs($developer)->post(route('projects.icon.store', $this->project), ['icon' => $svg])->assertSessionHasNoErrors();
    $stored = Storage::disk(ProjectIconStore::DISK)->get((string) $this->project->refresh()->icon_path);
    expect($stored)->toContain('<rect')->not->toContain('alert');
    $this->actingAs($developer)->get((string) $this->project->iconUrl())->assertHeader('Content-Type', 'image/svg+xml');

    $this->actingAs($developer)->post(route('projects.icon.store', $this->project), ['icon' => UploadedFile::fake()->createWithContent('x.png', '<html></html>')])
        ->assertSessionHasErrors(['icon' => 'Use a PNG, ICO, SVG or WebP image.']);
    $this->actingAs($developer)->post(route('projects.icon.store', $this->project), ['icon' => UploadedFile::fake()->create('big.png', 300)])
        ->assertSessionHasErrors('icon');
});

test('resetting goes back to automatic (and looks it up) or to no icon', function (): void {
    Bus::fake([FetchProjectIconJob::class]);
    $developer = $this->world->member(TeamRole::Developer);
    $this->actingAs($developer)->post(route('projects.icon.store', $this->project), ['icon' => UploadedFile::fake()->createWithContent('i.png', iconPng())]);
    $url = (string) $this->project->refresh()->iconUrl();

    $this->actingAs($developer)->delete(route('projects.icon.destroy', $this->project), ['mode' => 'uploaded'])->assertSessionHasErrors('mode');
    $this->actingAs($developer)->delete(route('projects.icon.destroy', $this->project), ['mode' => 'none'])->assertRedirect();

    expect($this->project->refresh()->icon_mode)->toBe(ProjectIconMode::None)
        ->and($this->project->iconUrl())->toBeNull()
        ->and(Storage::disk(ProjectIconStore::DISK)->allFiles())->toBe([]);
    $this->actingAs($developer)->get($url)->assertNotFound();
    Bus::assertNotDispatched(FetchProjectIconJob::class);

    $this->actingAs($developer)->delete(route('projects.icon.destroy', $this->project), ['mode' => 'automatic'])->assertRedirect();
    expect($this->project->refresh()->icon_mode)->toBe(ProjectIconMode::Automatic);
    Bus::assertDispatched(FetchProjectIconJob::class);
});

test('other teams can neither see nor change the icon', function (): void {
    $developer = $this->world->member(TeamRole::Developer);
    $this->actingAs($developer)->post(route('projects.icon.store', $this->project), ['icon' => UploadedFile::fake()->createWithContent('i.png', iconPng())]);
    $url = (string) $this->project->refresh()->iconUrl();

    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);

    $this->actingAs($outsider)->get($url)->assertNotFound();
    $this->actingAs($outsider)->post(route('projects.icon.store', $this->project), ['icon' => UploadedFile::fake()->createWithContent('i.png', iconPng())])->assertNotFound();
    $this->actingAs($outsider)->delete(route('projects.icon.destroy', $this->project), ['mode' => 'none'])->assertNotFound();
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
});

test('deleting the project removes its icon files', function (): void {
    $owner = $this->world->member(TeamRole::Owner);
    $this->actingAs($owner)->post(route('projects.icon.store', $this->project), ['icon' => UploadedFile::fake()->createWithContent('i.png', iconPng())]);
    expect(Storage::disk(ProjectIconStore::DISK)->allFiles())->toHaveCount(1);

    $this->actingAs($owner)->withSession(passwordConfirmed())->delete(route('projects.destroy', $this->project), ['confirmName' => 'shop']);

    expect(Project::query()->whereKey($this->project->id)->exists())->toBeFalse()
        ->and(Storage::disk(ProjectIconStore::DISK)->allFiles())->toBe([])
        ->and(Deployment::query()->count())->toBe(0);
});
