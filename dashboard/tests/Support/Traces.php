<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Traces\TraceCodec;
use JsonException;
use RuntimeException;
use Symfony\Component\Uid\Ulid;

/**
 * Request trace headers for tests (docs/23 §2): the shared fixtures of
 * protocol/fixtures/v1/trace and an encoder like the Caddy module's.
 */
final class Traces
{
    private const array TAGS = [
        'receivedAt' => 0x01, 'requestId' => 0x02, 'edge' => 0x03, 'route' => 0x04, 'service' => 0x05,
        'upstreamServer' => 0x06, 'method' => 0x07, 'path' => 0x08, 'protocol' => 0x09, 'status' => 0x0A,
        'origin' => 0x0B, 'edgeTime' => 0x0C, 'upstreamTime' => 0x0D, 'upstreamStatus' => 0x0E,
        'attempts' => 0x0F, 'failure' => 0x10, 'firewall' => 0x11,
    ];

    public static function dir(): string
    {
        return ProtocolSchemas::root().'/fixtures/v1/trace';
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public static function json(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("{$path} is not a JSON object.");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The fixture key: [key id, raw key, edge server id].
     *
     * @return array{int, string, string}
     */
    public static function fixtureKey(): array
    {
        $key = self::json(self::dir().'/key.json');

        return [(int) $key['keyId'], (string) base64_decode((string) $key['key'], true), (string) $key['edge']];
    }

    /**
     * @return array<string, array<string, mixed>> file name => fixture
     */
    public static function fixtures(string $kind): array
    {
        $out = [];

        foreach (glob(self::dir()."/{$kind}/*.json") ?: [] as $path) {
            $out[basename($path)] = self::json($path);
        }

        return $out;
    }

    /**
     * The header a fixture describes, built from its recipe (README.md
     * "How headers are built").
     *
     * @param  array<string, mixed>  $fixture
     */
    public static function header(array $fixture, string $key): string
    {
        if (! isset($fixture['payload'])) {
            return (string) $fixture['header'];
        }

        /** @var array<string, int|string>|null $mutate */
        $mutate = $fixture['mutate'] ?? null;
        $version = (int) ($fixture['version'] ?? 1);
        $keyId = (int) $fixture['keyId'];
        $bytes = self::seal((string) hex2bin((string) $fixture['payload']), $keyId, $key, (string) hex2bin((string) $fixture['nonce']), $version, (int) ($mutate['aadKeyId'] ?? $keyId));

        if (isset($mutate['truncate'])) {
            $bytes = substr($bytes, 0, (int) $mutate['truncate']);
        }

        if (isset($mutate['flipByte'])) {
            $i = (int) $mutate['flipByte'];
            $i = $i < 0 ? strlen($bytes) + $i : $i;
            $bytes[$i] = chr(ord($bytes[$i]) ^ 0x01);
        }

        $prefix = array_key_exists('prefix', $fixture) ? (string) $fixture['prefix'] : TraceCodec::PREFIX;

        return $prefix.self::base64url($bytes).(isset($mutate['append']) ? (string) $mutate['append'] : '');
    }

    /**
     * A header for $fields (the fixture field names, enumerations as
     * numbers), like the edge writes it.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function encode(array $fields, int $keyId, string $key): string
    {
        $payload = '';

        foreach ($fields as $name => $value) {
            $payload .= self::field(self::TAGS[$name], self::value($name, $value));
        }

        return TraceCodec::PREFIX.self::base64url(self::seal($payload, $keyId, $key, random_bytes(12)));
    }

    private static function seal(string $payload, int $keyId, string $key, string $nonce, int $version = 1, ?int $aadKeyId = null): string
    {
        $tag = '';
        $ciphertext = openssl_encrypt($payload, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, chr($version).pack('N', $aadKeyId ?? $keyId), 16);

        return chr($version).pack('N', $keyId).$nonce.$ciphertext.$tag;
    }

    private static function field(int $tag, string $value): string
    {
        return chr($tag).chr(strlen($value)).$value;
    }

    private static function value(string $name, mixed $value): string
    {
        return match ($name) {
            'receivedAt' => pack('J', $value),
            'requestId' => (string) hex2bin(str_replace('-', '', (string) $value)),
            'edge', 'route', 'service', 'upstreamServer' => Ulid::fromString(substr((string) $value, (int) strpos((string) $value, '_') + 1))->toBinary(),
            'method', 'path' => (string) $value,
            'protocol', 'origin', 'attempts', 'failure' => chr((int) $value),
            'status', 'upstreamStatus' => pack('n', $value),
            'edgeTime', 'upstreamTime' => pack('N', $value),
            'firewall' => self::firewall((array) $value),
            default => throw new RuntimeException("Unknown trace field {$name}."),
        };
    }

    /**
     * @param  array<array-key, mixed>  $firewall
     */
    private static function firewall(array $firewall): string
    {
        $rules = '';

        foreach ((array) ($firewall['rules'] ?? []) as $rule) {
            $rules .= pack('N', $rule);
        }

        return chr((int) $firewall['mode']).chr((int) $firewall['outcome']).chr((int) $firewall['score']).$rules;
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
