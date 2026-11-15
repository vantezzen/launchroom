<?php

declare(strict_types=1);

namespace App\Services\Audit\Transports;

use App\Models\AuditSink;
use App\Models\InstanceSettings;
use App\Services\Audit\AuditBatch;
use App\Services\Audit\AuditDeliveryFailed;
use App\Services\Crypto\SealedColumns;
use App\Services\Http\Exceptions\WebhookPostFailed;
use App\Services\Http\SignedWebhookPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * HTTPS webhook (docs/09 R34): one JSON batch per POST, signed like alert
 * webhooks.
 */
final readonly class WebhookTransport implements AuditSinkTransport
{
    public function __construct(
        private SealedColumns $sealed,
        private SignedWebhookPoster $poster,
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
            'instance' => InstanceSettings::instanceId(),
            'sink' => $sink->public_id,
            'sentAt' => CarbonImmutable::now()->toIso8601ZuluString(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->post($sink, $body, 'test', (string) Str::uuid());

        return 'The test arrived.';
    }

    private function post(AuditSink $sink, string $body, string $event, string $delivery): void
    {
        $secret = $this->sealed->revealJson($sink, 'secret');
        $url = $secret['url'] ?? '';
        $signing = $secret['signingSecret'] ?? '';

        if ($url === '' || $signing === '') {
            // Fail closed: never send an unsigned batch.
            throw AuditDeliveryFailed::because('This webhook has no address or signing secret.');
        }

        try {
            $this->poster->post($url, $body, 'audit.'.$event, $delivery, $signing, $sink->allow_private_network);
        } catch (WebhookPostFailed $e) {
            throw AuditDeliveryFailed::from($e, $sink->allow_private_network);
        }
    }
}
