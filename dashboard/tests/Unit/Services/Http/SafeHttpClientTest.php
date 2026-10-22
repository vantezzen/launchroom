<?php

declare(strict_types=1);

use App\Contracts\DnsResolver;
use App\Services\Http\Exceptions\ResponseTooLarge;
use App\Services\Http\Exceptions\UnsafeUrl;
use App\Services\Http\ResolvedTarget;
use App\Services\Http\SafeHttpClient;
use App\Services\Http\UrlGuard;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function safeClient(int $maxBytes = 1024): SafeHttpClient
{
    $resolver = new class implements DnsResolver
    {
        public function resolve(string $hostname): array
        {
            return match ($hostname) {
                'example.com' => ['93.184.216.34'],
                'internal.example.com' => ['10.0.0.1'],
                default => [],
            };
        }
    };

    return new SafeHttpClient(
        http: resolve(Factory::class),
        guard: new UrlGuard($resolver),
        connectTimeoutSeconds: 3,
        timeoutSeconds: 7,
        maxResponseBytes: $maxBytes,
    );
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('sends requests to vetted URLs', function (): void {
    Http::fake(['https://example.com/*' => Http::response(['ok' => true])]);

    $response = safeClient()->get('https://example.com/hook', ['a' => 'b'], ['X-Test' => '1']);

    expect($response->json('ok'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example.com/hook?a=b'
        && $request->hasHeader('X-Test', '1')
        && $request->method() === 'GET');
});

test('keeps the query string of the URL when no query is passed', function (): void {
    Http::fake(['https://example.com/*' => Http::response('ok')]);

    // Signed storage URLs (GitHub release assets) carry their signature here.
    safeClient()->get('https://example.com/asset?sig=abc&se=2026');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example.com/asset?sig=abc&se=2026');
});

test('posts JSON to vetted URLs', function (): void {
    Http::fake(['https://example.com/*' => Http::response(status: 204)]);

    safeClient()->postJson('https://example.com/hook', ['event' => 'deployed']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['event'] === 'deployed');
});

test('never sends a request to an unsafe URL', function (string $url): void {
    Http::fake();

    try {
        safeClient()->get($url);
        $this->fail('Expected UnsafeUrl');
    } catch (UnsafeUrl) {
        Http::assertNothingSent();
    }
})->with([
    'https://internal.example.com/',
    'https://169.254.169.254/latest/meta-data/',
    'http://example.com/',
    'https://[::ffff:10.0.0.1]/',
]);

test('rejects oversized responses', function (): void {
    Http::fake(['https://example.com/*' => Http::response(str_repeat('a', 2048))]);

    safeClient(maxBytes: 1024)->get('https://example.com/big');
})->throws(ResponseTooLarge::class);

test('transport options pin the IP, disable redirects and proxies, and set limits', function (): void {
    $target = new ResolvedTarget('https://example.com/', 'https', 'example.com', 443, '93.184.216.34');

    $options = safeClient()->transportOptions($target);

    expect($options['allow_redirects'])->toBeFalse()
        ->and($options['proxy'])->toBe('')
        ->and($options['connect_timeout'])->toBe(3)
        ->and($options['timeout'])->toBe(7)
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['example.com:443:93.184.216.34'])
        ->and($options['curl'][CURLOPT_PROTOCOLS])->toBe(CURLPROTO_HTTPS)
        ->and($options['curl'][CURLOPT_FOLLOWLOCATION])->toBeFalse()
        ->and($options['on_headers'])->toBeCallable()
        ->and($options['progress'])->toBeCallable();
});

test('the progress callback aborts downloads over the cap', function (): void {
    $target = new ResolvedTarget('https://example.com/', 'https', 'example.com', 443, '93.184.216.34');

    $options = safeClient(maxBytes: 100)->transportOptions($target);

    ($options['progress'])(0, 100);
    ($options['progress'])(0, 101);
})->throws(ResponseTooLarge::class);

test('the container builds the client from config', function (): void {
    expect(resolve(SafeHttpClient::class))->toBeInstanceOf(SafeHttpClient::class);
});

test('a trailing-dot host is requested under the same name it is pinned to (L11)', function (): void {
    Http::fake(['https://example.com/*' => Http::response(['ok' => true])]);

    safeClient()->get('https://example.com./hook');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example.com/hook');
});
