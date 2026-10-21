<?php

declare(strict_types=1);

use App\Services\Crypto\Envelope;
use App\Services\Crypto\Exceptions\InvalidKeyFile;
use App\Services\Crypto\KeyFile;
use App\Services\Crypto\SecretKey;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/launchroom-keyfile-'.bin2hex(random_bytes(6));
    $this->path = $this->dir.'/keys/kek.key';
});

afterEach(function (): void {
    if (is_file($this->path)) {
        chmod($this->path, 0600);
        unlink($this->path);
    }

    @rmdir($this->dir.'/keys');
    @rmdir($this->dir);
});

test('creates an owner-read-only key file that can be read back', function (): void {
    $key = KeyFile::create($this->path);

    expect(fileperms($this->path) & 0777)->toBe(0400)
        ->and(fileperms(dirname($this->path)) & 0777)->toBe(0700)
        ->and(file_get_contents($this->path))->toStartWith('lrkey1:')
        ->and(KeyFile::read($this->path)->id())->toBe($key->id())
        ->and(Envelope::fromKeyFile($this->path)->kekId())->toBe($key->id());
});

test('refuses to overwrite an existing key file', function (): void {
    $key = KeyFile::create($this->path);

    expect(fn (): SecretKey => KeyFile::create($this->path))->toThrow(InvalidKeyFile::class, 'refusing to overwrite')
        ->and(KeyFile::read($this->path)->id())->toBe($key->id());
});

test('reading a missing key file fails with guidance', function (): void {
    KeyFile::read($this->path);
})->throws(InvalidKeyFile::class, 'launchroom:keys:generate');

test('reading a malformed key file fails', function (string $contents): void {
    mkdir(dirname($this->path), 0700, true);
    file_put_contents($this->path, $contents);

    KeyFile::read($this->path);
})->with([
    'empty' => '',
    'no prefix' => base64_encode(random_bytes(32)),
    'wrong length' => 'lrkey1:'.base64_encode(random_bytes(16)),
    'not base64' => 'lrkey1:!!!',
])->throws(InvalidKeyFile::class);
