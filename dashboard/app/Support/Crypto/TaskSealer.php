<?php

declare(strict_types=1);

namespace App\Support\Crypto;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Seals task secrets to the leasing daemon's X25519 key (docs/03 §6–7,
 * protocol `task-env.json` / `task-token.json`): base64(crypto_box_seal(…))
 * of `{"serverId", "taskId", "env"}` or `{"serverId", "taskId", "purpose",
 * "token"}`. Binding server and task ids prevents re-targeting; the daemon
 * rejects a box sealed for another task or server.
 */
final class TaskSealer
{
    private const string NAME = '/^[A-Za-z_][A-Za-z0-9_]{0,255}$/D';

    /**
     * @param  array<string, string>  $env
     */
    public static function env(string $sealingKey, string $serverId, string $taskId, #[SensitiveParameter] array $env): string
    {
        if (count($env) > 1024) {
            throw new InvalidArgumentException('A task can have at most 1024 variables.');
        }

        foreach ($env as $name => $value) {
            if (preg_match(self::NAME, (string) $name) !== 1 || str_contains($value, "\0") || strlen($value) > 128 * 1024) {
                throw new InvalidArgumentException("Variable {$name} can't be passed to a task (name grammar, NUL byte or size).");
            }
        }

        return self::seal($sealingKey, ['serverId' => $serverId, 'taskId' => $taskId, 'env' => (object) $env]);
    }

    public static function token(string $sealingKey, string $serverId, string $taskId, string $purpose, #[SensitiveParameter] string $token): string
    {
        if ($token === '' || str_contains($token, "\0") || strlen($token) > 4096) {
            throw new InvalidArgumentException('The task token is empty or malformed.');
        }

        return self::seal($sealingKey, ['serverId' => $serverId, 'taskId' => $taskId, 'purpose' => $purpose, 'token' => $token]);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function seal(string $sealingKey, #[SensitiveParameter] array $document): string
    {
        $publicKey = PublicKeyFormat::parseX25519($sealingKey);

        if ($publicKey === null) {
            throw new InvalidArgumentException('The server has no valid X25519 sealing key.');
        }

        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return base64_encode(sodium_crypto_box_seal($json, $publicKey));
    }
}
