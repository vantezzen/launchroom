<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataTransfer;
use App\Models\User;
use App\Support\Crypto\PublicKeyFormat;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Builders for data import/export tests (docs/14 §12): sample dump headers,
 * a chunked upload through the routes, a daemon that signs its requests.
 */
final class Transfers
{
    public const int CHUNK = 1024 * 1024;

    /**
     * Small chunks and a private, empty transfer folder per test.
     */
    public static function useTempStore(): string
    {
        $path = sys_get_temp_dir().'/lr-transfers-test-'.bin2hex(random_bytes(6));
        config([
            'launchroom.transfers.path' => $path,
            'launchroom.transfers.chunk_bytes' => self::CHUNK,
        ]);

        return $path;
    }

    /**
     * The start of a `pg_dump -Fc` file (archive 1.15) made by pg_dump
     * $dump against server $remote.
     */
    public static function pgCustom(string $remote = '15.8 (Debian 15.8-1.pgdg120+1)', string $dump = '15.8 (Debian 15.8-1.pgdg120+1)', int $padTo = 0): string
    {
        $int = static fn (int $value): string => chr($value < 0 ? 1 : 0).pack('V', abs($value));
        $str = static fn (string $value): string => $int(strlen($value)).$value;
        $bytes = 'PGDMP'.chr(1).chr(15).chr(0).chr(4).chr(8).chr(1).chr(0);

        foreach ([12, 30, 10, 21, 9, 126, 0] as $field) {
            $bytes .= $int($field);
        }

        $bytes .= $str('umami').$str($remote).$str($dump);

        return $padTo > strlen($bytes) ? $bytes.str_repeat("\0", $padTo - strlen($bytes)) : $bytes;
    }

    public static function mongoArchive(string $serverVersion = '7.0.5'): string
    {
        $string = static fn (string $name, string $value): string => "\x02".$name."\0".pack('V', strlen($value) + 1).$value."\0";
        $body = "\x10concurrent_collections\0".pack('V', 4).$string('version', '0.1').$string('server_version', $serverVersion).$string('tool_version', '100.9.4');
        $document = pack('V', strlen($body) + 5).$body."\0";

        return "\x6d\xe2\x99\x81".$document.pack('V', 0xFFFFFFFF);
    }

    /**
     * Uploads $bytes to $instance through the web routes (chunks in reverse
     * order, to show order doesn't matter) and completes it.
     *
     * @return array{DataTransfer, TestResponse<Response>}
     */
    public static function upload(TestCase $test, User $user, ServiceInstance $instance, string $bytes, string $fileName = 'umami.dump', string $purpose = 'database_import', ?string $volume = null): array
    {
        $created = $test->actingAs($user)->postJson(route('transfers.services.transfers.store', $instance), array_filter([
            'purpose' => $purpose, 'fileName' => $fileName, 'sizeBytes' => strlen($bytes), 'volume' => $volume,
        ]))->assertCreated();
        $id = (string) $created->json('id');
        $chunks = str_split($bytes, self::CHUNK);

        foreach (array_reverse(array_keys($chunks)) as $index) {
            self::putChunk($test, $id, $index, $chunks[$index])->assertOk();
        }

        $response = $test->postJson(route('transfers.complete', $id));

        return [DataTransfer::query()->where('public_id', $id)->sole(), $response];
    }

    /**
     * @return TestResponse<Response>
     */
    public static function putChunk(TestCase $test, string $transfer, int $index, string $body, ?string $sha256 = null): TestResponse
    {
        return $test->call('PUT', route('transfers.chunks', [$transfer, $index]), [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CHUNK_SHA256' => $sha256 ?? hash('sha256', $body),
        ], $body);
    }

    /**
     * A FakeDaemon whose key the server trusts.
     */
    public static function daemon(Server $server): FakeDaemon
    {
        $seed = random_bytes(32);
        $server->forceFill(['signing_public_key' => PublicKeyFormat::formatEd25519(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed)))])->save();

        return new FakeDaemon($seed)->as($server->public_id);
    }

    /**
     * SHA-256 over the raw chunk digests (docs/14 §12.1).
     */
    public static function digest(string $bytes, int $chunk = self::CHUNK): string
    {
        $raw = '';

        foreach (str_split($bytes, $chunk) as $piece) {
            $raw .= hash('sha256', $piece, true);
        }

        return hash('sha256', $raw);
    }
}
