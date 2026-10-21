<?php

declare(strict_types=1);

use App\Support\GeneratedHostname;
use App\Support\Hostname;
use App\Support\Slug;

test('generated hostnames follow docs/05 §5', function (): void {
    expect(GeneratedHostname::localhost('web', 'production', 'shop'))->toBe('web-production.shop.localhost')
        ->and(GeneratedHostname::wildcard('web', 'staging', 'shop', 'Apps.Example.com'))->toBe('web-staging.shop.apps.example.com')
        ->and(GeneratedHostname::sslip('web', 'production', 'shop', '203.0.113.7'))->toBe('web-production-shop.203-0-113-7.sslip.io');
});

test('long labels are truncated with a stable hash suffix', function (): void {
    $label = Hostname::label(str_repeat('a', 40), str_repeat('b', 40));

    expect(strlen($label))->toBeLessThanOrEqual(63)
        ->and($label)->toBe(Hostname::label(str_repeat('a', 40), str_repeat('b', 40)))
        ->and(Hostname::isValid($label.'.example.com'))->toBeTrue();
});

test('validates hostnames like Let\'s Encrypt does', function (string $hostname, bool $valid): void {
    expect(Hostname::isValid($hostname))->toBe($valid);
})->with([
    ['shop.example.com', true],
    ['xn--bcher-kva.example', true],
    ['under_score.example.com', false],
    ['-dash.example.com', false],
    ['example', false],
    [str_repeat('a', 64).'.com', false],
    ['UPPER.example.com', false],
]);

test('normalizes case, trailing dots and IDNs', function (): void {
    expect(Hostname::normalize(' Shop.Example.COM. '))->toBe('shop.example.com')
        ->and(Hostname::normalize('bücher.example'))->toBe('xn--bcher-kva.example');
});

test('slugs are DNS-safe and unique', function (): void {
    expect(Slug::from('My Cool API!'))->toBe('my-cool-api')
        ->and(Slug::from('!!!', 'app'))->toBe('app')
        ->and(Slug::unique('api', ['api', 'api-2']))->toBe('api-3')
        ->and(Slug::isValid('api-'))->toBeFalse()
        ->and(Slug::isValid(Slug::from(str_repeat('x', 100))))->toBeTrue();
});
