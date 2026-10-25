<?php

declare(strict_types=1);

namespace App\Support\RemoteAccess;

/**
 * One OpenSSH public key pasted for "Connect via SSH" (docs/09 R39): the
 * same rules launchroomd checks (`state.SSHPublicKey`). The comment is
 * dropped; ed25519, ECDSA and RSA of at least 3072 bits are accepted.
 */
final readonly class SshPublicKey
{
    private const array TYPES = ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'ssh-rsa'];

    public const int MIN_RSA_BITS = 3072;

    private function __construct(
        public string $type,
        public string $blob,
    ) {}

    /**
     * Parses "type base64 [comment]", or returns null with nothing usable.
     */
    public static function parse(string $line): ?self
    {
        $parts = preg_split('/\s+/', trim($line));

        if (! is_array($parts) || count($parts) < 2 || ! in_array($parts[0], self::TYPES, true) || strlen($parts[1]) > 2048) {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $parts[1]) !== 1) {
            return null;
        }

        $blob = base64_decode($parts[1], true);

        if ($blob === false) {
            return null;
        }

        $fields = self::fields($blob);

        if ($fields === null || ($fields[0] ?? null) !== $parts[0]) {
            return null;
        }

        $ok = match ($parts[0]) {
            'ssh-ed25519' => count($fields) === 2 && strlen($fields[1]) === 32,
            'ssh-rsa' => count($fields) === 3 && self::bits($fields[2]) >= self::MIN_RSA_BITS,
            default => count($fields) === 3 && $fields[1] === substr($parts[0], strlen('ecdsa-sha2-')),
        };

        return $ok ? new self($parts[0], $parts[1]) : null;
    }

    /** "type base64", without the comment (what launchroomd gets). */
    public function line(): string
    {
        return $this->type.' '.$this->blob;
    }

    /** OpenSSH's SHA256 fingerprint ("SHA256:…"). */
    public function fingerprint(): string
    {
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', (string) base64_decode($this->blob, true), true)), '=');
    }

    /**
     * The length-prefixed strings of an SSH wire blob.
     *
     * @return list<string>|null
     */
    private static function fields(string $blob): ?array
    {
        $out = [];
        $offset = 0;
        $length = strlen($blob);

        while ($offset < $length) {
            if ($offset + 4 > $length) {
                return null;
            }

            $n = unpack('N', substr($blob, $offset, 4));
            $size = is_array($n) && is_int($n[1] ?? null) ? $n[1] : -1;

            if ($size < 0 || $offset + 4 + $size > $length || count($out) >= 4) {
                return null;
            }

            $out[] = substr($blob, $offset + 4, $size);
            $offset += 4 + $size;
        }

        return $out;
    }

    /** Bits of an SSH mpint (RSA modulus). */
    private static function bits(string $mpint): int
    {
        $trimmed = ltrim($mpint, "\0");

        if ($trimmed === '') {
            return 0;
        }

        return (strlen($trimmed) - 1) * 8 + strlen(decbin(ord($trimmed[0])));
    }
}
