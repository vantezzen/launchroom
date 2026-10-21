<?php

declare(strict_types=1);

namespace App\Support\Crypto;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Seals ingress credentials to an edge server's X25519 key
 * (protocol/schema/v1/ingress-secret.json): base64(crypto_box_seal(
 * {"serverId", "purpose", "values"})). The server id and purpose in the
 * plaintext bind the box to one field of one server's NodeState; launchroomd
 * refuses it anywhere else. Only edge servers ever receive one (the
 * client_auth edge secret only the control-plane server).
 */
final class IngressSecretSealer
{
    public const string DNS = 'dns';

    public const string CERT_STORAGE = 'cert_storage';

    /** The edge secret of the dashboard's system routes (mTLS, docs/03 §4.3). */
    public const string CLIENT_AUTH = 'client_auth';

    /**
     * @param  string  $sealingKey  `x25519:<base64>` from enrollment
     * @param  self::DNS|self::CERT_STORAGE|self::CLIENT_AUTH  $purpose
     * @param  array<string, string>  $values
     */
    public static function seal(string $sealingKey, string $serverId, string $purpose, #[SensitiveParameter] array $values): string
    {
        $publicKey = PublicKeyFormat::parseX25519($sealingKey);

        if ($publicKey === null) {
            throw new InvalidArgumentException('The server has no valid X25519 sealing key.');
        }

        foreach ($values as $name => $value) {
            if ($value === '' || strlen($value) > 4096 || preg_match('/[\x00\r\n]/', $value) === 1) {
                throw new InvalidArgumentException("Ingress secret {$name} must be 1-4096 bytes without NUL or line breaks.");
            }
        }

        $document = json_encode(
            ['serverId' => $serverId, 'purpose' => $purpose, 'values' => (object) $values],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return base64_encode(sodium_crypto_box_seal($document, $publicKey));
    }
}
