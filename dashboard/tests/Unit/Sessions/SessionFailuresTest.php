<?php

declare(strict_types=1);

use App\Enums\Sessions\StartFailure;
use App\Services\Sessions\SessionFailures;

// Why a session couldn't start, in one sentence (docs/03 §11.4), by launchroomd's failure code.

test('start failures read as one sentence', function (?StartFailure $failure, string $error, ?string $shell, string $expected): void {
    expect(SessionFailures::sentence($failure, $error, $shell))->toBe($expected);
})->with([
    'no shell' => [StartFailure::NoShell, 'this image has no shell (looked for /bin/bash, /bin/ash and /bin/sh)', null, 'This image has no shell (looked for bash, ash and sh), so there is no console for it.'],
    'picked shell' => [StartFailure::ShellMissing, 'this image has no /bin/bash', '/bin/bash', 'This image has no /bin/bash. Pick Automatic or another shell.'],
    'guard' => [StartFailure::GuardRefused, 'starting the session: docker: exec create: forbidden: dockerd-guard: console sessions are off on this server (LR_INTERACTIVE_SESSIONS)', null, 'The server refused to open it (dockerd-guard): console sessions are off on this server (LR_INTERACTIVE_SESSIONS)'],
    'local policy' => [StartFailure::KindDisabled, 'console sessions are turned off on this server (LR_INTERACTIVE_SESSIONS)', null, 'The server refused: sessions of this kind are turned off there (LR_INTERACTIVE_SESSIONS).'],
    'stopped' => [StartFailure::NotRunning, 'the container of wl_x is not running', null, 'The container isn’t running right now. Try again once it is back.'],
    'relay' => [StartFailure::RelayUnreachable, 'session: connect to relay: dial tcp: i/o timeout', null, 'The server couldn’t reach the live connection (Reverb): session: connect to relay: dial tcp: i/o timeout'],
    'exit 127' => [StartFailure::ShellStartFailed, '/bin/ash couldn\'t start in the container (exit code 127)', '/bin/ash', 'The shell couldn’t start in this container: /bin/ash couldn\'t start in the container (exit code 127)'],
    'session key' => [StartFailure::SessionKey, 'session key: not 32 bytes of base64url', null, 'The server couldn’t open the session key. Update it in Settings → Updates.'],
    'reworded error, same code' => [StartFailure::NotRunning, 'workload stopped', null, 'The container isn’t running right now. Try again once it is back.'],
    'no code (older launchroomd)' => [null, 'this image has no shell', null, 'The server couldn’t start the session: this image has no shell'],
]);

test('no failure, no sentence; long errors are clipped', function (): void {
    expect(SessionFailures::sentence(null, null))->toBeNull()
        ->and(SessionFailures::sentence(null, '  '))->toBeNull()
        ->and(mb_strlen((string) SessionFailures::sentence(null, str_repeat('x', 2000))))->toBeLessThan(400);
});
