<?php

declare(strict_types=1);

use App\Services\Sessions\SessionFailures;

// Why a session couldn't start, in one sentence (docs/03 §11.4).

test('start failures read as one sentence', function (string $error, string $expected): void {
    expect(SessionFailures::sentence($error))->toBe($expected);
})->with([
    'no shell' => ['this image has no shell (looked for /bin/bash, /bin/ash and /bin/sh)', 'This image has no shell (looked for bash, ash and sh), so there is no console for it.'],
    'picked shell' => ['this image has no /bin/bash', 'This image has no /bin/bash. Pick Automatic or another shell.'],
    'guard' => ['starting the session: docker: exec create: forbidden: dockerd-guard: console sessions are off on this server (LR_INTERACTIVE_SESSIONS)', 'The server refused to open it (dockerd-guard): console sessions are off on this server (LR_INTERACTIVE_SESSIONS)'],
    'local policy' => ['console sessions are turned off on this server (LR_INTERACTIVE_SESSIONS)', 'The server refused: sessions of this kind are turned off there (LR_INTERACTIVE_SESSIONS).'],
    'stopped' => ['the container of wl_x is not running', 'The container isn’t running right now. Try again once it is back.'],
    'relay' => ['session: connect to relay: dial tcp: i/o timeout', 'The server couldn’t reach the live connection (Reverb): session: connect to relay: dial tcp: i/o timeout'],
    'exit 127' => ['/bin/ash couldn\'t start in the container (exit code 127)', 'The shell couldn’t start in this container: /bin/ash couldn\'t start in the container (exit code 127)'],
    'anything else' => ['docker: stat: boom', 'The server couldn’t start the session: docker: stat: boom'],
]);

test('no error, no sentence; long errors are clipped', function (): void {
    expect(SessionFailures::sentence(null))->toBeNull()
        ->and(SessionFailures::sentence('  '))->toBeNull()
        ->and(mb_strlen((string) SessionFailures::sentence(str_repeat('x', 2000))))->toBeLessThan(400);
});
