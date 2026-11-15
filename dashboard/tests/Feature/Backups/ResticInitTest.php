<?php

declare(strict_types=1);

use App\Services\Backups\DestinationCredentials;
use App\Services\Backups\Process\ResticCli;
use App\Services\Backups\Process\ResticTarget;

/**
 * A stand-in restic: `cat config` exits 10 (no repository) until the
 * repository exists, or for the first $missingChecks calls; `init` creates
 * it. Every call is appended to calls.log.
 *
 * @return array{string, ResticCli}
 */
function fakeRestic(int $missingChecks = PHP_INT_MAX): array
{
    $dir = sys_get_temp_dir().'/lr-restic-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    resticDirs($dir);
    $script = <<<SH
        #!/bin/sh
        echo "\$*" >> {$dir}/calls.log
        if [ "\$1" = cat ]; then
            n=\$(grep -c '^cat' {$dir}/calls.log)
            [ -f {$dir}/repo ] && exit 0
            [ "\$n" -gt {$missingChecks} ] && exit 0
            echo "Fatal: repository does not exist" >&2
            exit 10
        fi
        if [ "\$1" = init ]; then touch {$dir}/repo; exit 0; fi
        exit 1
        SH;
    file_put_contents($dir.'/restic', $script);
    chmod($dir.'/restic', 0700);

    return [$dir, new ResticCli($dir.'/restic', $dir.'/cache', 60)];
}

function resticTarget(): ResticTarget
{
    return new ResticTarget('s3:https://s3.example.com/bucket', null, new DestinationCredentials('AKIA', 'secret', 'repository-password-0123456789'));
}

/** @return list<string> */
function resticCalls(string $dir): array
{
    return array_values(array_filter(explode("\n", (string) file_get_contents($dir.'/calls.log'))));
}

/**
 * The directories this test created (parallel workers share the temp dir,
 * so cleanup never globs it). Called without an argument it hands them over.
 *
 * @return list<string>
 */
function resticDirs(?string $add = null): array
{
    static $dirs = [];

    if ($add !== null) {
        $dirs[] = $add;

        return $dirs;
    }

    [$taken, $dirs] = [$dirs, []];

    return $taken;
}

afterEach(function (): void {
    foreach (resticDirs() as $dir) {
        removeResticDir($dir);
    }
});

function removeResticDir(string $dir): void
{
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        is_dir($dir.'/'.$entry) ? removeResticDir($dir.'/'.$entry) : unlink($dir.'/'.$entry);
    }

    rmdir($dir);
}

it('initialises a missing repository once and then opens it', function (): void {
    [$dir, $restic] = fakeRestic();

    expect($restic->openOrInit(resticTarget()))->toBe('initialized')
        ->and($restic->openOrInit(resticTarget()))->toBe('existing')
        ->and(resticCalls($dir))->toBe(['cat config', 'cat config', 'init', 'cat config']);
});

it('re-checks under the lock and does not init a repository someone else just created', function (): void {
    // The first check misses; by the time the lock is held another process
    // has initialised it (the second check sees the config).
    [$dir, $restic] = fakeRestic(missingChecks: 1);

    expect($restic->openOrInit(resticTarget()))->toBe('existing')
        ->and(resticCalls($dir))->toBe(['cat config', 'cat config']);
});
