<?php

declare(strict_types=1);

use App\Services\GitHub\WebhookSignature;

$body = '{"zen":"Keep it logically awesome.","hook_id":1}';

test('accepts the HMAC of the raw body', function () use ($body): void {
    $header = 'sha256='.hash_hmac('sha256', $body, 'secret');

    expect(WebhookSignature::isValid($body, $header, 'secret'))->toBeTrue()
        ->and(WebhookSignature::isValid($body, 'SHA256='.substr($header, 7), 'secret'))->toBeFalse()
        ->and(WebhookSignature::isValid($body, 'sha256='.strtoupper(hash_hmac('sha256', $body, 'secret')), 'secret'))->toBeTrue();
});

test('fails closed', function (?string $header, ?string $secret): void {
    expect(WebhookSignature::isValid('{}', $header, $secret))->toBeFalse();
})->with([
    'missing header' => [null, 'secret'],
    'empty header' => ['', 'secret'],
    'missing secret' => ['sha256='.hash_hmac('sha256', '{}', ''), null],
    'empty secret (Coolify CVE: HMAC with an empty key)' => ['sha256='.hash_hmac('sha256', '{}', ''), ''],
    'sha1 header' => ['sha1='.hash_hmac('sha1', '{}', 'secret'), 'secret'],
    'truncated' => ['sha256='.substr(hash_hmac('sha256', '{}', 'secret'), 0, 63), 'secret'],
    'trailing junk' => ['sha256='.hash_hmac('sha256', '{}', 'secret')."\n", 'secret'],
    'wrong secret' => ['sha256='.hash_hmac('sha256', '{}', 'other'), 'secret'],
]);

test('the signature covers the exact bytes, not re-encoded JSON', function (): void {
    $raw = '{"message":"café \/ ok","n":1.0}';
    $reencoded = json_encode(json_decode($raw, true));

    expect($reencoded)->not->toBe($raw)
        ->and(WebhookSignature::isValid($raw, 'sha256='.hash_hmac('sha256', $raw, 's'), 's'))->toBeTrue()
        ->and(WebhookSignature::isValid($raw, 'sha256='.hash_hmac('sha256', (string) $reencoded, 's'), 's'))->toBeFalse();
});
