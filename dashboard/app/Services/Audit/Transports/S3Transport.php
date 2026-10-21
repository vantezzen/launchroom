<?php

declare(strict_types=1);

namespace App\Services\Audit\Transports;

use App\Models\AuditSink;
use App\Services\Audit\AuditBatch;
use App\Services\Audit\AuditDeliveryFailed;
use App\Services\Audit\AuditSinkSecrets;
use App\Services\Backups\SnapshotTags;
use App\Services\Http\Exceptions\ResponseTooLarge;
use App\Services\Http\Exceptions\UnsafeUrl;
use App\Services\Http\SafeHttpClient;
use App\Support\S3\SigV4;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * S3-compatible object storage (docs/09 R34): one JSON Lines object per
 * batch under `<prefix>launchroom-audit/<instance>/<yyyy>/<mm>/<dd>/
 * <first id>-<last id>.jsonl`, never overwritten (`If-None-Match: *`; a 412
 * means an earlier attempt already stored it). When the bucket has Object
 * Lock (detected by "Send test") and the sink keeps entries for N days,
 * every object carries a retention period in the chosen mode, with the
 * integrity checksum S3 requires for it (`x-amz-checksum-sha256`). Path-style requests, SigV4.
 */
final readonly class S3Transport implements AuditSinkTransport
{
    public const string FOLDER = 'launchroom-audit';

    public function __construct(
        private AuditSinkSecrets $secrets,
        private SafeHttpClient $http,
    ) {}

    public function deliver(AuditSink $sink, AuditBatch $batch): void
    {
        $body = implode("\n", array_map(AuditBatch::line(...), $batch->records))."\n";
        $at = $batch->firstEventAt->utc();
        $key = $this->prefix($sink).self::FOLDER.'/'.$batch->instance.'/'.$at->format('Y/m/d').'/'
            .sprintf('%012d-%012d', $batch->firstEventId, $batch->lastEventId).'.jsonl';

        $headers = ['content-type' => 'application/x-ndjson', 'if-none-match' => '*'];
        $days = $sink->configInt('retentionDays');
        $mode = $sink->configString('lockMode');

        if (($sink->config['objectLock'] ?? false) === true && $days !== null && $days > 0 && in_array($mode, ['GOVERNANCE', 'COMPLIANCE'], true)) {
            // The integrity checksum S3 requires with Object Lock (instead of Content-MD5).
            $headers['x-amz-checksum-sha256'] = base64_encode(hash('sha256', $body, true));
            $headers['x-amz-object-lock-mode'] = $mode;
            $headers['x-amz-object-lock-retain-until-date'] = CarbonImmutable::now()->utc()->addDays($days)->format('Y-m-d\TH:i:s\Z');
        }

        $response = $this->request($sink, 'PUT', $key, [], $body, $headers);

        // 412: this exact batch is already stored (an earlier attempt that timed out).
        if (! $response->successful() && $response->status() !== 412) {
            throw $this->failure($response);
        }
    }

    public function test(AuditSink $sink): string
    {
        $lock = $this->request($sink, 'GET', '', ['object-lock' => ''], '', []);
        $hasLock = $lock->successful() && preg_match('#<ObjectLockEnabled>\s*Enabled\s*</ObjectLockEnabled>#', $lock->body()) === 1;

        if (! $lock->successful() && in_array($lock->status(), [401, 403], true)) {
            throw $this->failure($lock);
        }

        $body = json_encode(['type' => 'test', 'instance' => SnapshotTags::instanceId(), 'sentAt' => CarbonImmutable::now()->toIso8601ZuluString()], JSON_THROW_ON_ERROR)."\n";
        $put = $this->request($sink, 'PUT', $this->prefix($sink).self::FOLDER.'/'.SnapshotTags::instanceId().'/connection-test.json', [], $body, ['content-type' => 'application/json']);

        if (! $put->successful()) {
            throw $this->failure($put);
        }

        $config = $sink->config;
        $config['objectLock'] = $hasLock;
        $sink->config = $config;
        $sink->save();

        return $hasLock
            ? 'Connected. The bucket has Object Lock, so stored entries can be locked.'
            : 'Connected. The bucket has no Object Lock: objects are append-only by name, but can be deleted with these keys.';
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers
     */
    private function request(AuditSink $sink, string $method, string $key, array $query, string $body, array $headers): Response
    {
        $endpoint = rtrim((string) $sink->configString('endpoint'), '/');
        $bucket = (string) $sink->configString('bucket');
        $parts = parse_url($endpoint);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || $bucket === '') {
            throw AuditDeliveryFailed::because('This bucket has no valid endpoint.');
        }

        $host = strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = '/'.$bucket.($key === '' ? '' : '/'.$key);
        $secret = $this->secrets->reveal($sink);
        $signed = SigV4::sign(
            $method, $host, $path, $query, $headers, hash('sha256', $body),
            $sink->configString('region') ?? 'us-east-1',
            $secret['accessKeyId'] ?? '', $secret['secretAccessKey'] ?? '', CarbonImmutable::now(),
        );

        $url = $parts['scheme'].'://'.$host.SigV4::canonicalPath($path).($query === [] ? '' : '?'.SigV4::canonicalQuery($query));
        $http = $sink->allow_private_network ? $this->http->withPrivateNetwork() : $this->http;

        try {
            /** @var 'GET'|'PUT' $method */
            return $http->sendRaw($method, $url, $body, $signed);
        } catch (UnsafeUrl) {
            throw AuditDeliveryFailed::because($sink->allow_private_network
                ? 'This endpoint is not allowed.'
                : 'This endpoint is not allowed: it must be https and reachable on the public internet.');
        } catch (ResponseTooLarge) {
            throw AuditDeliveryFailed::because('The bucket answered with an unexpectedly large response.');
        } catch (Throwable) {
            throw AuditDeliveryFailed::because('Could not reach '.strtolower($parts['host']).'.');
        }
    }

    private function failure(Response $response): AuditDeliveryFailed
    {
        if (preg_match('#<Code>([A-Za-z]{1,64})</Code>#', $response->body(), $m) === 1) {
            return AuditDeliveryFailed::because("The bucket answered {$response->status()} ({$m[1]}).");
        }

        return AuditDeliveryFailed::status($response->status());
    }

    private function prefix(AuditSink $sink): string
    {
        $prefix = trim((string) $sink->configString('prefix'), '/');

        return $prefix === '' ? '' : $prefix.'/';
    }
}
