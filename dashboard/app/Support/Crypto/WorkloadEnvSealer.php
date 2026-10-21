<?php

declare(strict_types=1);

namespace App\Support\Crypto;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Seals a workload's env to a daemon's X25519 key (docs/03 §6,
 * protocol/README.md "Sealed workload env"): base64(crypto_box_seal(
 * {"serverId", "workloadId", "env"})). Binding the server and workload ids
 * into the plaintext prevents re-targeting; every workload is sealed on its own.
 */
final class WorkloadEnvSealer
{
    private const string NAME = '/^[A-Za-z_][A-Za-z0-9_]{0,255}$/D';

    /**
     * @param  string  $sealingKey  `x25519:<base64>` from enrollment
     * @param  array<string, string>  $env
     */
    public static function seal(string $sealingKey, string $serverId, string $workloadId, #[SensitiveParameter] array $env): string
    {
        $publicKey = PublicKeyFormat::parseX25519($sealingKey);

        if ($publicKey === null) {
            throw new InvalidArgumentException('The server has no valid X25519 sealing key.');
        }

        if (count($env) > 1024) {
            throw new InvalidArgumentException('A workload can have at most 1024 variables.');
        }

        foreach ($env as $name => $value) {
            if (preg_match(self::NAME, (string) $name) !== 1 || str_contains($value, "\0") || strlen($value) > 128 * 1024) {
                throw new InvalidArgumentException("Variable {$name} can't be passed to a container (name grammar, NUL byte or size).");
            }
        }

        $document = json_encode(
            ['serverId' => $serverId, 'workloadId' => $workloadId, 'env' => (object) $env],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return base64_encode(sodium_crypto_box_seal($document, $publicKey));
    }
}
