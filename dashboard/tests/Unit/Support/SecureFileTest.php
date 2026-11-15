<?php

declare(strict_types=1);

use App\Support\Files\SecureFile;
use App\Support\Files\SecureFileFailed;

test('a secure file is created owner-only and never overwritten', function (): void {
    $directory = sys_get_temp_dir().'/lr-secure-'.bin2hex(random_bytes(4));
    $path = $directory.'/nested/key';

    SecureFile::createExclusive($path, 'secret');

    expect(file_get_contents($path))->toBe('secret')
        ->and(fileperms($path) & 0777)->toBe(0400)
        ->and(fileperms(dirname($path)) & 0777)->toBe(0700);

    try {
        SecureFile::createExclusive($path, 'other', 0600);
        $this->fail('overwrote');
    } catch (SecureFileFailed $e) {
        expect($e->exists)->toBeTrue()->and(file_get_contents($path))->toBe('secret');
    }

    chmod($path, 0600);
    unlink($path);
    rmdir(dirname($path));
    rmdir($directory);
});
