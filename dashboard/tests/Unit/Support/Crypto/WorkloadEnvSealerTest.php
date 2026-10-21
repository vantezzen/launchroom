<?php

declare(strict_types=1);

use App\Support\Crypto\WorkloadEnvSealer;
use Tests\Support\ProtocolSchemas;

/**
 * Opens a sealed env like the daemon does and applies its binding checks
 * (protocol/README.md "Sealed workload env").
 *
 * @return array<string, string>|null
 */
function openSealedEnv(string $sealed, string $secretKey, string $publicKey, string $serverId, string $workloadId): ?array
{
    $raw = base64_decode($sealed, true);
    $plaintext = $raw === false ? false : sodium_crypto_box_seal_open($raw, sodium_crypto_box_keypair_from_secretkey_and_publickey($secretKey, $publicKey));

    if ($plaintext === false) {
        return null;
    }

    $document = json_decode($plaintext, true);

    if (! is_array($document) || array_keys($document) !== ['serverId', 'workloadId', 'env']
        || $document['serverId'] !== $serverId || $document['workloadId'] !== $workloadId || ! is_array($document['env'])) {
        return null;
    }

    foreach ($document['env'] as $name => $value) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,255}$/D', (string) $name) !== 1 || ! is_string($value)) {
            return null;
        }
    }

    return $document['env'];
}

test('the shared sealing vectors open (or are rejected) exactly as the daemon does', function (): void {
    $fixture = json_decode((string) file_get_contents(ProtocolSchemas::root().'/fixtures/sealing/workload-env.json'), true);
    $secret = base64_decode($fixture['recipientPrivateKey']);
    $public = base64_decode(substr($fixture['recipientPublicKey'], strlen('x25519:')));

    foreach ($fixture['cases'] as $case) {
        $env = openSealedEnv($case['sealed'], $secret, $public, $fixture['serverId'], $fixture['workloadId']);

        expect($env !== null)->toBe($case['valid'], $case['description']);

        if ($case['valid']) {
            expect($env)->toBe($fixture['env']);
        }
    }
});

test('PHP-sealed envs round-trip through the daemon checks, bound to server and workload', function (): void {
    $fixture = json_decode((string) file_get_contents(ProtocolSchemas::root().'/fixtures/sealing/workload-env.json'), true);
    $secret = base64_decode($fixture['recipientPrivateKey']);
    $public = base64_decode(substr($fixture['recipientPublicKey'], strlen('x25519:')));

    $sealed = WorkloadEnvSealer::seal($fixture['recipientPublicKey'], $fixture['serverId'], $fixture['workloadId'], $fixture['env']);

    expect(openSealedEnv($sealed, $secret, $public, $fixture['serverId'], $fixture['workloadId']))->toBe($fixture['env'])
        ->and(openSealedEnv($sealed, $secret, $public, $fixture['serverId'], 'wl_01J9Z8X7W6V5T4S3R2Q1P0N9N8'))->toBeNull()
        ->and(openSealedEnv($sealed, $secret, $public, 'srv_01J9Z8X7W6V5T4S3R2Q1P0N9MA', $fixture['workloadId']))->toBeNull()
        // Randomized: sealing twice never gives the same ciphertext.
        ->and(WorkloadEnvSealer::seal($fixture['recipientPublicKey'], $fixture['serverId'], $fixture['workloadId'], $fixture['env']))->not->toBe($sealed);
});

test('an empty env seals as an object', function (): void {
    $keypair = sodium_crypto_box_keypair();
    $public = sodium_crypto_box_publickey($keypair);
    $sealed = WorkloadEnvSealer::seal('x25519:'.base64_encode($public), 'srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK', 'wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7', []);

    expect(sodium_crypto_box_seal_open(base64_decode($sealed), $keypair))->toContain('"env":{}');
});

test('refuses bad keys and env names the daemon would reject', function (array $env, string $key): void {
    expect(fn (): string => WorkloadEnvSealer::seal($key, 'srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK', 'wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7', $env))->toThrow(InvalidArgumentException::class);
})->with([
    'bad key' => [[], 'x25519:nope'],
    'bad name' => [['BAD-NAME' => 'x'], 'x25519:'.base64_encode(str_repeat('a', 32))],
    'NUL byte' => [['A' => "x\0y"], 'x25519:'.base64_encode(str_repeat('a', 32))],
]);
