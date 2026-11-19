<?php

declare(strict_types=1);

namespace App\Support\Traces;

use App\Enums\Traces\TraceFailure;
use App\Enums\Traces\TraceFirewallOutcome;
use App\Enums\Traces\TraceOrigin;
use SensitiveParameter;
use Symfony\Component\Uid\Ulid;

/**
 * The `Launchroom-Trace` header, format v1 (docs/23 §2): base64url without
 * padding of `version (1) | key id (uint32 BE) | nonce (12) | AES-256-GCM
 * ciphertext and tag`, the first five bytes as additional data; the payload
 * is `tag | length | value` fields. Pure: the key comes from the caller
 * (App\Actions\Traces\DecodeTrace). Every refusal is null, without saying
 * why (one "not a trace we can read" answer). The Go encoder of the Caddy
 * module runs the same vectors (protocol/fixtures/v1/trace).
 */
final class TraceCodec
{
    public const int VERSION = 1;

    /** Longest input accepted (a typical trace is about 300 characters). */
    public const int MAX_INPUT = 2048;

    public const string HEADER = 'Launchroom-Trace';

    private const int NONCE = 12;

    private const int TAG = 16;

    private const int HEAD = 5;

    /** Fields whose value has a fixed length (docs/23 §2). */
    private const array FIXED = [
        0x01 => 8, 0x02 => 16, 0x03 => 16, 0x04 => 16, 0x05 => 16, 0x06 => 16,
        0x09 => 1, 0x0A => 2, 0x0B => 1, 0x0C => 4, 0x0D => 4, 0x0E => 2, 0x0F => 1, 0x10 => 1,
    ];

    /** receivedAt, requestId, edge, status, origin: every edge writes them. */
    private const array REQUIRED = [0x01, 0x02, 0x03, 0x0A, 0x0B];

    private const array PROTOCOLS = [1 => 'HTTP/1.0', 2 => 'HTTP/1.1', 3 => 'HTTP/2', 4 => 'HTTP/3'];

    /**
     * The header value from what a member pasted: the bare value or a whole
     * header line (`Launchroom-Trace: …`, any case), surrounding whitespace
     * dropped. Null when it can't be a trace value.
     */
    public static function value(string $input): ?string
    {
        $value = trim($input);

        if (strlen($value) > self::MAX_INPUT) {
            return null;
        }

        if (preg_match('/^'.self::HEADER.'\s*:\s*(\S+)$/Di', $value, $match) === 1) {
            $value = $match[1];
        }

        return preg_match('/^[A-Za-z0-9_-]+$/D', $value) === 1 ? $value : null;
    }

    /**
     * Splits a header value into its key id and sealed parts; null for
     * anything that isn't a v1 trace by its shape.
     */
    public static function envelope(string $value): ?TraceEnvelope
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1 || strlen($value) % 4 === 1) {
            return null;
        }

        $bytes = base64_decode(strtr($value, '-_', '+/'), true);

        if ($bytes === false || strlen($bytes) < self::HEAD + self::NONCE + self::TAG + 2 || ord($bytes[0]) !== self::VERSION) {
            return null;
        }

        // Canonical encoding only: the unused bits of the last character must be zero.
        if (rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=') !== $value) {
            return null;
        }

        /** @var array{1: int} $keyId */
        $keyId = unpack('N', substr($bytes, 1, 4));

        return new TraceEnvelope(
            keyId: $keyId[1],
            additionalData: substr($bytes, 0, self::HEAD),
            nonce: substr($bytes, self::HEAD, self::NONCE),
            ciphertext: substr($bytes, self::HEAD + self::NONCE, -self::TAG),
            tag: substr($bytes, -self::TAG),
        );
    }

    /**
     * Decrypts the payload with the edge's 32-byte key; null when the
     * authentication fails.
     */
    public static function open(TraceEnvelope $envelope, #[SensitiveParameter] string $key): ?string
    {
        if (strlen($key) !== 32) {
            return null;
        }

        $payload = openssl_decrypt($envelope->ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $envelope->nonce, $envelope->tag, $envelope->additionalData);

        return is_string($payload) && $payload !== '' ? $payload : null;
    }

    /**
     * The fields of a payload. Unknown tags are skipped; a length past the
     * end, a known tag with a wrong length, a tag given twice, a value
     * outside its range or a missing required field refuses the whole
     * payload.
     */
    public static function fields(string $payload): ?DecodedTrace
    {
        $values = [];
        $offset = 0;
        $end = strlen($payload);

        while ($offset < $end) {
            if ($offset + 2 > $end) {
                return null;
            }

            $tag = ord($payload[$offset]);
            $length = ord($payload[$offset + 1]);
            $offset += 2;

            if ($offset + $length > $end) {
                return null;
            }

            $value = substr($payload, $offset, $length);
            $offset += $length;

            if (! self::known($tag)) {
                continue;
            }

            if (isset($values[$tag]) || ! self::validLength($tag, $length)) {
                return null;
            }

            $values[$tag] = $value;
        }

        return self::decode($values);
    }

    /**
     * @param  array<int, string>  $values
     */
    private static function decode(array $values): ?DecodedTrace
    {
        foreach (self::REQUIRED as $tag) {
            if (! isset($values[$tag])) {
                return null;
            }
        }

        $method = $values[0x07] ?? null;
        $path = $values[0x08] ?? null;
        $protocol = isset($values[0x09]) ? (self::PROTOCOLS[ord($values[0x09])] ?? false) : null;
        $origin = TraceOrigin::fromByte(ord($values[0x0B]));
        $failure = isset($values[0x10]) ? (TraceFailure::fromByte(ord($values[0x10])) ?? false) : null;

        // Values outside their range refuse the payload (protocol/fixtures/v1/trace/README.md).
        if (($method !== null && preg_match('/^[\x21-\x7E]{1,16}$/D', $method) !== 1)
            || ($path !== null && (! str_starts_with($path, '/') || ! mb_check_encoding($path, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1))
            || $protocol === false || ! $origin instanceof TraceOrigin || $failure === false) {
            return null;
        }

        $receivedAt = self::uint64($values[0x01]);

        if ($receivedAt === null) {
            return null;
        }

        $firewall = isset($values[0x11]) ? self::firewall($values[0x11]) : null;

        if (isset($values[0x11]) && $firewall === null) {
            return null;
        }

        return new DecodedTrace(
            receivedAtMs: $receivedAt,
            requestId: self::uuid($values[0x02]),
            edge: self::publicId('srv', $values[0x03]),
            status: self::uint($values[0x0A]),
            origin: $origin,
            route: isset($values[0x04]) ? self::publicId('rt', $values[0x04]) : null,
            service: isset($values[0x05]) ? self::publicId('sin', $values[0x05]) : null,
            upstreamServer: isset($values[0x06]) ? self::publicId('srv', $values[0x06]) : null,
            method: $method,
            path: $path,
            protocol: $protocol,
            edgeTimeUs: isset($values[0x0C]) ? self::uint($values[0x0C]) : null,
            upstreamTimeUs: isset($values[0x0D]) ? self::uint($values[0x0D]) : null,
            upstreamStatus: isset($values[0x0E]) ? self::uint($values[0x0E]) : null,
            attempts: isset($values[0x0F]) ? ord($values[0x0F]) : null,
            failure: $failure,
            firewallMode: $firewall['mode'] ?? null,
            firewallOutcome: $firewall['outcome'] ?? null,
            firewallScore: $firewall['score'] ?? null,
            firewallRules: $firewall['rules'] ?? [],
        );
    }

    private static function known(int $tag): bool
    {
        return $tag >= 0x01 && $tag <= 0x11;
    }

    private static function validLength(int $tag, int $length): bool
    {
        return match (true) {
            isset(self::FIXED[$tag]) => $length === self::FIXED[$tag],
            $tag === 0x07 => $length >= 1 && $length <= 16,
            $tag === 0x08 => $length >= 1 && $length <= 128,
            // mode, outcome, score, then 0–8 rule ids of 4 bytes.
            $tag === 0x11 => $length >= 3 && $length <= 35 && ($length - 3) % 4 === 0,
            default => false,
        };
    }

    /**
     * @return array{mode: int, outcome: TraceFirewallOutcome, score: int, rules: list<int>}|null
     */
    private static function firewall(string $value): ?array
    {
        $mode = ord($value[0]);
        $outcome = TraceFirewallOutcome::fromByte(ord($value[1]));

        if (! in_array($mode, [1, 2], true) || ! $outcome instanceof TraceFirewallOutcome) {
            return null;
        }

        $rules = [];

        // str_split('') is [] (PHP 8.2+): no rules.
        foreach (str_split(substr($value, 3), 4) as $rule) {
            $rules[] = self::uint($rule);
        }

        return ['mode' => $mode, 'outcome' => $outcome, 'score' => ord($value[2]), 'rules' => $rules];
    }

    private static function uint(string $bytes): int
    {
        $value = 0;

        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
        }

        return $value;
    }

    /**
     * Null when it doesn't fit a PHP int (no real clock gets there).
     */
    private static function uint64(string $bytes): ?int
    {
        return ord($bytes[0]) >= 0x80 ? null : self::uint($bytes);
    }

    private static function uuid(string $bytes): string
    {
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    private static function publicId(string $prefix, string $bytes): string
    {
        return $prefix.'_'.Ulid::fromBinary($bytes)->toBase32();
    }
}
