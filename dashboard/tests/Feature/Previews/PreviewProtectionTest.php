<?php

declare(strict_types=1);

use App\Actions\Previews\RevokeShareLink;
use App\Actions\Servers\RenderNodeState;
use App\Data\Audit\AuditActor;
use App\Enums\TeamRole;
use App\Models\Deployment;
use App\Models\InstanceSettings;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewSettings;
use App\Models\Previews\PreviewShareLink;
use App\Models\Team;
use App\Services\Previews\PreviewAccess;
use App\Services\Previews\PreviewUrls;
use App\Support\Previews\PreviewToken;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Support\PreviewFlow;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;

const PP_HOST = 'web-pr-7.shop.localhost';

beforeEach(function (): void {
    PreviewFlow::setUp();
    config(['launchroom.previews.auth_url' => 'http://dashboard:8000/auth/preview', 'app.url' => 'http://localhost:8000', 'launchroom.previews.https_port' => 8443]);
    [$this->team, $this->server, , $this->project] = PreviewFlow::project();
    $this->preview = PreviewFlow::event($this->project);
    PreviewFlow::run($this->server);
    $this->environment = $this->preview->refresh()->environment;
});

/**
 * Calls the forward-auth endpoint like the edge Caddy does.
 *
 * @param  array<string, string>  $headers
 */
function ppCheck(string $uri = '/dashboard?x=1', string $host = PP_HOST.':8443', array $headers = [], ?string $cookie = null, string $method = 'GET'): TestResponse
{
    $test = test();

    if ($cookie !== null) {
        $test->withUnencryptedCookie(config()->string('launchroom.previews.cookie'), $cookie);
    }

    return $test->withHeaders(['X-Forwarded-Host' => $host, 'X-Forwarded-Uri' => $uri, 'X-Forwarded-Method' => $method, ...$headers])->get('/auth/preview');
}

function ppKey(): string
{
    return hash_hmac('sha256', 'launchroom:preview-auth:v1', config()->string('app.key'), true);
}

/**
 * The cookie value from a 302 that sets it.
 */
function ppCookie(TestResponse $response): string
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === config()->string('launchroom.previews.cookie')) {
            return (string) $cookie->getValue();
        }
    }

    throw new RuntimeException('no preview cookie');
}

function ppToken(TestResponse $response): string
{
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    return (string) $query[PreviewAccess::TOKEN_PARAMETER];
}

test('protected preview routes forward-auth to the dashboard and are never indexed', function (): void {
    $routes = collect(Projects::nodeState($this->server)['routes'])->keyBy(static fn (array $route): string => $route['hosts'][0]);
    $route = $routes[PP_HOST];

    expect($route['protection'])->toBe(['forwardAuth' => ['url' => 'http://dashboard:8000/auth/preview', 'stripHeaders' => ['X-Launchroom-Bypass']]])
        ->and($route['headers'])->toBe(['X-Robots-Tag' => 'noindex'])
        // The branch alias joins the route and redirects to the PR URL.
        ->and($route['hosts'])->toBe([PP_HOST, 'web-git-feature-login.shop.localhost'])
        ->and($route['redirectWwwTo'])->toBe(PP_HOST)
        ->and(ProtocolSchemas::errors('node-state', Projects::latestDocument($this->server)))->toBe([]);

    // The production route stays unprotected.
    expect($routes['web-production.shop.localhost'])->not->toHaveKey('protection');

    // Turning protection off removes it.
    PreviewSettings::forProject($this->project)->forceFill(['protection' => false])->save();
    resolve(RenderNodeState::class)->handle($this->server);
    expect(collect(Projects::nodeState($this->server)['routes'])->firstWhere('hosts.0', PP_HOST))->not->toHaveKey('protection');
});

test('immutable per-deployment URLs route to that deployment, protected for previews, on-demand TLS elsewhere', function (): void {
    $routes = collect(Projects::nodeState($this->server)['routes'])->keyBy(static fn (array $route): string => $route['hosts'][0]);
    $deployment = Deployment::query()->where('environment_id', $this->environment->id)->whereHas('serviceInstance.service', static fn (Builder $query) => $query->where('slug', 'web'))->latest('id')->sole();
    $host = 'web-'.PreviewUrls::shortId($deployment).'.shop.localhost';

    expect($routes)->toHaveKey($host)
        ->and($routes[$host]['id'])->toBe('rt_'.substr($deployment->public_id, 4))
        ->and($routes[$host]['protection']['forwardAuth']['url'])->toBe('http://dashboard:8000/auth/preview')
        ->and($routes[$host]['headers'])->toBe(['X-Robots-Tag' => 'noindex'])
        ->and(resolve(PreviewUrls::class)->resolve($host)['deployment']?->id)->toBe($deployment->id);

    config(['launchroom.domains.localhost' => false]);
    InstanceSettings::current()->forceFill(['apps_domain' => 'apps.example.com'])->save();
    resolve(RenderNodeState::class)->handle($this->server);
    $production = Deployment::query()->whereHas('environment', static fn (Builder $query) => $query->where('kind', 'production'))->whereHas('serviceInstance.service', static fn (Builder $query) => $query->where('slug', 'web'))->latest('id')->sole();
    $route = collect(Projects::nodeState($this->server)['routes'])->firstWhere('hosts.0', 'web-'.PreviewUrls::shortId($production).'.shop.apps.example.com');

    expect($route['tls'])->toBe('on_demand')
        ->and($route)->not->toHaveKey('protection');
});

test('unauthenticated visitors are sent to the dashboard to sign in; other methods get 401', function (): void {
    $response = ppCheck();

    $response->assertStatus(302)->assertHeader('Cache-Control', 'no-store, private');
    expect($response->headers->get('Location'))->toBe('http://localhost:8000/auth/preview/start?host='.urlencode(PP_HOST.':8443').'&path='.urlencode('/dashboard?x=1'));

    ppCheck(method: 'POST')->assertStatus(401);
    ppCheck(host: 'nope.shop.localhost')->assertStatus(403);
    ppCheck(host: 'web-production.shop.localhost')->assertOk();
});

test('denials are a small HTML page for browsers and a plain line for other clients', function (): void {
    $plain = ppCheck(host: 'nope.shop.localhost', headers: ['Accept' => '*/*']);
    $plain->assertStatus(403)->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    expect($plain->getContent())->toBe("launchroom: this preview no longer exists.\n");

    $html = ppCheck(host: 'nope.shop.localhost', headers: ['Accept' => 'text/html,application/xhtml+xml']);
    $html->assertStatus(403)->assertHeader('Content-Type', 'text/html; charset=utf-8')->assertHeader('Cache-Control', 'no-store, private');
    $body = (string) $html->getContent();
    expect($body)->toContain('This preview doesn’t exist')
        ->not->toContain('<script')
        ->not->toContain('http://')
        ->not->toContain('https://')
        ->toContain('nope.shop.localhost')
        ->and(strlen($body))->toBeLessThan(4096);

    ppCheck(headers: ['Accept' => 'text/html'], method: 'POST')->assertStatus(401)->assertSee('Sign in to view this preview');
});

test('members exchange a single-use token for a cookie on the preview host', function (): void {
    $member = memberOf($this->team, TeamRole::Viewer);

    $start = $this->actingAs($member)->get('/auth/preview/start?host='.urlencode(PP_HOST.':8443').'&path='.urlencode('/dashboard?x=1'));
    $start->assertRedirect();
    $location = (string) $start->headers->get('Location');

    expect($location)->toStartWith('https://'.PP_HOST.':8443/dashboard?x=1&__lr_token=v1.');

    auth()->logout();
    $token = ppToken($start);
    $exchanged = ppCheck('/dashboard?x=1&'.PreviewAccess::TOKEN_PARAMETER.'='.urlencode($token));

    $exchanged->assertStatus(302);
    expect($exchanged->headers->get('Location'))->toBe('https://'.PP_HOST.':8443/dashboard?x=1');
    $setCookie = collect($exchanged->headers->getCookies())->first(static fn ($cookie): bool => $cookie->getName() === '__Host-lr-preview');
    expect($setCookie->isSecure())->toBeTrue()
        ->and($setCookie->isHttpOnly())->toBeTrue()
        ->and($setCookie->getDomain())->toBeNull()
        ->and($setCookie->getPath())->toBe('/')
        ->and($setCookie->getSameSite())->toBe('lax');

    $cookie = ppCookie($exchanged);
    ppCheck(cookie: $cookie)->assertOk();

    // Single use.
    ppCheck('/?'.PreviewAccess::TOKEN_PARAMETER.'='.urlencode($token))->assertStatus(403);
    // The cookie is bound to its host.
    ppCheck(host: 'web-git-feature-login.shop.localhost', cookie: $cookie)->assertStatus(302);

    // Losing the membership ends access (after the short cache).
    $member->memberships()->delete();
    Cache::flush();
    ppCheck(cookie: $cookie)->assertStatus(302);
});

test('the start endpoint only serves previews the user may view (no open redirect, no cross-team access)', function (): void {
    $stranger = memberOf(Team::factory()->create(), TeamRole::Owner);

    $this->actingAs($stranger)->get('/auth/preview/start?host='.urlencode(PP_HOST.':8443').'&path=/')->assertForbidden();
    $this->actingAs($stranger)->get('/auth/preview/start?host=evil.example.com&path=/')->assertNotFound();

    $member = memberOf($this->team, TeamRole::Viewer);
    $this->actingAs($member)->get('/auth/preview/start?host=web-production.shop.localhost:8443&path=/')->assertNotFound();
    // Tokens only go to the edges' public HTTPS port (the Host header, and so the port, is client-controlled).
    $this->actingAs($member)->get('/auth/preview/start?host='.urlencode(PP_HOST.':2019').'&path=/')->assertNotFound();
    $this->actingAs($member)->get('/auth/preview/start?host='.urlencode(PP_HOST).'&path=/')->assertNotFound();
    $redirect = $this->actingAs($member)->get('/auth/preview/start?host='.urlencode(PP_HOST.':8443').'&path='.urlencode('//evil.example.com/x'));
    expect($redirect->headers->get('Location'))->toStartWith('https://'.PP_HOST.':8443/?__lr_token=');

    auth()->logout();
    $this->get('/auth/preview/start?host='.urlencode(PP_HOST.':8443').'&path=/')->assertRedirect('/login');
});

test('forged, expired, wrong-kind and other-team tokens are refused', function (): void {
    $now = now()->getTimestamp();
    $env = $this->environment->public_id;
    $user = memberOf($this->team, TeamRole::Owner);

    $forged = new PreviewToken(PreviewToken::KIND_COOKIE, $env, PP_HOST, $now + 3600, userId: $user->id)->sign('not-the-key');
    $expired = new PreviewToken(PreviewToken::KIND_COOKIE, $env, PP_HOST, $now - 1, userId: $user->id)->sign(ppKey());
    // An exchange token where a cookie is expected.
    $wrongKind = new PreviewToken(PreviewToken::KIND_EXCHANGE, $env, PP_HOST, $now + 60, userId: $user->id, nonce: 'n')->sign(ppKey());
    // Signed for another environment.
    $otherEnv = new PreviewToken(PreviewToken::KIND_COOKIE, 'env_01JB7Z6X9Q3T8M5N2P4R6S8V0W', PP_HOST, $now + 3600, userId: $user->id)->sign(ppKey());
    // A user of another team.
    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);
    $crossTeam = new PreviewToken(PreviewToken::KIND_COOKIE, $env, PP_HOST, $now + 3600, userId: $outsider->id)->sign(ppKey());
    $valid = new PreviewToken(PreviewToken::KIND_COOKIE, $env, PP_HOST, $now + 3600, userId: $user->id)->sign(ppKey());
    // Tampered claims keep the old MAC.
    [$version, $payload, $mac] = explode('.', $valid);
    $tampered = $version.'.'.rtrim(strtr(base64_encode(str_replace(PP_HOST, 'x'.substr(PP_HOST, 1), base64_decode(strtr($payload, '-_', '+/')))), '+/', '-_'), '=').'.'.$mac;

    foreach ([$forged, $expired, $wrongKind, $otherEnv, $crossTeam, $tampered, 'garbage', str_repeat('a', 5000)] as $cookie) {
        ppCheck(cookie: $cookie)->assertStatus(302);
    }

    ppCheck(cookie: $valid)->assertOk();

    // Exchange tokens: forged / expired / for another host.
    $exchange = static fn (string $host, int $exp, string $key = ''): string => new PreviewToken(PreviewToken::KIND_EXCHANGE, $env, $host, $exp, userId: $user->id, nonce: bin2hex(random_bytes(8)))->sign($key === '' ? ppKey() : $key);
    ppCheck('/?__lr_token='.urlencode($exchange(PP_HOST, $now + 60, 'bad')))->assertStatus(403);
    ppCheck('/?__lr_token='.urlencode($exchange(PP_HOST, $now - 5)))->assertStatus(403);
    ppCheck('/?__lr_token='.urlencode($exchange('web-git-feature-login.shop.localhost', $now + 60)))->assertStatus(403);
    ppCheck('/?__lr_token='.urlencode($exchange(PP_HOST, $now + 60)))->assertStatus(302);
});

test('share links grant access until they expire or are revoked', function (): void {
    $developer = memberOf($this->team, TeamRole::Developer);
    $this->actingAs($developer)->post(route('projects.previews.share-links.store', [$this->project, $this->preview]), ['label' => 'Client', 'days' => 3])->assertRedirect();
    $link = PreviewShareLink::query()->sole();
    auth()->logout();

    $token = resolve(PreviewAccess::class)->shareToken($link, $this->environment);
    $response = ppCheck('/pricing?'.PreviewAccess::TOKEN_PARAMETER.'='.urlencode($token));
    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toBe('https://'.PP_HOST.':8443/pricing');
    $cookie = ppCookie($response);

    ppCheck(cookie: $cookie)->assertOk();
    // Share tokens are multi-use and work on every host of the preview.
    ppCheck('/?'.PreviewAccess::TOKEN_PARAMETER.'='.urlencode($token), host: 'web-git-feature-login.shop.localhost')->assertStatus(302);
    expect($link->refresh()->last_used_at)->not->toBeNull();

    resolve(RevokeShareLink::class)->handle($link, AuditActor::system());
    ppCheck(cookie: $cookie)->assertStatus(302);
    ppCheck('/?'.PreviewAccess::TOKEN_PARAMETER.'='.urlencode($token))->assertStatus(403);

    // Expired links don't work either.
    $expired = PreviewShareLink::query()->create(['team_id' => $this->team->id, 'preview_id' => $this->preview->id, 'expires_at' => now()->addMinute()]);
    $token = resolve(PreviewAccess::class)->shareToken($expired, $this->environment);
    $this->travel(2)->minutes();
    ppCheck('/?'.PreviewAccess::TOKEN_PARAMETER.'='.urlencode($token))->assertStatus(403);
});

test('viewers can’t create share links', function (): void {
    $viewer = memberOf($this->team, TeamRole::Viewer);

    $this->actingAs($viewer)->post(route('projects.previews.share-links.store', [$this->project, $this->preview]), ['days' => 3])->assertForbidden();
});

test('the CI bypass header passes with the project’s secret only', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $response = $this->actingAs($owner)->post(route('projects.previews.bypass', $this->project));
    $response->assertRedirect();
    $settings = PreviewSettings::forProject($this->project);
    $secret = (string) resolve(PreviewAccess::class)->bypassSecret($this->project, $settings);
    auth()->logout();

    expect($secret)->toStartWith('lrb_')
        ->and(json_encode($settings->toArray()))->not->toContain($settings->bypass_nonce);

    ppCheck(headers: ['X-Launchroom-Bypass' => $secret])->assertOk();
    ppCheck(headers: ['X-Launchroom-Bypass' => $secret.'x'])->assertStatus(302);
    ppCheck(headers: ['X-Launchroom-Bypass' => ''])->assertStatus(302);

    // Another project's secret doesn't work here.
    [, , , $other] = PreviewFlow::project();
    PreviewSettings::forProject($other)->forceFill(['bypass_nonce' => 'other'])->save();
    ppCheck(headers: ['X-Launchroom-Bypass' => (string) resolve(PreviewAccess::class)->bypassSecret($other, PreviewSettings::forProject($other))])->assertStatus(302);
});

test('previews without protection pass', function (): void {
    PreviewSettings::forProject($this->project)->forceFill(['protection' => false])->save();

    ppCheck()->assertOk();
    expect(Preview::query()->count())->toBe(1);
});
