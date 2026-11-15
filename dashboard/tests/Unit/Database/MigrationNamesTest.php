<?php

declare(strict_types=1);

/*
 * Laravel runs migrations in file-name order and records them by name, so the
 * timestamp prefix is the order: it must be well-formed and unique. Renaming
 * a released migration re-runs it on every existing install, so the two
 * released pairs that share a timestamp stay as they are (their order within
 * the pair is the rest of the name, which never changes).
 */
const RELEASED_SHARED_TIMESTAMPS = ['2026_10_21_100000', '2026_10_31_400000'];

test('migration names are a timestamp and a snake_case name', function (): void {
    $bad = array_filter(
        array_map(basename(...), glob(database_path('migrations/*.php')) ?: []),
        static fn (string $name): bool => preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.php$/D', $name) !== 1,
    );

    expect($bad)->toBe([]);
});

test('every migration has its own timestamp', function (): void {
    $counts = array_count_values(array_map(
        static fn (string $path): string => substr(basename($path), 0, 17),
        glob(database_path('migrations/*.php')) ?: [],
    ));
    $shared = array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));

    expect(array_values(array_diff($shared, RELEASED_SHARED_TIMESTAMPS)))->toBe([])
        ->and(array_map(static fn (string $timestamp): int => $counts[$timestamp] ?? 0, RELEASED_SHARED_TIMESTAMPS))->toBe([2, 2]);
});
