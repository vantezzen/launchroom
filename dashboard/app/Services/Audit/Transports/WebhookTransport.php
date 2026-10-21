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
use App\Support\Alerts\WebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/**
 * HTTPS webhook (docs/09 R34): one JSON batch per POST, signed like alert
 * webhooks, `X-Launchroom-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret,
 * "<t>.<raw body>")>`. A 2xx answer is a delivery.
 */
final readonly class WebhookTransport implements AuditSinkTransport
{
    public function __construct(
        private AuditSinkSecrets $secrets,
        private SafeHttpClient $http,
    ) {}

    public function deliver(AuditSink $sink, AuditBatch $batch): void
    {
        $body = '{"type":"launchroom.audit.batch","version":1,"instance":'.json_encode($batch->instance)
            .',"sink":'.json_encode($sink->public_id)
            .',"batch":"'.$batch->firstEventId.'-'.$batch->lastEventId.'"'
            .',"records":['.implode(',', array_map(AuditBatch::line(...), $batch->records)).']}';

        $this->post($sink, $body, 'batch', "{$sink->public_id}:{$batch->firstEventId}-{$batch->lastEventId}");
    }

    public function test(AuditSink $sink): string
    {
        $body = json_encode([
            'type' => 'launchroom.audit.test',
            'version' => 1,
            'instance' => SnapshotTags::instanceId(),
            'sink' => $sink->public_id,
            'sentAt' => CarbonImmutable::now()->toIso8601ZuluString(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->post($sink, $body, 'test', (string) Str::uuid());

        return 'The test arrived.';
    }

    private function post(AuditSink $sink, string $body, string $event, string $delivery): void
    {
        $secret = $this->secrets->reveal($sink);
        $url = $secret['url'] ?? '';
        $signing = $secret['signingSecret'] ?? '';

        if ($url === '' || $signing === '') {
            // Fail closed: never send an unsigned batch.
            throw AuditDeliveryFailed::because('This webhook has no address or signing secret.');
        }

        $http = $sink->allow_private_network ? $this->http->withPrivateNetwork() : $this->http;

        try {
            $response = $http->postRaw($url, $body, 'application/json', [
                'X-Launchroom-Event' => 'audit.'.$event,
                'X-Launchroom-Delivery' => $delivery,
                'X-Launchroom-Signature' => WebhookSignature::header($signing, CarbonImmutable::now()->getTimestamp(), $body),
            ]);
        } catch (UnsafeUrl) {
            throw AuditDeliveryFailed::because($sink->allow_private_network
                ? 'This address is not allowed.'
                : 'This address is not allowed: it must be https and reachable on the public internet.');
        } catch (ResponseTooLarge) {
            throw AuditDeliveryFailed::because('The destination answered with an unexpectedly large response.');
        } catch (Throwable) {
            throw AuditDeliveryFailed::because('Could not reach '.$this->host($url).'.');
        }

        if (! $response->successful()) {
            throw AuditDeliveryFailed::status($response->status());
        }
    }

    private function host(#[SensitiveParameter] string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : 'the address';
    }
}
