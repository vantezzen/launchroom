<?php

declare(strict_types=1);

use App\Models\Backups\BackupDestination;
use App\Services\Backups\ControlPlaneKeyBundle;
use App\Services\Backups\DestinationCredentials;
use App\Services\Backups\Process\ProcessOutcome;
use App\Services\Backups\RecoveryKey;
use App\Services\Backups\RecoveryKitDocument;

function fastKdf(): array
{
    return RecoveryKey::newKdf(SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);
}

test('a bundle sealed to the passphrase key opens only with that passphrase', function (): void {
    $kdf = fastKdf();
    $public = RecoveryKey::publicKey('correct horse battery staple', $kdf);
    $bundle = RecoveryKey::seal('{"secret":"value"}', $public, $kdf);

    expect($bundle)->not->toContain('value')
        ->and(RecoveryKey::open($bundle, 'correct horse battery staple'))->toBe('{"secret":"value"}')
        ->and(RecoveryKey::verify('correct horse battery staple', $kdf, $public))->toBeTrue()
        ->and(RecoveryKey::verify('wrong horse', $kdf, $public))->toBeFalse();

    expect(fn (): string => RecoveryKey::open($bundle, 'wrong horse battery staple'))->toThrow(RuntimeException::class, 'not correct');
});

test('tampered bundles and weak KDF parameters are refused', function (): void {
    $kdf = fastKdf();
    $bundle = json_decode(RecoveryKey::seal('payload', RecoveryKey::publicKey('passphrase-1234', $kdf), $kdf), true);

    $tampered = $bundle;
    $raw = base64_decode($tampered['sealed']);
    $raw[40] = chr(ord($raw[40]) ^ 1);
    $tampered['sealed'] = base64_encode($raw);
    expect(fn (): string => RecoveryKey::open(json_encode($tampered), 'passphrase-1234'))->toThrow(RuntimeException::class, 'could not be decrypted');

    $weak = $bundle;
    $weak['kdf']['opslimit'] = 1;
    expect(fn (): string => RecoveryKey::open(json_encode($weak), 'passphrase-1234'))->toThrow(RuntimeException::class, 'unsupported');
});

test('bound bundles name their run and database snapshot and are authenticated by the passphrase (review L5)', function (): void {
    $kdf = fastKdf();
    $public = RecoveryKey::publicKey('correct horse battery staple', $kdf);
    $macKey = RecoveryKey::macKey('correct horse battery staple', $kdf);
    $binding = ['run' => 'bkr_01', 'databaseSnapshot' => str_repeat('d', 64)];
    $bundle = RecoveryKey::seal('{"secret":"value"}', $public, $kdf, $binding, $macKey);

    expect(json_decode($bundle, true)['format'])->toBe(RecoveryKey::FORMAT_BOUND)
        ->and(RecoveryKey::openBound($bundle, 'correct horse battery staple'))->toBe(['{"secret":"value"}', $binding]);

    // Anyone can seal to the public key, but without the passphrase they
    // can't authenticate: a forged bundle (another binding, another payload)
    // is refused before it is opened.
    $forged = json_decode($bundle, true);
    $forged['binding']['databaseSnapshot'] = str_repeat('e', 64);
    expect(fn (): array => RecoveryKey::openBound(json_encode($forged), 'correct horse battery staple'))->toThrow(RuntimeException::class, 'not authentic');

    $resealed = json_decode(RecoveryKey::seal('{"secret":"planted"}', $public, $kdf, $binding, random_bytes(32)), true);
    expect(fn (): array => RecoveryKey::openBound(json_encode($resealed), 'correct horse battery staple'))->toThrow(RuntimeException::class, 'not authentic');

    // Dropping the MAC (downgrading the format) loses the binding, which the restore reports.
    $legacy = json_decode(RecoveryKey::seal('{"secret":"value"}', $public, $kdf), true);
    expect(RecoveryKey::openBound(json_encode($legacy), 'correct horse battery staple')[1])->toBeNull();
});

test('the recovery kit renders and parses without the passphrase', function (): void {
    $destination = new BackupDestination(['endpoint' => 'https://fsn1.your-objectstorage.com', 'bucket' => 'lr-backups', 'region' => 'fsn1', 'prefix' => 'cp']);
    $document = RecoveryKitDocument::render($destination, new DestinationCredentials('AKIA1', 'secret-2', 'repo-password-3'), 'https://dash.example.com');

    expect($document)->toContain('launchroom recovery kit')->toContain('install | sh -s -- --restore');

    expect(RecoveryKitDocument::parse($document))->toMatchArray([
        'version' => '1',
        'endpoint' => 'https://fsn1.your-objectstorage.com',
        'bucket' => 'lr-backups',
        'prefix' => 'cp',
        'region' => 'fsn1',
        'access-key-id' => 'AKIA1',
        'secret-access-key' => 'secret-2',
        'repository-password' => 'repo-password-3',
        'repository' => 's3:https://fsn1.your-objectstorage.com/lr-backups/cp',
    ]);

    expect(fn (): array => RecoveryKitDocument::parse('just some text'))->toThrow(RuntimeException::class);
});

test('the key bundle keeps only known files and well-formed env names', function (): void {
    $parsed = ControlPlaneKeyBundle::parse(json_encode([
        'format' => ControlPlaneKeyBundle::FORMAT,
        'appUrl' => 'https://dash.example.com',
        'env' => ['APP_KEY' => 'base64:abc', 'bad name' => 'x', 'MULTI' => "a\nb"],
        'files' => ['kek' => 'lrkey1:...', '../../etc/passwd' => 'root'],
    ]));

    expect($parsed['env'])->toBe(['APP_KEY' => 'base64:abc'])
        ->and(array_keys($parsed['files']))->toBe(['kek']);
});

test('process output masks secrets in raw, base64 and url-encoded form', function (): void {
    $secret = 'p@ss/word+1';
    $text = "Fatal: auth {$secret} ".base64_encode($secret).' '.rawurlencode($secret);

    expect(ProcessOutcome::mask($text, [$secret]))->toBe('Fatal: auth *** *** ***');
});

test('destination credentials never serialize or dump', function (): void {
    $credentials = new DestinationCredentials('AKIA1', 'very-secret', 'repo-password');

    expect(print_r($credentials, true))->not->toContain('very-secret')
        ->and(fn (): string => serialize($credentials))->toThrow(LogicException::class);
});
