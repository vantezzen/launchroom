<?php

declare(strict_types=1);

use App\Services\Crypto\Envelope;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use App\Services\Crypto\SecretKey;

beforeEach(function (): void {
    $this->envelope = new Envelope(SecretKey::generate());
});

test('round-trips values through a wrapped project key', function (): void {
    $dek = $this->envelope->generateDataKey();
    $wrapped = $this->envelope->wrap($dek, 'project:42');

    $ciphertext = $dek->encrypt('postgres://user:s3cret@db:5432/app', '1|42|7|1');

    $unwrapped = $this->envelope->unwrap($wrapped, 'project:42');

    expect($unwrapped->decrypt($ciphertext, '1|42|7|1'))->toBe('postgres://user:s3cret@db:5432/app');
});

test('ciphertexts are versioned, base64 and randomized', function (): void {
    $dek = SecretKey::generate();

    $a = $dek->encrypt('same', 'ad');
    $b = $dek->encrypt('same', 'ad');

    expect($a)->toStartWith('lr1:')
        ->and(base64_decode(substr($a, 4), true))->not->toBeFalse()
        ->and($a)->not->toBe($b)
        ->and($a)->not->toContain('same');
});

test('round-trips empty and binary plaintexts', function (string $plaintext): void {
    $dek = SecretKey::generate();

    expect($dek->decrypt($dek->encrypt($plaintext, 'ad'), 'ad'))->toBe($plaintext);
})->with([
    'empty' => '',
    'binary' => "\x00\xff\x10binary",
    'unicode' => 'pässwörd 🔑',
]);

test('decryption fails when the associated data differs (ciphertext swapping)', function (): void {
    $dek = SecretKey::generate();
    $ciphertext = $dek->encrypt('secret', '1|42|7|1');

    $dek->decrypt($ciphertext, '1|42|8|1');
})->throws(DecryptionFailed::class);

test('unwrapping fails for another project context', function (): void {
    $wrapped = $this->envelope->wrap($this->envelope->generateDataKey(), 'project:42');

    $this->envelope->unwrap($wrapped, 'project:43');
})->throws(DecryptionFailed::class);

test('a DEK-encrypted value cannot be unwrapped as a DEK even under the KEK', function (): void {
    $kek = SecretKey::generate();
    $envelope = new Envelope($kek);

    // Same key and associated data, but without the "dek|" domain separation.
    $notAWrappedKey = $kek->encrypt(random_bytes(SecretKey::BYTES), 'project:42');

    $envelope->unwrap($notAWrappedKey, 'project:42');
})->throws(DecryptionFailed::class);

test('decryption fails with another key', function (): void {
    $ciphertext = SecretKey::generate()->encrypt('secret', 'ad');

    SecretKey::generate()->decrypt($ciphertext, 'ad');
})->throws(DecryptionFailed::class);

test('decryption fails when the ciphertext is tampered with', function (int $offset): void {
    $dek = SecretKey::generate();
    $raw = base64_decode(substr($dek->encrypt('secret value', 'ad'), 4), true);
    $position = $offset < 0 ? strlen($raw) + $offset : $offset;
    $raw[$position] = chr(ord($raw[$position]) ^ 0x01);

    $dek->decrypt('lr1:'.base64_encode($raw), 'ad');
})->with([
    'nonce' => 0,
    'body' => 30,
    'tag' => -1,
])->throws(DecryptionFailed::class);

test('decryption rejects malformed input', function (string $ciphertext): void {
    SecretKey::generate()->decrypt($ciphertext, 'ad');
})->with([
    'empty' => '',
    'no prefix' => base64_encode(random_bytes(64)),
    'unknown version' => 'lr2:'.base64_encode(random_bytes(64)),
    'not base64' => 'lr1:***',
    'too short' => 'lr1:'.base64_encode(random_bytes(10)),
])->throws(DecryptionFailed::class);

test('keys must be exactly 32 bytes', function (): void {
    SecretKey::fromBytes(random_bytes(16));
})->throws(InvalidArgumentException::class);

test('keys never leak through var_dump, print_r or serialization', function (): void {
    $key = SecretKey::fromBytes(str_repeat('K', SecretKey::BYTES));
    $envelope = new Envelope($key);

    expect(print_r($key, true))->not->toContain('KKKK')->toContain('[redacted]')
        ->and(print_r($envelope, true))->not->toContain('KKKK')
        ->and(fn (): string => serialize($key))->toThrow(LogicException::class)
        ->and(fn (): string => serialize($envelope))->toThrow(LogicException::class);
});

test('key ids are stable and do not reveal the key', function (): void {
    $bytes = random_bytes(SecretKey::BYTES);
    $id = SecretKey::fromBytes($bytes)->id();

    expect($id)->toHaveLength(16)
        ->and(SecretKey::fromBytes($bytes)->id())->toBe($id)
        ->and(SecretKey::generate()->id())->not->toBe($id)
        ->and(new Envelope(SecretKey::fromBytes($bytes))->kekId())->toBe($id);
});
