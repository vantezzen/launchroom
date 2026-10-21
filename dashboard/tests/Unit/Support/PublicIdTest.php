<?php

declare(strict_types=1);

use App\Support\PublicId;
use Illuminate\Support\Sleep;

test('generates prefixed ULIDs', function (): void {
    $id = PublicId::generate('prj');

    expect($id)
        ->toStartWith('prj_')
        ->toHaveLength(4 + 26)
        ->and(PublicId::isValid($id))->toBeTrue()
        ->and(PublicId::isValid($id, 'prj'))->toBeTrue()
        ->and(PublicId::isValid($id, 'svc'))->toBeFalse()
        ->and(PublicId::prefixOf($id))->toBe('prj');
});

test('generated ids are unique and sortable by creation time', function (): void {
    $ids = array_map(fn (): string => PublicId::generate('dpl'), range(1, 50));

    expect(array_unique($ids))->toHaveCount(50);

    $first = PublicId::generate('dpl');
    Sleep::usleep(2000);
    $second = PublicId::generate('dpl');

    expect(strcmp($first, $second))->toBeLessThan(0);
});

test('rejects malformed ids', function (string $value): void {
    expect(PublicId::isValid($value))->toBeFalse()
        ->and(PublicId::prefixOf($value))->toBeNull();
})->with([
    'empty' => '',
    'no prefix' => '01JB7Z6X9Q3T8M5N2P4R6S8V0W',
    'uppercase prefix' => 'PRJ_01JB7Z6X9Q3T8M5N2P4R6S8V0W',
    'lowercase ulid' => 'prj_01jb7z6x9q3t8m5n2p4r6s8v0w',
    'too short' => 'prj_01JB7Z6X9Q3T8M5N2P4R6S8V0',
    'too long' => 'prj_01JB7Z6X9Q3T8M5N2P4R6S8V0WX',
    'excluded letter I' => 'prj_01JB7Z6X9Q3T8M5N2P4R6S8V0I',
    'overflowing first char' => 'prj_81JB7Z6X9Q3T8M5N2P4R6S8V0W',
    'trailing newline' => "prj_01JB7Z6X9Q3T8M5N2P4R6S8V0W\n",
    'injection' => "prj_01JB7Z6X9Q3T8M5N2P4R6S8V0W'; --",
    'path traversal' => '../prj_01JB7Z6X9Q3T8M5N2P4R6S8V0W',
    'numeric id' => '42',
]);

test('validation regex and route pattern match generated ids', function (): void {
    $id = PublicId::generate('srv');

    expect(preg_match(PublicId::regex('srv'), $id))->toBe(1)
        ->and(preg_match(PublicId::regex('srv'), PublicId::generate('prj')))->toBe(0)
        ->and(preg_match('#^'.PublicId::routePattern('srv').'$#', $id))->toBe(1);
});

test('rejects invalid prefixes', function (string $prefix): void {
    PublicId::generate($prefix);
})->with(['', 'p', 'toolong', 'PRJ', 'pr_j', 'pr1'])->throws(InvalidArgumentException::class);
