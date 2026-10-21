<?php

declare(strict_types=1);

use App\Support\Network\SubnetAllocator;

test('allocates the lowest free /24 of the range', function (): void {
    expect(SubnetAllocator::next('10.231.0.0/16', 24, []))->toBe('10.231.0.0/24')
        ->and(SubnetAllocator::next('10.231.0.0/16', 24, ['10.231.0.0/24', '10.231.2.0/24']))->toBe('10.231.1.0/24');
});

test('returns null when the range is exhausted', function (): void {
    $used = array_map(static fn (int $i): string => "10.231.{$i}.0/24", range(0, 255));

    expect(SubnetAllocator::next('10.231.0.0/16', 24, $used))->toBeNull();
});

test('normalizes a range with host bits and supports other sizes', function (): void {
    expect(SubnetAllocator::next('192.168.10.77/24', 26, ['192.168.10.0/26']))->toBe('192.168.10.64/26');
});

test('rejects invalid ranges and prefixes', function (string $range, int $prefix): void {
    expect(fn (): ?string => SubnetAllocator::next($range, $prefix, []))->toThrow(InvalidArgumentException::class);
})->with([
    ['not-a-cidr', 24],
    ['10.0.0.0/33', 24],
    ['10.231.0.0/16', 8],
    ['10.231.0.0/16', 31],
]);

test('every allocation is unique (property)', function (): void {
    $used = [];

    for ($i = 0; $i < 256; $i++) {
        $subnet = SubnetAllocator::next('10.231.0.0/16', 24, $used);
        expect($subnet)->not->toBeNull()->and(in_array($subnet, $used, true))->toBeFalse();
        $used[] = (string) $subnet;
    }
});
