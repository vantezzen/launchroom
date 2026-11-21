<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Routing\RouteFamily;
use App\Models\Environment;
use App\Models\Team;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Projects;

/*
| Dashboard error pages (docs/08 §24): every page error renders `errors/show`,
| in the shell when signed in, without telling another team's rows apart
| from missing ones.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    config()->set('app.debug', false);

    $this->team = Team::factory()->create();
    $this->member = memberOf($this->team, TeamRole::Developer);
    $this->project = Projects::create($this->team, 'Shop');
    $this->environment = $this->project->production()->sole();

    $this->otherTeam = Team::factory()->create();
    $this->outsider = memberOf($this->otherTeam, TeamRole::Owner);
    $this->foreign = Projects::create($this->otherTeam, 'Secret plans');

    // Statuses no dashboard route answers on demand.
    Route::middleware('web')->group(function (): void {
        Route::get('__errors/crash', static fn () => throw new RuntimeException('database password is hunter2'));
        Route::post('__errors/expired', static fn () => throw new TokenMismatchException);
        Route::get('__errors/busy', static fn () => abort(429, '', ['Retry-After' => '30']));
        Route::get('__errors/maintenance', static fn () => abort(503, '', ['Retry-After' => '60']));
    });
});

/** A well-formed public id of the same kind that no row has. */
function unusedId(string $publicId): string
{
    return substr($publicId, 0, -4).(str_ends_with($publicId, 'ZZZZ') ? 'YYYY' : 'ZZZZ');
}

test('an unknown page is a 404 page inside the shell', function (): void {
    $this->actingAs($this->member)
        ->get('/nowhere-to-be-found')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('errors/show')
            ->where('error.status', 404)
            ->where('error.kind', 'not_found')
            ->where('error.path', '/nowhere-to-be-found')
            ->where('error.missing', null)
            ->where('error.suggestions', [])
            ->where('auth.user.email', $this->member->email)
            ->has('teams'));
});

test('signed out, an unknown page is a 404 page without a user', function (): void {
    $this->get('/nowhere-to-be-found')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('errors/show')
            ->where('error.kind', 'not_found')
            ->where('auth.user', null));
});

test('a missing project suggests the user\'s own projects', function (): void {
    $this->actingAs($this->member)
        ->get('/p/'.unusedId($this->project->public_id))
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('errors/show')
            ->where('error.missing', 'project')
            ->where('error.suggestions', [['label' => 'Shop', 'href' => '/p/'.$this->project->public_id, 'detail' => null]]));
});

test('another team\'s project looks exactly like one that doesn\'t exist', function (): void {
    $missing = $this->actingAs($this->member)->get('/p/'.unusedId($this->foreign->public_id))->assertNotFound();
    $foreign = $this->actingAs($this->member)->get('/p/'.$this->foreign->public_id)->assertNotFound();

    $props = static fn ($response): array => collect($response->inertiaProps('error'))->except('path')->all();

    expect($props($foreign))->toBe($props($missing))
        ->and($foreign->getContent())->not->toContain('Secret plans')
        ->and($foreign->getContent())->not->toContain($this->otherTeam->name);
});

test('a missing service suggests the environment\'s services, a missing environment the project\'s', function (): void {
    Projects::image($this->project, 'web');
    Projects::image($this->project, 'api');
    $base = '/p/'.$this->project->public_id.'/'.$this->environment->public_id;

    $this->actingAs($this->member)
        ->get($base.'/s/'.unusedId(Projects::image($this->project, 'worker')->public_id))
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('error.missing', 'service')
            ->where('error.suggestions.0.label', 'api')
            ->where('error.suggestions.1.label', 'web')
            ->where('error.suggestions.2.label', 'worker')
            ->where('error.suggestions.0.detail', $this->environment->name));

    $this->actingAs($this->member)
        ->get('/p/'.$this->project->public_id.'/'.unusedId($this->environment->public_id))
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('error.missing', 'environment')
            ->where('error.suggestions.0.label', $this->environment->name));
});

test('another team\'s service in your own environment\'s path is a plain missing service', function (): void {
    $foreignService = Projects::image($this->foreign, 'billing');
    $foreignEnv = $this->foreign->production()->sole();

    $this->actingAs($this->member)
        ->get('/p/'.$this->project->public_id.'/'.$this->environment->public_id.'/s/'.$foreignService->public_id)
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('error.missing', 'service')->where('error.suggestions', []));

    // Their environment under your project: missing environment, your project's environments only.
    $this->actingAs($this->member)
        ->get('/p/'.$this->project->public_id.'/'.$foreignEnv->public_id)
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('error.missing', 'environment')
            ->where('error.suggestions', fn ($suggestions): bool => collect($suggestions)->every(
                fn (array $item): bool => Environment::query()->where('project_id', $this->project->id)->where('name', $item['label'])->exists(),
            )));
});

test('a refused page is a 403 page that names nothing', function (): void {
    $viewer = memberOf($this->team, TeamRole::Viewer);

    $this->actingAs($viewer)
        ->get('/logs')
        ->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('errors/show')
            ->where('error.kind', 'forbidden')
            ->where('error.suggestions', [])
            ->where('error.reference', null));
});

test('an expired session offers to reload the page the form was on', function (): void {
    $this->actingAs($this->member)
        ->withHeader('Referer', url('/settings/profile?tab=name'))
        ->post('/__errors/expired')
        ->assertStatus(419)
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('errors/show')
            ->where('error.kind', 'expired')
            ->where('error.reloadTo', '/settings/profile?tab=name'));
});

test('another site\'s Referer is never a reload target', function (): void {
    $this->post('/__errors/expired', [], ['Referer' => 'https://evil.example/phish'])
        ->assertStatus(419)
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('error.reloadTo', '/'));
});

test('a crash shows a reference that its log entry carries, never the message', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->context;
    });

    $response = $this->actingAs($this->member)->get('/__errors/crash')->assertStatus(500);
    $reference = $response->inertiaProps('error.reference');

    expect($reference)->toBeString()->and(Str::isUuid($reference))->toBeTrue()
        ->and($response->getContent())->not->toContain('hunter2')
        ->and($response->getContent())->not->toContain('RuntimeException')
        ->and(collect($logged)->pluck('reference')->all())->toContain($reference);
    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('error.kind', 'server_error'));
});

test('the reference is the edge\'s request id when it set a valid one', function (): void {
    $id = '0b5e8f9e-3c1d-4f8a-9a51-6d2c4e7b1a90';

    $this->withHeader('X-Request-Id', $id)->get('/__errors/crash')
        ->assertStatus(500)
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('error.reference', $id));

    $this->withHeader('X-Request-Id', '<script>')->get('/__errors/crash')
        ->assertStatus(500)
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('error.reference', fn (string $reference): bool => Str::isUuid($reference)));
});

test('with APP_DEBUG a crash keeps the debug page', function (): void {
    config()->set('app.debug', true);

    $response = $this->get('/__errors/crash')->assertStatus(500);

    expect($response->headers->get('X-Inertia'))->toBeNull()
        ->and($response->getContent())->toContain('RuntimeException');
});

test('rate limits and maintenance say how long to wait', function (): void {
    $this->get('/__errors/busy')
        ->assertStatus(429)
        ->assertHeader('Retry-After', '30')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('error.kind', 'too_many_requests')->where('error.retryAfter', 30));

    $this->get('/__errors/maintenance')
        ->assertStatus(503)
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('error.kind', 'unavailable')->where('error.retryAfter', 60));
});

test('JSON requests, the API and launchroomd keep their own error answers', function (): void {
    $this->actingAs($this->member)->getJson('/p/'.$this->foreign->public_id)
        ->assertNotFound()
        ->assertJsonMissingPath('component');

    $this->getJson('/api/v1/nowhere')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $daemon = $this->get('/daemon/v1/nowhere');
    expect($daemon->status())->toBe(404)
        ->and($daemon->headers->get('X-Inertia'))->toBeNull()
        ->and($daemon->getContent())->not->toContain('errors/show')
        ->and($daemon->headers->getCookies())->toBe([]);
});

test('an Inertia visit swaps in the error page, a failed post keeps the page it came from', function (): void {
    $version = resolve(HandleInertiaRequests::class)->version(request());
    $headers = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => (string) $version];

    $this->actingAs($this->member)
        ->get('/p/'.unusedId($this->project->public_id), $headers)
        ->assertNotFound()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'errors/show');

    $this->actingAs($this->member)
        ->post('/__errors/expired', [], [...$headers, 'Referer' => url('/settings/profile')])
        ->assertStatus(419)
        ->assertJsonPath('component', 'errors/show')
        ->assertJsonPath('url', '/settings/profile');
});

test('the fallback route leaves every machine-facing family unmatched', function (string $path, bool $matches): void {
    expect(preg_match('#^'.RouteFamily::browserFallbackPattern().'$#', $path))->toBe($matches ? 1 : 0);
})->with([
    ['nowhere', true],
    ['p/prj_x/settings', true],
    ['upgrade', true],
    ['api/v1/projects', false],
    ['daemon/v1/report', false],
    ['move/v1/state', false],
    ['up', false],
    ['up/version', false],
    ['build/assets/app.js', false],
    ['webhooks/github', false],
    ['auth/preview', false],
    ['cli/login', false],
]);

test('a form posted to an unknown path is a 404, not a 405', function (): void {
    $this->post('/nowhere/at/all', ['x' => 1])->assertNotFound();
    $this->delete('/settings/nowhere')->assertNotFound();
});
