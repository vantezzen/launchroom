<?php

declare(strict_types=1);

use App\Data\Deployments\ContainerExitData;
use App\Support\Deployments\ExitMeaning;

test('137 is out of memory only with the OOM flag', function (): void {
    expect(ExitMeaning::of(137, oomKilled: true))->toBe('it ran out of memory')
        ->and(ExitMeaning::of(137))->toBe('it was killed (SIGKILL)')
        ->and(ExitMeaning::of(137, byLaunchroom: true))->toBe('it was stopped by launchroom after its stop timeout')
        ->and(ExitMeaning::of(137))->not->toContain('memory')
        ->and(ExitMeaning::advice(137))->toContain('couldn’t confirm')
        ->and(ExitMeaning::advice(137, byLaunchroom: true))->toContain('Stop timeout');
});

test('exit codes and signals are worded', function (?int $code, ?int $signal, bool $byLaunchroom, ?string $meaning): void {
    expect(ExitMeaning::of($code, false, $signal, $byLaunchroom))->toBe($meaning);
})->with([
    'clean' => [0, null, false, null],
    'unknown' => [null, null, false, null],
    'error' => [1, null, false, 'it exited with an error'],
    'command not found' => [127, null, false, 'the start command wasn’t found'],
    'not executable' => [126, null, false, 'the start command couldn’t be run'],
    'segfault' => [139, null, false, 'it crashed (segmentation fault)'],
    'sigterm' => [143, null, false, 'it was asked to stop (SIGTERM)'],
    'sigterm by launchroom' => [143, null, true, 'it was stopped by launchroom'],
    'signal from launchroomd wins' => [1, 11, false, 'it crashed (segmentation fault)'],
    'abort' => [134, null, false, 'it aborted (SIGABRT)'],
    'other signal' => [129, null, false, 'it was ended by SIGHUP'],
]);

test('notable meanings leave out a plain error', function (): void {
    expect(ExitMeaning::notable(1))->toBeNull()
        ->and(ExitMeaning::notable(2))->toBeNull()
        ->and(ExitMeaning::notable(137))->toBe('it was killed (SIGKILL)');
});

test('summaries read as a sentence', function (): void {
    expect(ExitMeaning::summary(137, oomKilled: true))->toBe('Exited with code 137: it ran out of memory.')
        ->and(ExitMeaning::summary(0))->toBe('Exited with code 0.')
        ->and(ExitMeaning::summaryOfExit(new ContainerExitData(at: '2026-11-17T10:00:00Z', code: 137, signal: 9, byLaunchroom: true)))
        ->toBe('Exited with code 137: it was stopped by launchroom after its stop timeout.');
});
