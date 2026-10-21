<?php

declare(strict_types=1);

use App\Models\SigningKey;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\Exceptions\InvalidKeyFile;
use App\Services\Crypto\KeyFile;
use App\Services\Crypto\Signer;
use App\Services\Crypto\SigningKeyFile;
use App\Services\Registry\RegistryTokenKeyFile;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/launchroom-keys-'.bin2hex(random_bytes(6));
    $this->path = $this->dir.'/kek.key';
    $this->signingPath = $this->dir.'/signing.ed25519';
    $this->registryPath = $this->dir.'/registry-token.es256.pem';
    $this->registryPublicPath = $this->dir.'/registry-public/registry-token.pub.pem';
    config([
        'launchroom.kek_path' => $this->path,
        'launchroom.signing_key_path' => $this->signingPath,
        'launchroom.registry.token_key_path' => $this->registryPath,
        'launchroom.registry.token_public_key_path' => $this->registryPublicPath,
    ]);

    // These tests exercise the real key files, not the in-memory test KEK.
    app()->forgetInstance(Envelope::class);
});

afterEach(function (): void {
    foreach ([$this->path, $this->signingPath, $this->registryPath, $this->registryPublicPath] as $path) {
        if (is_file($path)) {
            chmod($path, 0600);
            unlink($path);
        }
    }

    @rmdir($this->dir.'/registry-public');
    @rmdir($this->dir);
});

test('generates the KEK and signing key files when missing', function (): void {
    $this->artisan('launchroom:keys:generate')
        ->expectsOutputToContain('KEK created')
        ->expectsOutputToContain('Signing key created')
        ->assertSuccessful();

    $signer = SigningKeyFile::read($this->signingPath);

    expect(fileperms($this->path) & 0777)->toBe(0400)
        ->and(fileperms($this->signingPath) & 0777)->toBe(0400)
        ->and(resolve(Envelope::class)->kekId())->toBe(KeyFile::read($this->path)->id())
        ->and($signer->kid)->toBe('cp-'.now()->format('Y-m-d'))
        ->and($signer->publicKey())->toMatch('/^ed25519:[A-Za-z0-9+\/]{43}=$/')
        ->and(SigningKey::query()->sole()->public_key)->toBe($signer->publicKey());
});

test('leaves existing keys untouched', function (): void {
    $original = KeyFile::create($this->path);
    $signer = SigningKeyFile::create($this->signingPath, 'cp-old');

    $this->artisan('launchroom:keys:generate')
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(KeyFile::read($this->path)->id())->toBe($original->id())
        ->and(SigningKeyFile::read($this->signingPath)->publicKey())->toBe($signer->publicKey());
});

test('a malformed signing key file is rejected', function (): void {
    mkdir($this->dir, 0700, true);
    file_put_contents($this->signingPath, "lrsign1:cp-x:not-base64\n");

    expect(fn (): Signer => SigningKeyFile::read($this->signingPath))->toThrow(InvalidKeyFile::class);
});

test('generates the registry token key pair once and keeps it', function (): void {
    $this->artisan('launchroom:keys:generate')
        ->expectsOutputToContain('Registry token key created')
        ->assertSuccessful();

    $key = RegistryTokenKeyFile::read($this->registryPath);

    expect(fileperms($this->registryPath) & 0777)->toBe(0400)
        ->and(fileperms($this->registryPublicPath) & 0777)->toBe(0444)
        ->and((string) file_get_contents($this->registryPublicPath))->toBe($key->publicPem)
        ->and($key->publicPem)->toStartWith('-----BEGIN PUBLIC KEY-----');

    // Deleted public key files are restored from the private key, which is never replaced.
    chmod($this->registryPublicPath, 0600);
    unlink($this->registryPublicPath);

    $this->artisan('launchroom:keys:generate')
        ->expectsOutputToContain('Registry token key already exists')
        ->assertSuccessful();

    expect(RegistryTokenKeyFile::read($this->registryPath)->kid())->toBe($key->kid())
        ->and((string) file_get_contents($this->registryPublicPath))->toBe($key->publicPem);
});
