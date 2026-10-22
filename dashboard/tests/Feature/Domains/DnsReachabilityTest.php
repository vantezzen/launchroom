<?php

declare(strict_types=1);

use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use App\Services\Dns\DnsCheck;
use App\Services\Dns\EdgeReachability;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDns;

const CLOUDFLARE_PROXY_IPS = ['172.67.185.103', '104.21.76.25', '2606:4700:3030::6815:4c19'];

/**
 * What launchroomd answers on /.well-known/launchroom/check/<nonce>: the
 * request's host and nonce signed with the server's identity key.
 */
function reachabilityAnswer(Request $request, string $seed, string $serverId): array
{
    $url = parse_url($request->url());
    $nonce = substr((string) ($url['path'] ?? ''), strlen(EdgeReachability::PATH));
    $message = EdgeReachability::MESSAGE_PREFIX.($url['host'] ?? '')."\n".$nonce;
    $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));

    return ['serverId' => $serverId, 'signature' => base64_encode(sodium_crypto_sign_detached($message, $secret))];
}

beforeEach(function (): void {
    config(['launchroom.dns.reachability' => true]);
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->owner->forceFill(['is_instance_owner' => true])->save();
    $this->seed = random_bytes(32);
    $this->server = Server::factory()->withSigningSeed($this->seed)->online()->create([
        'team_id' => $this->team->id,
        'roles' => [ServerRole::Edge, ServerRole::Runtime, ServerRole::Build],
        'is_control_plane' => true,
        'public_ipv4' => '93.184.216.34',
    ]);
});

function checkWildcard(mixed $test): array
{
    return $test->actingAs($test->owner)->postJson(route('onboarding.domain.check'), ['domain' => 'apps.example.com'])->assertOk()->json();
}

test('a proxied wildcard (Cloudflare) is OK once the HTTP check reaches our server', function (): void {
    FakeDns::set('*.apps.example.com', CLOUDFLARE_PROXY_IPS);
    Http::fake(fn (Request $request) => Http::response(reachabilityAnswer($request, $this->seed, $this->server->public_id)));

    $result = checkWildcard($this);

    expect($result['status'])->toBe('ok')
        ->and($result['proxy'])->toBe('Cloudflare')
        ->and($result['reachedOverHttp'])->toBeTrue()
        ->and($result['message'])->toContain('Cloudflare’s proxy')
        ->and($result['message'])->toContain($this->server->name);

    Http::assertSent(fn (Request $request): bool => preg_match('#^http://lr-check-[0-9a-f]{8}\.apps\.example\.com/\.well-known/launchroom/check/[A-Za-z0-9_-]{32}$#', $request->url()) === 1
        && $request->method() === 'GET');
});

test('every check uses a fresh nonce', function (): void {
    FakeDns::set('*.apps.example.com', CLOUDFLARE_PROXY_IPS);
    Http::fake(fn (Request $request) => Http::response(reachabilityAnswer($request, $this->seed, $this->server->public_id)));

    checkWildcard($this);
    checkWildcard($this);

    $paths = Http::recorded()->map(fn (array $pair): string => (string) parse_url($pair[0]->url(), PHP_URL_PATH))->unique();
    expect($paths)->toHaveCount(2);
});

test('a proxied record that does not reach us is reported as behind a proxy, not confirmed', function (callable $answer, string $reason): void {
    FakeDns::set('*.apps.example.com', CLOUDFLARE_PROXY_IPS);
    Http::fake(fn (Request $request) => $answer($request, $this));

    $result = checkWildcard($this);

    expect($result['status'])->toBe('proxied')
        ->and($result['proxy'])->toBe('Cloudflare')
        ->and($result['reachedOverHttp'])->toBeFalse()
        ->and($result['message'])->toContain($reason);
})->with([
    'an older server without the check' => [fn () => Http::response('launchroom: there is no app at this address.', 404), 'HTTP 404'],
    'the proxy cannot reach the origin' => [fn () => Http::response('', 522), 'could not reach your server (HTTP 522)'],
    'someone else\'s key' => [fn (Request $request, $test) => Http::response(reachabilityAnswer($request, random_bytes(32), $test->server->public_id)), 'did not come from one of your servers'],
    'a replayed answer for another nonce' => [fn (Request $request, $test) => Http::response(['serverId' => $test->server->public_id, 'signature' => base64_encode(str_repeat('a', 64))]), 'did not come from one of your servers'],
    'not JSON' => [fn () => Http::response('<html>hello</html>'), 'not launchroom’s check'],
    'a redirect elsewhere' => [fn () => Http::response('', 301, ['Location' => 'https://evil.example.net/']), 'redirected elsewhere'],
]);

test('a revoked server’s signature does not count', function (): void {
    FakeDns::set('*.apps.example.com', CLOUDFLARE_PROXY_IPS);
    $this->server->forceFill(['revoked_at' => now(), 'status' => ServerStatus::Revoked])->save();
    Http::fake(fn (Request $request) => Http::response(reachabilityAnswer($request, $this->seed, $this->server->public_id)));

    expect(checkWildcard($this)['status'])->toBe('proxied');
});

test('the one redirect to the same URL over https is followed', function (): void {
    FakeDns::set('*.apps.example.com', CLOUDFLARE_PROXY_IPS);
    Http::fake(fn (Request $request) => str_starts_with($request->url(), 'http://')
        ? Http::response('', 301, ['Location' => 'https://'.substr($request->url(), 7)])
        : Http::response(reachabilityAnswer($request, $this->seed, $this->server->public_id)));

    expect(checkWildcard($this))->toMatchArray(['status' => 'ok', 'proxy' => 'Cloudflare']);
    Http::assertSentCount(2);
});

test('records pointing elsewhere but forwarding to us (NAT, an unknown proxy) pass over HTTP', function (): void {
    FakeDns::set('*.apps.example.com', ['80.80.80.80']);
    Http::fake(fn (Request $request) => Http::response(reachabilityAnswer($request, $this->seed, $this->server->public_id)));

    expect(checkWildcard($this))->toMatchArray(['status' => 'ok', 'proxy' => null, 'reachedOverHttp' => true]);
});

test('without an HTTP answer, a foreign record stays a mismatch', function (): void {
    FakeDns::set('*.apps.example.com', ['80.80.80.80']);
    Http::fake(fn () => Http::response('nope', 404));

    expect(checkWildcard($this))->toMatchArray(['status' => 'mismatch', 'proxy' => null, 'reachedOverHttp' => false]);
});

test('the HTTP check never connects to private or reserved addresses (SSRF, docs/09 R5)', function (): void {
    FakeDns::set('*.apps.example.com', ['10.0.0.5']);
    Http::fake();

    expect(checkWildcard($this)['status'])->toBe('mismatch');
    Http::assertNothingSent();
});

test('records that already point at our server need no HTTP check', function (): void {
    FakeDns::set('*.apps.example.com', ['93.184.216.34']);
    Http::fake();

    expect(checkWildcard($this))->toMatchArray(['status' => 'ok', 'reachedOverHttp' => false]);
    Http::assertNothingSent();
});

test('a record that points partly at us and partly elsewhere stays a mismatch', function (): void {
    FakeDns::set('*.apps.example.com', ['93.184.216.34', '172.67.185.103']);
    Http::fake();

    expect(checkWildcard($this)['status'])->toBe('mismatch');
    Http::assertNothingSent();
});

test('custom domains get the same proxy verdict', function (): void {
    FakeDns::set('shop.example.com', CLOUDFLARE_PROXY_IPS);
    Http::fake(fn (Request $request) => Http::response(reachabilityAnswer($request, $this->seed, $this->server->public_id)));

    $result = resolve(DnsCheck::class)->hostname('shop.example.com', ['93.184.216.34']);

    expect($result->status)->toBe('ok')->and($result->proxy)->toBe('Cloudflare');
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://shop.example.com/.well-known/launchroom/check/'));
});
