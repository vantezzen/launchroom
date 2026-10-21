<?php

declare(strict_types=1);

use App\Enums\Previews\PreviewStatus;
use App\Support\Previews\PreviewToken;

const PT_KEY = 'k1-0123456789abcdef0123456789abcdef';
const PT_ENV = 'env_01JB7Z6X9Q3T8M5N2P4R6S8V0W';

test('tokens round-trip and bind kind, environment, host and expiry', function (): void {
    $token = new PreviewToken(PreviewToken::KIND_COOKIE, PT_ENV, 'web-pr-1.example.com', 2000, userId: 5)->sign(PT_KEY);
    $verified = PreviewToken::verify($token, PreviewToken::KIND_COOKIE, PT_KEY, 1000);

    expect($verified?->environment)->toBe(PT_ENV)
        ->and($verified?->host)->toBe('web-pr-1.example.com')
        ->and($verified?->userId)->toBe(5)
        ->and($verified?->shareLink)->toBeNull()
        ->and(PreviewToken::verify($token, PreviewToken::KIND_SHARE, PT_KEY, 1000))->toBeNull()
        ->and(PreviewToken::verify($token, PreviewToken::KIND_COOKIE, 'other-key', 1000))->toBeNull()
        ->and(PreviewToken::verify($token, PreviewToken::KIND_COOKIE, PT_KEY, 2000))->toBeNull();
});

test('malformed and tampered tokens fail closed', function (string $token): void {
    expect(PreviewToken::verify($token, PreviewToken::KIND_COOKIE, PT_KEY, 1000))->toBeNull();
})->with([
    'empty' => [''],
    'two parts' => ['v1.abc'],
    'other version' => ['v2.eyJrIjoiY29va2llIn0.AAAA'],
    'bad base64' => ['v1.***.***'],
    'mac of other payload' => [(function (): string {
        $a = explode('.', new PreviewToken(PreviewToken::KIND_COOKIE, PT_ENV, 'a.example.com', 2000, userId: 1)->sign(PT_KEY));
        $b = explode('.', new PreviewToken(PreviewToken::KIND_COOKIE, PT_ENV, 'b.example.com', 2000, userId: 1)->sign(PT_KEY));

        return $a[0].'.'.$b[1].'.'.$a[2];
    })()],
    'oversized' => [str_repeat('v', 3000)],
]);

test('preview statuses: open ones move freely, closed ones only reopen', function (): void {
    expect(PreviewStatus::Running->canTransitionTo(PreviewStatus::Closed))->toBeTrue()
        ->and(PreviewStatus::Queued->canTransitionTo(PreviewStatus::Preparing))->toBeTrue()
        ->and(PreviewStatus::Closed->canTransitionTo(PreviewStatus::Running))->toBeFalse()
        ->and(PreviewStatus::Closed->canTransitionTo(PreviewStatus::Queued))->toBeTrue()
        ->and(PreviewStatus::Expired->isOpen())->toBeFalse();
});
