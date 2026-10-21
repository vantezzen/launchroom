<?php

declare(strict_types=1);

namespace App\Support\Git;

/**
 * A pinned SSH host key (docs/19 §4.2): the key type and the standard base64
 * of its wire-format blob, as in a known_hosts line. The fingerprint is
 * OpenSSH's `SHA256:<base64 without padding>` of the blob.
 */
final readonly class HostKey
{
    public const array TYPES = ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'ssh-rsa'];

    private function __construct(
        public string $type,
        public string $key,
    ) {}

    /**
     * From `<type> <base64>` (the algorithm prefix may be a signature
     * algorithm such as `rsa-sha2-512`; the type is read from the blob).
     */
    public static function fromOpenSsh(string $line): ?self
    {
        $parts = preg_split('/\s+/', trim($line));

        return is_array($parts) && count($parts) >= 2 ? self::fromBlob($parts[1]) : null;
    }

    public static function fromBlob(string $base64): ?self
    {
        if (strlen($base64) > 4096 || preg_match('#^[A-Za-z0-9+/]+={0,2}$#D', $base64) !== 1) {
            return null;
        }

        $blob = base64_decode($base64, true);

        if ($blob === false || strlen($blob) < 4) {
            return null;
        }

        $length = unpack('N', substr($blob, 0, 4));
        $length = is_array($length) && is_int($length[1]) ? $length[1] : -1;

        if ($length < 1 || $length > 64 || strlen($blob) < 4 + $length) {
            return null;
        }

        $type = substr($blob, 4, $length);

        return in_array($type, self::TYPES, true) ? new self($type, base64_encode($blob)) : null;
    }

    /**
     * @param  array<mixed>  $stored
     */
    public static function fromArray(array $stored): ?self
    {
        $key = is_string($stored['key'] ?? null) ? self::fromBlob($stored['key']) : null;

        return $key instanceof HostKey && ($stored['type'] ?? null) === $key->type ? $key : null;
    }

    public function fingerprint(): string
    {
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', (string) base64_decode($this->key, true), true)), '=');
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && hash_equals($this->key, $other->key);
    }

    /**
     * @return array{type: string, key: string, fingerprint: string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'key' => $this->key, 'fingerprint' => $this->fingerprint()];
    }
}
