<?php

declare(strict_types=1);

namespace App\Support\Crypto;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Seals a mesh pre-auth key to a server's X25519 key (docs/05 §2, protocol
 * `mesh-auth-key.json`): base64(crypto_box_seal({"serverId", "authKey"})).
 * Binding the server id prevents re-targeting; launchroomd rejects a box
 * sealed for another server.
 */
final class MeshKeySealer
{
    public static function seal(string $sealingKey, string $serverId, #[SensitiveParameter] string $authKey): string
    {
        if ($authKey === '' || strlen($authKey) > 1024 || preg_match('/[\x00-\x20\x7f]/', $authKey) === 1) {
            throw new InvalidArgumentException('The mesh key is empty or malformed.');
        }

        $publicKey = PublicKeyFormat::parseX25519($sealingKey);

        if ($publicKey === null) {
            throw new InvalidArgumentException('The server has no valid X25519 sealing key.');
        }

        $json = json_encode(['serverId' => $serverId, 'authKey' => $authKey], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return base64_encode(sodium_crypto_box_seal($json, $publicKey));
    }
}
